/*
 * #498: az országkód tárolása a határon.
 *
 * Eddig az „ország -> kód" leképezés KIZÁRÓLAG a régi `templomok.orszag` oszlopon
 * keresztül létezett (az `orszagok` táblában nincs ISO-kód, csak `telkod`). Ezért
 * az oszlop kivezetése magával vitte volna a statisztikát (`stat.php`: orszag=12)
 * és az Angular naptárnak átadott országkódot is.
 *
 * Az OSM országrelációi viszont hordozzák, ellenőrizve:
 *   rel/21335 Magyarország  ISO3166-1=HU
 *   rel/90689 România       ISO3166-1=RO
 *   rel/14296 Slovensko     ISO3166-1=SK
 *
 * FONTOS, hogy ez a fájl a `05-data.sh` UTÁN fusson, és hogy az oszlop NE kerüljön
 * bele a `02-schema.sql`-be — ugyanaz a csapda, amit a 06-os fájl is leír. A seed
 * dump (`data/boundaries.sql`) oszloplista NÉLKÜLI `INSERT INTO boundaries VALUES`
 * alakot használ, tehát a betöltés pillanatában a táblának PONTOSAN a dumpban lévő
 * oszlopszámmal kell rendelkeznie. Ha az oszlopot a sémába tesszük, a seed
 * "Column count doesn't match value count"-tal elszáll, és a MySQL konténer el sem
 * indul. (Kimértem: pontosan ez történt.)
 *
 * ÉLES ADATBÁZISON KÉZZEL, mert nincs migrációs rendszerünk:
 *   ALTER TABLE boundaries ADD COLUMN IF NOT EXISTS `iso3166_1` varchar(2) NULL DEFAULT NULL;
 *   ALTER TABLE boundaries ADD INDEX IF NOT EXISTS `index3` (`iso3166_1`);
 * Az oszlop a következő boundary-szinkronnál töltődik fel magától; addig NULL,
 * amit a Church::countryCode() és minden hívó kezel. Adatvesztés nincs.
 */

USE miserend;

ALTER TABLE `boundaries`
    ADD COLUMN IF NOT EXISTS `iso3166_1` varchar(2) NULL DEFAULT NULL AFTER `denomination`;

ALTER TABLE `boundaries`
    ADD INDEX IF NOT EXISTS `index3` (`iso3166_1`);
