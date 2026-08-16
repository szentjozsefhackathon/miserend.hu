-- #791: az ExternalApi::clearAllOldCache() bekötése.
--
-- borazslo a #791-hez: „a ExternalApi::clearAllOldCache() tényleg jó ötlet bekötni,
-- márha jól van megírva :D És akkor nem is kell a másik clearOldCache".
--
-- Eddig a 39-es cron KIZÁRÓLAG az Overpass cache-ét takarította, a többi kilenc külső
-- API lejárt fájljai pedig ottmaradtak. A fejlesztői adatbázison mérve az első futás
-- 43 fájlt törölt, ebből 35-öt a Nominatimtól — azokhoz évek óta nem nyúlt senki.
--
-- A 39-es sort ÁTÁLLÍTJUK, nem újat veszünk fel: így nem marad ott a szűkebb hatókörű
-- takarítás egy második cronként, ahogy borazslo is kérte.

UPDATE `crons`
SET `class` = '\\ExternalApi\\ExternalApi',
    `function` = 'clearAllOldCache'
WHERE `id` = 39;

-- Ha valamiért nincs 39-es sor (friss adatbázis), vegyük fel.
INSERT INTO `crons` (`id`, `class`, `function`, `frequency`, `from`, `until`)
SELECT 39, '\\ExternalApi\\ExternalApi', 'clearAllOldCache', '1 day', NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM `crons` WHERE `id` = 39);
