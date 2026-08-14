-- #748: a `distances` tábla normalizálása.
--
-- A cron minden templomot külön dolgoz fel `from`-ként (Distance::MupdateChurch),
-- ezért ugyanaz a pár előbb-utóbb MINDKÉT irányban bekerült a táblába (A->B és
-- B->A). A `Coord` unique kulcs a két tuple-t különbözőnek látja, tehát nem védett.
-- A szomszéd-lista mindkét irányban keres (#103), így ugyanaz a templom kétszer
-- jelent meg.
--
-- Ez a migráció egyszer fut le; utána a kód már csak kanonikus sorrendben ír
-- (a kisebb koordináta a `from`), tehát nem keletkezik új duplikátum.
--
-- FUTTATÁS (éles/deploy után, egyszer):
--   docker exec -i <mysql-konténer> mariadb -u root -p miserend \
--     < docker/mysql/migrations/748-distances-normalizalas.sql

-- 1) A tükrözött párokból a KISEBB távolságút tartjuk meg. Azonos távolságnál a
--    kisebb id-t. (Az útvonal-távolság irányonként eltérhet az egyirányú utcák
--    miatt; a szomszéd-listán eddig is a kisebb érték jelent meg.)
DELETE d FROM distances d
JOIN distances m
  ON  m.fromLat = d.toLat   AND m.fromLon = d.toLon
  AND m.toLat   = d.fromLat AND m.toLon   = d.fromLon
WHERE d.distance > m.distance
   OR (d.distance = m.distance AND d.id > m.id);

-- 2) A megmaradt sorokat kanonikus sorrendbe forgatjuk.
--
--    A `SET a = b, b = a` MySQL/MariaDB alatt NEM cserél: az értékadás balról
--    jobbra megy, tehát a második már az ÚJ értéket látná. Ezért egy származtatott
--    táblából (`s`) olvassuk az EREDETI értékeket.
--
--    Ütközés nem lehet: az 1. lépés után egy párhoz pontosan egy sor tartozik.
UPDATE distances d
JOIN (SELECT id, fromLat, fromLon, toLat, toLon FROM distances) s ON s.id = d.id
SET d.fromLat = s.toLat,   d.fromLon = s.toLon,
    d.toLat   = s.fromLat, d.toLon   = s.fromLon
WHERE s.fromLat > s.toLat
   OR (s.fromLat = s.toLat AND s.fromLon > s.toLon);

-- 3) Ellenőrzés: 0-t kell adnia.
SELECT COUNT(*) AS maradt_tukrozott_par
FROM distances d
JOIN distances m
  ON  m.fromLat = d.toLat   AND m.fromLon = d.toLon
  AND m.toLat   = d.fromLat AND m.toLon   = d.fromLon;
