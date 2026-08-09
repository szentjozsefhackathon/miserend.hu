/*
 * #646: a seed-adat integritás-javításai.
 *
 * FONTOS a sorrend: ez a fájl a `05-data.sh` UTÁN fut (a docker-entrypoint névsorrendben
 * dolgozza fel az initdb.d tartalmát), tehát a már betöltött seed-adaton javít. A
 * `03-migrations.sql` erre nem alkalmas, mert az még a betöltés ELŐTT fut.
 *
 * A seed a dump fejlécében FOREIGN_KEY_CHECKS=0-val töltődik be, ezért az alábbi két
 * hiba be tudott csúszni annak ellenére, hogy a `fk_cal_masses_period_id` idegen kulcs
 * létezik.
 */

USE miserend;

/*
 * 1) period_id = 0  ->  NULL
 *
 * A 0 nem "törölt időszak", hanem eleve érvénytelen érték: a `cal_periods` id-tartománya
 * 1-től indul. Szemantikailag azt akarja jelenteni, hogy "nincs időszak", amire a NULL való.
 *
 * Miért veszélyes a 0: a kódban jellemzően `isset()` dönti el, van-e időszak — a 0-ra pedig
 * az IGAZ. Így a kód elindul lekérni a nem létező időszakot:
 *     CalPeriod::find(0) -> null -> "Call to a member function toArray() on null"
 * Ez döntötte le a misekeresés teljes találati oldalát (HTTP 500) a #636-ban.
 *
 * A seedben 70 ilyen sor van, 38 VALÓDI templomhoz — ezeket javítani kell, nem törölni.
 */
UPDATE `cal_masses` SET `period_id` = NULL WHERE `period_id` = 0;

/*
 * 2) Nem létező templomra hivatkozó misék törlése
 *
 * A seedben 17 ilyen sor van, a 5422-5435 church_id tartományban. Ezek teszt-maradékok:
 * a `templomok` tábla legnagyobb valódi id-ja 5419, az integrációs tesztek pedig 5420-tól
 * kaptak auto-increment id-t (lásd a ChurchRelationshipTest megjegyzését). A templomok
 * eltűntek, a miséik viszont belekerültek a dumpba.
 *
 * A `Church::find()` ezekre null-t ad (a `templomok` soft-delete-es, tehát még egy
 * soft-deleted templom is null-t adna), amitől szintén elszállt a találati lista.
 */
DELETE FROM `cal_masses`
 WHERE `church_id` NOT IN (SELECT `id` FROM `templomok`);
