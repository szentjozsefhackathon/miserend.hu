/*
 * Ki fogadta el a javaslatot?
 *
 * A `cal_suggestion_packages` eddig CSAK az állapotot tárolta (PENDING/ACCEPTED/
 * REJECTED), azt nem, hogy KI és MIKOR döntött róla. Az adminfelületen ezért nem
 * lehetett látni a kezelő nevét — nem elveszett az adat, hanem sosem keletkezett.
 *
 * A beküldő oldalán is volt hiba: a `sender_user_id`-t a naptár-alkalmazás sosem
 * küldte el (a FormControl deklarálva volt, de soha nem kapott értéket), így az
 * oszlop mindig NULL maradt, és a felület nem tudta a beküldőt felhasználóhoz kötni.
 */

USE miserend;

ALTER TABLE `cal_suggestion_packages`
    ADD COLUMN `handled_by_user_id` bigint(20) unsigned NULL DEFAULT NULL AFTER `state`,
    ADD COLUMN `handled_at` timestamp NULL DEFAULT NULL AFTER `handled_by_user_id`;
