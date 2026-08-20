-- #866: a vízszivárgás-jelzés NE gyóntatásnak látsszon.
--
-- A LoRaWAN-végpont két módot ismer (l. `Api\LoraWan::$fields`, `object/Mód`):
--   1 - ajtó állapot        -> ez a gyóntatás jelzése
--   2 - vízszivárgás        -> ez valami egészen más
--
-- Csakhogy MINDKETTŐ ugyanabba a `confessions.status` mezőbe írt 'ON'/'OFF'-ot, a
-- `Church::getConfessionStatusAttribute()` pedig a templom LEGUTOLSÓ sorát veszi, módra
-- való szűrés nélkül. Egy jelzett vízszivárgásból tehát „Most van gyóntatás a
-- helyszínen!" lett a templom oldalán.
--
-- Nem elméleti eset: a végpont dokumentációja maga mondja, hogy „Egy misézőhelyen több
-- eszköz is lehet" (a `local_id` mező leírása) — ajtó- és szivárgásérzékelő egy
-- templomban a TERVEZETT üzemmód.
--
-- Az alapérték 1: a meglévő sorok az ajtóérzékelőtől jöttek (a Mód 2 eszközök telepítése
-- még nem kezdődött el), tehát a visszamenőleges besorolás helyes.
--
-- A NÉV SZÁNDÉKOSAN NEM `mod`: az a MySQL-ben foglalt szó (a maradékos osztás
-- operátora). Az Eloquent ugyan backtick-eli az oszlopneveket, de egy jövőbeli nyers
-- lekérdezés némán elhasalna rajta — a sajátom is elhasalt, amikor kipróbáltam.
ALTER TABLE `confessions`
    ADD COLUMN IF NOT EXISTS `device_mode` TINYINT NOT NULL DEFAULT 1
        COMMENT '#866: 1 = ajtó/gyóntatás, 2 = vízszivárgás' AFTER `local_id`;

ALTER TABLE `confessions`
    ADD INDEX IF NOT EXISTS `church_mode_timestamp` (`church_id`, `device_mode`, `timestamp`);
