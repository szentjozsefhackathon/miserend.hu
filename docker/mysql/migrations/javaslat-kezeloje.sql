-- Ki fogadta el a javaslatot? — az éles adatbázison kézzel kell lefuttatni.
--
-- A `cal_suggestion_packages` eddig CSAK az állapotot tárolta (PENDING/ACCEPTED/
-- REJECTED), azt nem, hogy KI és MIKOR döntött róla. Az adminfelületen ezért nem
-- lehetett látni a kezelő nevét: nem elveszett az adat, hanem sosem keletkezett.
--
-- !!! ELŐTTE BACKUP:  bash docker/mysql/dump.sh > backup-PRE-kezelo.sql
--    (A deploy amúgy is készít teljes mysqldumpot minden futásnál.)
--
-- Futtatás:
--    docker exec -i miserend-prod-mysql-1 mariadb -uuser -p"$MYSQL_PASSWORD" miserend \
--      < docker/mysql/migrations/javaslat-kezeloje.sql
--
-- Csak hozzáad, semmit nem ír felül: a meglévő javaslatoknál a két új oszlop NULL
-- marad (róluk tényleg nem tudjuk, ki döntött), az ezutániaknál kitöltődik.

USE miserend;

ALTER TABLE `cal_suggestion_packages`
    ADD COLUMN `handled_by_user_id` bigint(20) unsigned NULL DEFAULT NULL AFTER `state`,
    ADD COLUMN `handled_at` timestamp NULL DEFAULT NULL AFTER `handled_by_user_id`;
