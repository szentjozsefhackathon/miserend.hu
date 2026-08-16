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
--   2. (Ezt már ez a fájl maga elvégzi, lásd az 1. lépést.)
--   3. A határ-lefedettség elfogadható. A /health kiírja, hány aktív, koordinátás
--      templomnak NINCS administratív határa. Ez a szám a drop után már nem
--      pótolható a régi oszlopból — ezek a templomok helynév nélkül maradnak.
--      Élesben a szlovák minta 23%-a volt érintett; a cron 497 havonta újrapróbálja.
--   4. Teljes templom-újraindexelés lefutott (cron_id=38), hogy a keresőindex a
--      származtatott neveket tartalmazza.
--
-- =======================================================================

-- ============ 1. LÉPÉS: a koordináta nélküli templomok helyadatának mentése ============
--
-- Néhány misézőhelynek egyáltalán nincs koordinátája, tehát sosem lesz boundary-ja:
-- nekik ezek az oszlopok az EGYETLEN helymegjelölésük. Élesben 47 ilyen van.
-- borazslo a #496-ban két lehetőséget adott (törlés vagy a megjegyzés mezőbe); ez a
-- nem-destruktív ág.
--
-- SZÁNDÉKOSAN itt van, ugyanabban a fájlban, közvetlenül a DROP előtt. Külön cronként
-- indult, de az a ledobott oszlopokat olvasta volna — így viszont a két lépés atomi,
-- és nem lehet rossz sorrendben futtatni.
--
-- Idempotens: a jelölőre szűr, tehát kétszer futtatva sem duplikál.
-- Az azonosítókat NEVEKRE oldjuk fel: egy megjegyzésben az "orszag=25" semmit nem mond.

UPDATE `templomok` t
  LEFT JOIN `orszagok` o ON o.id = t.orszag
  LEFT JOIN `megye` m ON m.id = t.megye
SET t.megjegyzes = TRIM(CONCAT(
      IF(COALESCE(t.megjegyzes, '') = '', '', CONCAT(t.megjegyzes, '\n\n')),
      '[Helyadat a koordináta nélküli időszakból] ',
      CONCAT_WS(', ',
        NULLIF(COALESCE(o.nev, ''), ''),
        IF(COALESCE(m.megyenev, '') = '', NULL, CONCAT(m.megyenev, ' megye')),
        NULLIF(TRIM(COALESCE(t.varos, '')), '')
      )
    ))
WHERE (t.lat IS NULL OR t.lat = 0)
  AND COALESCE(t.megjegyzes, '') NOT LIKE '%[Helyadat a koordináta nélküli időszakból]%'
  AND (COALESCE(o.nev, '') <> '' OR COALESCE(m.megyenev, '') <> '' OR TRIM(COALESCE(t.varos, '')) <> '');

-- ============ 2. LÉPÉS: az oszlopok és a táblák eldobása ============

ALTER TABLE `templomok`
  DROP COLUMN `orszag`,
  DROP COLUMN `megye`,
  DROP COLUMN `varos`;

DROP TABLE IF EXISTS `varosok`;
DROP TABLE IF EXISTS `megye`;
DROP TABLE IF EXISTS `orszagok`;
