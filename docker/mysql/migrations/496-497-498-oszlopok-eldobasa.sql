-- #496 / #497 / #498: a templomok.orszag, .megye és .varos oszlopok, valamint az
-- orszagok, megye és varosok táblák eldobása.
--
-- A helyet innentől kizárólag a koordináta és az OSM-határok adják.
--
-- ============================ ELŐFELTÉTELEK ============================
--
-- EZT A FÁJLT UTOLSÓKÉNT KELL FUTTATNI, és csak akkor, ha az alábbiak megvannak.
-- A művelet VISSZAFORDÍTHATATLAN — előtte mentés kell.
--
--   1. A kód már nem olvassa az oszlopokat (#797, #798 mergelve).
--   2. A koordináta nélküli templomok helyadata átkerült a megjegyzés mezőbe:
--        docker exec miserend php index.php q=cron cron_id=496
--      Ellenőrzés: keress a megjegyzésekben a "[Helyadat a koordináta nélküli
--      időszakból]" jelölőre — élesben 47 templomnál kell megjelennie.
--   3. A határ-lefedettség elfogadható. A /health kiírja, hány aktív, koordinátás
--      templomnak NINCS administratív határa. Ez a szám a drop után már nem
--      pótolható a régi oszlopból — ezek a templomok helynév nélkül maradnak.
--      Élesben a szlovák minta 23%-a volt érintett; a cron 497 havonta újrapróbálja.
--   4. Teljes templom-újraindexelés lefutott (cron_id=38), hogy a keresőindex a
--      származtatott neveket tartalmazza.
--
-- =======================================================================

ALTER TABLE `templomok`
  DROP COLUMN `orszag`,
  DROP COLUMN `megye`,
  DROP COLUMN `varos`;

DROP TABLE IF EXISTS `varosok`;
DROP TABLE IF EXISTS `megye`;
DROP TABLE IF EXISTS `orszagok`;
