-- #874: (church_id, key) EGYEDI az attributes táblán.
--
-- Az `updateOrCreate((church_id, key), …)` mindenhol úgy viselkedik, MINTHA a pár egyedi
-- lenne — de eddig semmi nem garantálta. A `key_church` index létezett, csak nem UNIQUE.
-- Két párhuzamos írás (az éjszakai OSM-szinkron és egy /edit mentés) tehát két sort tudott
-- létrehozni ugyanarra a párra; onnantól az `updateOrCreate` találomra az egyiket
-- frissíti, a /josm statisztikája pedig duplán számol.
--
-- borazslo az éles adatbázison ellenőrizte: nincs duplikátum, tehát nincs adat, ami
-- sérülne. A dev adatbázisban ugyanez (0 duplikált pár).
--
-- FUTTATÁS ELŐTT érdemes újra megnézni — ha időközben keletkezett duplikátum, az ALTER
-- elhasal, és NEM töröl semmit csendben:
--
--   SELECT church_id, `key`, COUNT(*) db
--     FROM attributes GROUP BY church_id, `key` HAVING db > 1;
--
-- A meglévő, nem egyedi indexet CSERÉLJÜK, nem melléteszünk egy újat: ugyanaz az
-- oszloppár, csak megszorítással — két azonos index fölösleges írásköltség lenne.
--
-- Az oszlopsorrend SZÁNDÉKOSAN (church_id, key), nem fordítva: a lekérdezéseink
-- templomonként szűrnek (l. `churchesinbbox`, `josm`, `Church::names`), tehát a
-- `church_id` az első oszlop, ami így önmagában is használható előtagként.

ALTER TABLE `attributes`
    DROP INDEX IF EXISTS `key_church`;

ALTER TABLE `attributes`
    ADD UNIQUE KEY IF NOT EXISTS `church_key` (`church_id`, `key`);
