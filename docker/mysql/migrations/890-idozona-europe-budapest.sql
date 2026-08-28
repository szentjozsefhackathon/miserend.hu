-- #890: a session-időzóna `+05:00`-ról `Europe/Budapest`-re áll — a történelmi,
-- PHP által írt TIMESTAMP oszlopok egyszeri helyretolása.
--
--
-- !!! ELŐTTE BACKUP:  bash docker/mysql/dump.sh > backup-PRE-890.sql
--
-- Futtatás (az alkalmazás ÁLLJON, l. docs/deploy-lepesek.md):
--    docker exec -i miserend-prod-mysql-1 mariadb -uuser -p"$MYSQL_PASSWORD" miserend \
--      < docker/mysql/migrations/890-idozona-europe-budapest.sql
--
--
-- MIÉRT KELL. A PHP `date('Y-m-d H:i:s')`-szel budapesti FALIÓRA-időt ír, a
-- munkamenet viszont `+05:00`-ban értelmezi, tehát a TÁROLT pillanat 5 órával a
-- falióra mögé kerül a helyes 1-2 óra helyett. Amíg ugyanaz a rossz zóna olvassa
-- vissza, ez nem látszik — de a `webapp/functions.php` javításával az olvasás
-- helyreáll, és a régi sorok 3 (nyár) / 4 (tél) órával korábbra ugranának.
-- Ez a migráció ezt a 3-4 órát adja vissza a TÁROLT pillanatnak.
--
-- A KÉPLET. Kizárólag `+05:00`-s munkamenetben érvényes — a fájl ezért maga
-- állítja be, nem bízza a kliensre:
--     CONVERT_TZ(col,'Europe/Budapest','+05:00')
-- Az eltolás NEM konstans: a tárolt pillanatra érvényes budapesti eltolás dönti
-- el, tehát az óraátállítás körüli sorokra is helyes. Egy `INTERVAL 3 HOUR`
-- HIBÁS volna.
--
-- MIT NEM ÉRINT.
--   * DATETIME oszlopok — a munkamenet-zóna nem konvertálja őket, tehát a PHP
--     által írtak (`chat.datum`, `user.regdatum`, `user.lastactive`) MA IS
--     helyesek. A MySQL órájával írtak (`templomok.moddatum`, és a vegyes
--     `user.lastlogin` / `remarks.admindatum` egy része) 3-4 órával a jövőben
--     állnak, de egyiket sem olvassa órához mérő kód, és a javítás után az új
--     sorok maguktól jók lesznek. Nem nyúlunk hozzájuk — l. a jegy indoklását.
--   * `church_update_tokens.created_at` és `updates.timestamp` — ezeket a MySQL
--     `CURRENT_TIMESTAMP` alapértéke tölti, tehát a TÁROLT pillanatuk MA IS
--     helyes, és a javítástól maguktól jóra fordulnak. Eltolni őket rontás volna.
--   * `cal_masses.start_date` — varchar, zóna nélküli falióra, ismétlődő
--     eseményekkel. A munkamenet-zóna nem ér hozzá.
--   * 2016-01-01 ELŐTTI sorok — a `SET time_zone='+05:00'` a 8bd8e2e9 commit-tal
--     került be (2016-01-01). Ami régebbi, az nem ezen a hibán ment keresztül.
--   * `'0000-00-00 00:00:00'` — a `CONVERT_TZ` NULL-t adna rá. NULL-ozható
--     oszlopon ez néma adatvesztés; NOT NULL TIMESTAMP oszlopon a MariaDB NÉMÁN
--     a futásidejű CURRENT_TIMESTAMP-re cseréli (mérve, warning nélkül). Az alsó
--     határ egyszerre zárja ki a nulla-dátumot és a hiba előtti kort.

USE `miserend`;

/*
 * 1. ŐR — a nevesített időzóna elérhetősége.
 *
 * Ha a `mysql.time_zone_*` tábla hiányzik vagy csonka, a `CONVERT_TZ` NEM hibázik,
 * hanem NULL-t ad — a migráció ilyenkor csendben kinullázná az oszlopokat. Ezért
 * a fájl elején ismert bemenetre ismert kimenetet követelünk: ha nem stimmel, a
 * NOT NULL oszlopba írt NULL ERROR 1048-cal MEGÁLLÍTJA a futást, és mivel a
 * kliens `--force` nélkül fut, a lenti UPDATE-ek el sem indulnak.
 *
 * Ha itt elszáll:  mariadb-tzinfo-to-sql /usr/share/zoneinfo | mariadb -u root -p mysql
 */
