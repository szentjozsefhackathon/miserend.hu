-- #496 / #497 / #498: a koordináta nélküli templomok helyadatának átmentése.
--
-- A három jegy a templomok.orszag, .megye és .varos kivezetéséről szól: a helyet
-- ezután a koordináta és az OSM-határok adják. Ez mindenre igaz, KIVÉVE azt a
-- néhány misézőhelyet, aminek egyáltalán nincs koordinátája — azoknak sosem lesz
-- boundary-juk, tehát az oszlopok eldobásával az EGYETLEN helymegjelölésük veszne
-- el. Élesben 47 ilyen templom van (22 magyar, 25 határon túli).
--
-- borazslo a #496-ban két lehetőséget adott: törlés, vagy a megjegyzés mezőbe.
-- Ez a nem-destruktív ág.
--
-- A tényleges átmentést a \Crons::archiveLocationOfChurchesWithoutCoordinates()
-- végzi (idempotens, az azonosítókat nevekre oldja fel). Ez a fájl csak
-- REGISZTRÁLJA cronként, hogy élesben futtatható legyen:
--
--     docker exec miserend php index.php q=cron cron_id=496
--
-- Futtatás UTÁN a cron-sor törölhető, egyszeri feladat.

INSERT INTO `crons` (`id`, `class`, `function`, `frequency`, `from`, `until`)
VALUES (496, '\\Crons', 'archiveLocationOfChurchesWithoutCoordinates', 'never', NULL, NULL)
ON DUPLICATE KEY UPDATE `class` = VALUES(`class`), `function` = VALUES(`function`);
