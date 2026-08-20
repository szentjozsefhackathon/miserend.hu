-- #840: a `fromOSM` jelző jelentésének rendbetétele.
--
-- ÚJ DEFINÍCIÓ: fromOSM = 1  <=>  a kulcs az OSM-címke névtérbe tartozik.
-- A jelzőt a KULCS dönti el, soha nem az, hogy ki írta a sort utoljára.
--
-- MIÉRT KELL VISSZATÖLTÉS. A meglévő sorok a RÉGI jelentést hordozzák: a `fromOSM` addig
-- azt mondta meg, melyik író nyúlt hozzá utoljára. Mivel a #484 óta két írónk van, és az
-- `updateOrCreate` a (church_id, key) párra illeszt, a jelző oda-vissza billegett — a
-- `diet:gluten_free` sorok emiatt estek ki a /josm statisztikájából, pedig valódi
-- OSM-címkék. A kód javítása önmagában csak a KÖVETKEZŐ mentésnél vagy cron-futásnál
-- hatna; ez a migráció hozza egy szintre a meglévő adatot.
--
-- Idempotens: a WHERE-ek csak az eltérő sorokat mozgatják.

UPDATE `attributes`
   SET `fromOSM` = 0
 WHERE `key` IN ('communion:gluten_free:holidays', 'communion:gluten_free:weekdays')
   AND `fromOSM` <> 0;

UPDATE `attributes`
   SET `fromOSM` = 1
 WHERE `key` NOT IN ('communion:gluten_free:holidays', 'communion:gluten_free:weekdays')
   AND `fromOSM` <> 1;