DROP TEMPORARY TABLE IF EXISTS `_tz890_or`;
CREATE TEMPORARY TABLE `_tz890_or` (
  `LEALLAS_A_MYSQL_TIME_ZONE_TABLA_HIANYZIK_VAGY_ROSSZ` TINYINT NOT NULL
) ENGINE=InnoDB;

INSERT INTO `_tz890_or` VALUES (NULLIF(
      CONVERT_TZ('2026-07-15 10:00:00','Europe/Budapest','+05:00') = '2026-07-15 13:00:00'
  AND CONVERT_TZ('2026-01-15 10:00:00','Europe/Budapest','+05:00') = '2026-01-15 14:00:00'
, 0));

/*
 * 2. NAPLÓ — az idempotencia egyetlen forrása.
 *
 * A képlet ÉRTÉK alapján nem idempotens: az eltolt időbélyeg ugyanolyan hihető,
 * mint az eredeti, tehát egy második futás némán további 3-4 órát csúsztatna.
 * Ezért oszloponként jelöljük, hogy megtörtént, és az UPDATE-et ehhez a jelöléshez
 * kötjük. A DDL implicit commitot okoz, ezért a tábla a tranzakció ELŐTT készül.
 *
 * A `futott_epoch` SZÁNDÉKOSAN BIGINT, nem TIMESTAMP: a napló ne essen annak a
 * hibának az áldozatává, amit javít.
 */
