-- #496 / #497 / #498: friss adatbázisnál is essenek ki a régi helyoszlopok.
--
-- Ez a fájl SZÁNDÉKOSAN az adatbetöltés (05-data.sh) UTÁN fut, nem a séma
-- definíciójában dobja el őket. Így a `data/templomok.sql` seed változatlan maradhat,
-- és a seed-fájlokat nem kell újragyártani ahhoz, hogy a fejlesztői adatbázis a
-- production utáni állapotot tükrözze.
--
-- Az éles migrációt lásd: docker/mysql/migrations/496-497-498-oszlopok-eldobasa.sql

USE `miserend`;

ALTER TABLE `templomok`
  DROP COLUMN IF EXISTS `orszag`,
  DROP COLUMN IF EXISTS `megye`,
  DROP COLUMN IF EXISTS `varos`;

DROP TABLE IF EXISTS `varosok`;
DROP TABLE IF EXISTS `megye`;
DROP TABLE IF EXISTS `orszagok`;
