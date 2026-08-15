/*
 * #663: a church_relationships.type oszlop eldobása.
 *
 * FONTOS, hogy ez a fájl a `05-data.sh` UTÁN fusson: a seed-dump
 * (data/church_relationships.sql) még beszúrja a `type` oszlopot, tehát a betöltés
 * pillanatában az oszlopnak MÉG léteznie kell. A 03-migrations.sql erre nem alkalmas,
 * mert az a seed ELŐTT fut — ott eldobva a seed "Unknown column 'type'"-pal elszáll,
 * és a MySQL konténer el sem indul.
 *
 * (A dump majd a következő újragenerálásakor veszíti el magától az oszlopot.)
 */

USE miserend;

-- Minden kapcsolat alárendeltség — az "alárendelt plébánia" (oldallagosan ellátva) és az
-- "alárendelt fília" között a megjelenítésben sincs különbség, tehát nincs mit tárolni.
-- A térkép mostantól egységes (lilás, folytonos) vonallal rajzol, popup nélkül.
--
-- ÉLES ADATBÁZISON KÉT LÉPÉSBEN, hogy ne legyen kieső ablak (nincs migrációs rendszerünk):
--   1) MERGE ELŐTT bármikor:  ALTER TABLE church_relationships MODIFY COLUMN `type`
--        enum('subordinate','associated','territorially_independent') NULL DEFAULT NULL;
--      Ezzel a RÉGI kód (ami még ír bele) és az ÚJ kód (ami már nem) is működik.
--   2) MERGE UTÁN bármikor:   ALTER TABLE church_relationships DROP COLUMN `type`;
ALTER TABLE `church_relationships`
    DROP COLUMN IF EXISTS `type`;
