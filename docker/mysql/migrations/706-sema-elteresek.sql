-- #706: a /health séma-ellenőrzése két eltérést jelez az éles adatbázison.
-- Mindkettő az évek során felgyűlt maradék, nem mostani hiba.
--
-- !!! ELŐTTE BACKUP:  bash docker/mysql/dump.sh > backup-PRE-706.sql
--    (A deploy amúgy is készít teljes mysqldumpot minden futásnál.)
--
-- Futtatás:
--    docker exec -i miserend-prod-mysql-1 mariadb -uuser -p"$MYSQL_PASSWORD" miserend \
--      < docker/mysql/migrations/706-sema-elteresek.sql

USE miserend;

-- ============================================================================
-- 1) `misek` — a régi, kivezetett miserend-tábla eldobása
-- ============================================================================
--
-- A táblát borazslo maga vezette ki: 3ade10be "end of old misek" (2026-01-27),
-- ami törölte a sémából ÉS a seedből is. Az éles adatbázisból viszont soha nem
-- lett eldobva, ezért látszik azóta is a /health-en.
--
-- Ellenőriztem, hogy tényleg halott: a PHP-kódban EGYETLEN MySQL-hivatkozás
-- sincs rá. Amit a keresés talál, az mind más:
--   * `$misek`      — PHP-változó (eloquent/church.php, api/sqlite.php)
--   * `'misek'`     — API-válaszkulcs (api/church.php, api/nearby.php, ...)
--   * `[misek]`     — a mobilapp SQLITE-exportjának táblája (api/sqlite.php),
--                     külön kapcsolaton, a MySQL-hez semmi köze
--
-- A miserendet a `cal_masses` / `cal_periods` hordozza 2026 eleje óta.
--
-- Óvatosságból előbb mentsük külön ezt az egy táblát — a fenti teljes backup
-- mellett is olcsó, és így visszatölthető marad anélkül, hogy az egészet
-- vissza kellene állítani:
--
--    docker exec miserend-prod-mysql-1 mariadb-dump -uuser -p"$MYSQL_PASSWORD" \
--      miserend misek | gzip > misek-2026-08.sql.gz
--
-- Ha ez megvan, jöhet:

DROP TABLE IF EXISTS `misek`;


-- ============================================================================
-- 2) `cal_periods`.`multi_day` — hiányzó alapérték pótlása
-- ============================================================================
--
-- A séma (02-schema.sql:126) `NOT NULL DEFAULT 0`, élesen viszont nincs
-- alapértéke. Gyakorlati következménye ma NINCS: a `cal_periods` seedelt
-- referencia-tábla (40 sor), futásidőben SENKI nem szúr bele sort — csak a
-- `cal_period_years` és a `cal_generated_periods` íródik. Ezért is jelzi a
-- /health "megjegyzés" (nem hiba) szinten.
--
-- Mégis érdemes rendbe tenni: a séma-ellenőrzés akkor ér valamit, ha csendben
-- van, amíg tényleg minden rendben. Egy állandó, ismert eltérés elfedi a
-- következő, valódit.

ALTER TABLE `cal_periods`
    MODIFY COLUMN `multi_day` tinyint(1) NOT NULL DEFAULT 0;