CREATE TABLE IF NOT EXISTS `tz_migracio_890` (
  `tabla`        varchar(64) NOT NULL,
  `oszlop`       varchar(64) NOT NULL,
  `futott_epoch` bigint(20)  NOT NULL,
  `erintett_sor` int(11)     NOT NULL DEFAULT 0,
  PRIMARY KEY (`tabla`,`oszlop`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/*
 * 3. A KÉPLET MUNKAMENETE.
 *
 * `+05:00` KELL: az UPDATE bal oldala is a munkamenet-zónán megy át, ezért a
 * kifejezés csak ebben a zónában adja a helyes eredményt. A `mariadb` kliens
 * alapból a szerver zónáján (itt SYSTEM=UTC) indul — nem szabad rábízni.
 */
SET time_zone = '+05:00';
SET @KEZDET = '2016-01-01 00:00:00';
SET @VEGE   = '2038-01-01 00:00:00';

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 4. OSZLOPONKÉNTI HELYRETOLÁS
--
-- Táblánként EGY utasítás, benne a tábla MINDEN migrálandó dátumoszlopa. Ez nem
-- kényelmi kérdés: a `confessions.timestamp`, a `church_relationships.updated_at`
-- és az `external_calendars.updated_at` `ON UPDATE current_timestamp()`-es, tehát
-- ha nem szerepelnének explicit módon a SET-listában, a futásidőre ugranának.
--
-- A határőr az ÉRTÉKADÁSBAN van, nem a WHERE-ben: így egy NULL vagy nulla-dátumú
-- oszlop nem zárja ki a sor többi oszlopát a javításból.
-- ---------------------------------------------------------------------------

-- photos
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES
  ('photos','created_at',UNIX_TIMESTAMP()),('photos','updated_at',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `photos` SET
  `created_at` = IF(`created_at` >= @KEZDET AND `created_at` < @VEGE, CONVERT_TZ(`created_at`,'Europe/Budapest','+05:00'), `created_at`),
  `updated_at` = IF(`updated_at` >= @KEZDET AND `updated_at` < @VEGE, CONVERT_TZ(`updated_at`,'Europe/Budapest','+05:00'), `updated_at`)
WHERE @go = 2;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='photos' AND @go = 2;

-- templomok  (a `moddatum` DATETIME — szándékosan kimarad, l. a fejlécet)
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES
  ('templomok','created_at',UNIX_TIMESTAMP()),('templomok','updated_at',UNIX_TIMESTAMP()),
  ('templomok','deleted_at',UNIX_TIMESTAMP()),('templomok','boundaries_checked_at',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `templomok` SET
  `created_at`            = IF(`created_at` >= @KEZDET AND `created_at` < @VEGE, CONVERT_TZ(`created_at`,'Europe/Budapest','+05:00'), `created_at`),
  `updated_at`            = IF(`updated_at` >= @KEZDET AND `updated_at` < @VEGE, CONVERT_TZ(`updated_at`,'Europe/Budapest','+05:00'), `updated_at`),
  `deleted_at`            = IF(`deleted_at` >= @KEZDET AND `deleted_at` < @VEGE, CONVERT_TZ(`deleted_at`,'Europe/Budapest','+05:00'), `deleted_at`),
  `boundaries_checked_at` = IF(`boundaries_checked_at` >= @KEZDET AND `boundaries_checked_at` < @VEGE, CONVERT_TZ(`boundaries_checked_at`,'Europe/Budapest','+05:00'), `boundaries_checked_at`)
WHERE @go = 4;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='templomok' AND @go = 4;

-- keyword_shortcuts
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES
  ('keyword_shortcuts','created_at',UNIX_TIMESTAMP()),('keyword_shortcuts','updated_at',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `keyword_shortcuts` SET
  `created_at` = IF(`created_at` >= @KEZDET AND `created_at` < @VEGE, CONVERT_TZ(`created_at`,'Europe/Budapest','+05:00'), `created_at`),
  `updated_at` = IF(`updated_at` >= @KEZDET AND `updated_at` < @VEGE, CONVERT_TZ(`updated_at`,'Europe/Budapest','+05:00'), `updated_at`)
WHERE @go = 2;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='keyword_shortcuts' AND @go = 2;

-- church_links
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES
  ('church_links','created_at',UNIX_TIMESTAMP()),('church_links','updated_at',UNIX_TIMESTAMP()),
  ('church_links','deleted_at',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `church_links` SET
  `created_at` = IF(`created_at` >= @KEZDET AND `created_at` < @VEGE, CONVERT_TZ(`created_at`,'Europe/Budapest','+05:00'), `created_at`),
  `updated_at` = IF(`updated_at` >= @KEZDET AND `updated_at` < @VEGE, CONVERT_TZ(`updated_at`,'Europe/Budapest','+05:00'), `updated_at`),
  `deleted_at` = IF(`deleted_at` >= @KEZDET AND `deleted_at` < @VEGE, CONVERT_TZ(`deleted_at`,'Europe/Budapest','+05:00'), `deleted_at`)
WHERE @go = 3;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='church_links' AND @go = 3;

-- church_holders
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES
  ('church_holders','created_at',UNIX_TIMESTAMP()),('church_holders','updated_at',UNIX_TIMESTAMP()),
  ('church_holders','deleted_at',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `church_holders` SET
  `created_at` = IF(`created_at` >= @KEZDET AND `created_at` < @VEGE, CONVERT_TZ(`created_at`,'Europe/Budapest','+05:00'), `created_at`),
  `updated_at` = IF(`updated_at` >= @KEZDET AND `updated_at` < @VEGE, CONVERT_TZ(`updated_at`,'Europe/Budapest','+05:00'), `updated_at`),
  `deleted_at` = IF(`deleted_at` >= @KEZDET AND `deleted_at` < @VEGE, CONVERT_TZ(`deleted_at`,'Europe/Budapest','+05:00'), `deleted_at`)
WHERE @go = 3;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='church_holders' AND @go = 3;

-- church_relationships  (updated_at ON UPDATE — ezért van benne explicit módon)
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES
  ('church_relationships','created_at',UNIX_TIMESTAMP()),('church_relationships','updated_at',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `church_relationships` SET
  `created_at` = IF(`created_at` >= @KEZDET AND `created_at` < @VEGE, CONVERT_TZ(`created_at`,'Europe/Budapest','+05:00'), `created_at`),
  `updated_at` = IF(`updated_at` >= @KEZDET AND `updated_at` < @VEGE, CONVERT_TZ(`updated_at`,'Europe/Budapest','+05:00'), `updated_at`)
WHERE @go = 2;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='church_relationships' AND @go = 2;

-- confessions  (a `timestamp` maga ON UPDATE — az explicit értékadás elnyomja)
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES
  ('confessions','timestamp',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `confessions` SET
  `timestamp` = IF(`timestamp` >= @KEZDET AND `timestamp` < @VEGE, CONVERT_TZ(`timestamp`,'Europe/Budapest','+05:00'), `timestamp`)
WHERE @go = 1;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='confessions' AND @go = 1;

-- external_calendars  (updated_at ON UPDATE — ezért van benne explicit módon)
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES
  ('external_calendars','created_at',UNIX_TIMESTAMP()),('external_calendars','updated_at',UNIX_TIMESTAMP()),
  ('external_calendars','last_import_at',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `external_calendars` SET
  `created_at`     = IF(`created_at` >= @KEZDET AND `created_at` < @VEGE, CONVERT_TZ(`created_at`,'Europe/Budapest','+05:00'), `created_at`),
  `updated_at`     = IF(`updated_at` >= @KEZDET AND `updated_at` < @VEGE, CONVERT_TZ(`updated_at`,'Europe/Budapest','+05:00'), `updated_at`),
  `last_import_at` = IF(`last_import_at` >= @KEZDET AND `last_import_at` < @VEGE, CONVERT_TZ(`last_import_at`,'Europe/Budapest','+05:00'), `last_import_at`)
WHERE @go = 3;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='external_calendars' AND @go = 3;

-- tokens  (a `timeout` a munkamenet-lejárat — enélkül a javítás kiléptet mindenkit)
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES
  ('tokens','timeout',UNIX_TIMESTAMP()),('tokens','created_at',UNIX_TIMESTAMP()),('tokens','updated_at',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `tokens` SET
  `timeout`    = IF(`timeout` >= @KEZDET AND `timeout` < @VEGE, CONVERT_TZ(`timeout`,'Europe/Budapest','+05:00'), `timeout`),
  `created_at` = IF(`created_at` >= @KEZDET AND `created_at` < @VEGE, CONVERT_TZ(`created_at`,'Europe/Budapest','+05:00'), `created_at`),
  `updated_at` = IF(`updated_at` >= @KEZDET AND `updated_at` < @VEGE, CONVERT_TZ(`updated_at`,'Europe/Budapest','+05:00'), `updated_at`)
WHERE @go = 3;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='tokens' AND @go = 3;

-- crons  (a `deadline_at` az ütemező horgonya — enélkül minden munka egyszerre esedékes)
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES
  ('crons','deadline_at',UNIX_TIMESTAMP()),('crons','lastsuccess_at',UNIX_TIMESTAMP()),
  ('crons','created_at',UNIX_TIMESTAMP()),('crons','updated_At',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `crons` SET
  `deadline_at`    = IF(`deadline_at` >= @KEZDET AND `deadline_at` < @VEGE, CONVERT_TZ(`deadline_at`,'Europe/Budapest','+05:00'), `deadline_at`),
  `lastsuccess_at` = IF(`lastsuccess_at` >= @KEZDET AND `lastsuccess_at` < @VEGE, CONVERT_TZ(`lastsuccess_at`,'Europe/Budapest','+05:00'), `lastsuccess_at`),
  `created_at`     = IF(`created_at` >= @KEZDET AND `created_at` < @VEGE, CONVERT_TZ(`created_at`,'Europe/Budapest','+05:00'), `created_at`),
  `updated_At`     = IF(`updated_At` >= @KEZDET AND `updated_At` < @VEGE, CONVERT_TZ(`updated_At`,'Europe/Budapest','+05:00'), `updated_At`)
WHERE @go = 4;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='crons' AND @go = 4;

-- emails  (a `created_at`/`updated_at` a beragadt-küldés detektorának bemenete)
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES
  ('emails','created_at',UNIX_TIMESTAMP()),('emails','updated_at',UNIX_TIMESTAMP()),('emails','failed_at',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `emails` SET
  `created_at` = IF(`created_at` >= @KEZDET AND `created_at` < @VEGE, CONVERT_TZ(`created_at`,'Europe/Budapest','+05:00'), `created_at`),
  `updated_at` = IF(`updated_at` >= @KEZDET AND `updated_at` < @VEGE, CONVERT_TZ(`updated_at`,'Europe/Budapest','+05:00'), `updated_at`),
  `failed_at`  = IF(`failed_at`  >= @KEZDET AND `failed_at`  < @VEGE, CONVERT_TZ(`failed_at`,'Europe/Budapest','+05:00'),  `failed_at`)
WHERE @go = 3;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='emails' AND @go = 3;

-- messages
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES ('messages','timestamp',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `messages` SET
  `timestamp` = IF(`timestamp` >= @KEZDET AND `timestamp` < @VEGE, CONVERT_TZ(`timestamp`,'Europe/Budapest','+05:00'), `timestamp`)
WHERE @go = 1;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='messages' AND @go = 1;

-- remarks  (az `admindatum` DATETIME — szándékosan kimarad)
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES
  ('remarks','created_at',UNIX_TIMESTAMP()),('remarks','updated_at',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `remarks` SET
  `created_at` = IF(`created_at` >= @KEZDET AND `created_at` < @VEGE, CONVERT_TZ(`created_at`,'Europe/Budapest','+05:00'), `created_at`),
  `updated_at` = IF(`updated_at` >= @KEZDET AND `updated_at` < @VEGE, CONVERT_TZ(`updated_at`,'Europe/Budapest','+05:00'), `updated_at`)
WHERE @go = 2;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='remarks' AND @go = 2;

-- church_update_tokens  (a `created_at` MySQL-írt — szándékosan kimarad)
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES
  ('church_update_tokens','expires_at',UNIX_TIMESTAMP()),('church_update_tokens','used_at',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `church_update_tokens` SET
  `expires_at` = IF(`expires_at` >= @KEZDET AND `expires_at` < @VEGE, CONVERT_TZ(`expires_at`,'Europe/Budapest','+05:00'), `expires_at`),
  `used_at`    = IF(`used_at`    >= @KEZDET AND `used_at`    < @VEGE, CONVERT_TZ(`used_at`,'Europe/Budapest','+05:00'),    `used_at`)
WHERE @go = 2;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='church_update_tokens' AND @go = 2;

-- cal_suggestions
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES
  ('cal_suggestions','created_at',UNIX_TIMESTAMP()),('cal_suggestions','updated_at',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `cal_suggestions` SET
  `created_at` = IF(`created_at` >= @KEZDET AND `created_at` < @VEGE, CONVERT_TZ(`created_at`,'Europe/Budapest','+05:00'), `created_at`),
  `updated_at` = IF(`updated_at` >= @KEZDET AND `updated_at` < @VEGE, CONVERT_TZ(`updated_at`,'Europe/Budapest','+05:00'), `updated_at`)
WHERE @go = 2;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='cal_suggestions' AND @go = 2;

-- cal_suggestion_packages
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES
  ('cal_suggestion_packages','created_at',UNIX_TIMESTAMP()),('cal_suggestion_packages','updated_at',UNIX_TIMESTAMP()),
  ('cal_suggestion_packages','handled_at',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `cal_suggestion_packages` SET
  `created_at` = IF(`created_at` >= @KEZDET AND `created_at` < @VEGE, CONVERT_TZ(`created_at`,'Europe/Budapest','+05:00'), `created_at`),
  `updated_at` = IF(`updated_at` >= @KEZDET AND `updated_at` < @VEGE, CONVERT_TZ(`updated_at`,'Europe/Budapest','+05:00'), `updated_at`),
  `handled_at` = IF(`handled_at` >= @KEZDET AND `handled_at` < @VEGE, CONVERT_TZ(`handled_at`,'Europe/Budapest','+05:00'), `handled_at`)
WHERE @go = 3;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='cal_suggestion_packages' AND @go = 3;

-- notification_digest_items
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES
  ('notification_digest_items','created_at',UNIX_TIMESTAMP()),('notification_digest_items','sent_at',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `notification_digest_items` SET
  `created_at` = IF(`created_at` >= @KEZDET AND `created_at` < @VEGE, CONVERT_TZ(`created_at`,'Europe/Budapest','+05:00'), `created_at`),
  `sent_at`    = IF(`sent_at`    >= @KEZDET AND `sent_at`    < @VEGE, CONVERT_TZ(`sent_at`,'Europe/Budapest','+05:00'),    `sent_at`)
WHERE @go = 2;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='notification_digest_items' AND @go = 2;

-- distances
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES
  ('distances','created_at',UNIX_TIMESTAMP()),('distances','updated_at',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `distances` SET
  `created_at` = IF(`created_at` >= @KEZDET AND `created_at` < @VEGE, CONVERT_TZ(`created_at`,'Europe/Budapest','+05:00'), `created_at`),
  `updated_at` = IF(`updated_at` >= @KEZDET AND `updated_at` < @VEGE, CONVERT_TZ(`updated_at`,'Europe/Budapest','+05:00'), `updated_at`)
WHERE @go = 2;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='distances' AND @go = 2;

-- favorites
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES
  ('favorites','created_at',UNIX_TIMESTAMP()),('favorites','updated_at',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `favorites` SET
  `created_at` = IF(`created_at` >= @KEZDET AND `created_at` < @VEGE, CONVERT_TZ(`created_at`,'Europe/Budapest','+05:00'), `created_at`),
  `updated_at` = IF(`updated_at` >= @KEZDET AND `updated_at` < @VEGE, CONVERT_TZ(`updated_at`,'Europe/Budapest','+05:00'), `updated_at`)
WHERE @go = 2;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='favorites' AND @go = 2;

-- osm
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES
  ('osm','created_at',UNIX_TIMESTAMP()),('osm','updated_at',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `osm` SET
  `created_at` = IF(`created_at` >= @KEZDET AND `created_at` < @VEGE, CONVERT_TZ(`created_at`,'Europe/Budapest','+05:00'), `created_at`),
  `updated_at` = IF(`updated_at` >= @KEZDET AND `updated_at` < @VEGE, CONVERT_TZ(`updated_at`,'Europe/Budapest','+05:00'), `updated_at`)
WHERE @go = 2;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='osm' AND @go = 2;

-- lookup_boundary_church
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES
  ('lookup_boundary_church','created_at',UNIX_TIMESTAMP()),('lookup_boundary_church','updated_at',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `lookup_boundary_church` SET
  `created_at` = IF(`created_at` >= @KEZDET AND `created_at` < @VEGE, CONVERT_TZ(`created_at`,'Europe/Budapest','+05:00'), `created_at`),
  `updated_at` = IF(`updated_at` >= @KEZDET AND `updated_at` < @VEGE, CONVERT_TZ(`updated_at`,'Europe/Budapest','+05:00'), `updated_at`)
WHERE @go = 2;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='lookup_boundary_church' AND @go = 2;

-- lookup_church_osm
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES
  ('lookup_church_osm','created_at',UNIX_TIMESTAMP()),('lookup_church_osm','updated_at',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `lookup_church_osm` SET
  `created_at` = IF(`created_at` >= @KEZDET AND `created_at` < @VEGE, CONVERT_TZ(`created_at`,'Europe/Budapest','+05:00'), `created_at`),
  `updated_at` = IF(`updated_at` >= @KEZDET AND `updated_at` < @VEGE, CONVERT_TZ(`updated_at`,'Europe/Budapest','+05:00'), `updated_at`)
WHERE @go = 2;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='lookup_church_osm' AND @go = 2;

-- lookup_osm_enclosed
INSERT IGNORE INTO `tz_migracio_890` (tabla,oszlop,futott_epoch) VALUES
  ('lookup_osm_enclosed','updated_at',UNIX_TIMESTAMP());
SET @go := ROW_COUNT();
UPDATE `lookup_osm_enclosed` SET
  `updated_at` = IF(`updated_at` >= @KEZDET AND `updated_at` < @VEGE, CONVERT_TZ(`updated_at`,'Europe/Budapest','+05:00'), `updated_at`)
WHERE @go = 1;
SET @n := ROW_COUNT();
UPDATE `tz_migracio_890` SET `erintett_sor` = @n WHERE `tabla`='lookup_osm_enclosed' AND @go = 1;

COMMIT;

DROP TEMPORARY TABLE IF EXISTS `_tz890_or`;

-- ---------------------------------------------------------------------------
-- 5. UTÓELLENŐRZÉS — az alkalmazás MÉG ÁLLJON, amíg ez lefut.
-- ---------------------------------------------------------------------------
SELECT `tabla`, `oszlop`, `erintett_sor`, FROM_UNIXTIME(`futott_epoch`) AS futott
  FROM `tz_migracio_890` ORDER BY `tabla`, `oszlop`;
