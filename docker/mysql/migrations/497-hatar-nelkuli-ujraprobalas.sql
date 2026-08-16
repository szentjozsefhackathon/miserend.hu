-- #496: a határ nélkül maradt templomok újra sorba állítása.
--
-- A checkBoundaries() sora `boundaries_checked_at` szerint halad, és a #570/#700 óta
-- helyesen megkülönbözteti a HIBÁT a "lekérdeztük, de nincs határ" esettől: hibánál nem
-- bélyegez. A második eset viszont bélyeget kap — és ott is marad, amíg a teljes sor
-- körbe nem ér.
--
-- Ez akkor fáj, ha a "nincs határ" nem az OSM valósága volt, hanem a mi oldalunk
-- változott azóta. Pontosan ez történt Szlovákiában (a szlovák minta 23%-ának nincs
-- határa): a tárolt szintjeink elavultak, és a #699 óta a lekérdezés a 4-es szintet is
-- behúzza — a régen bélyegzett templomok viszont ettől még nem próbálkoznak újra.
--
-- A tényleges munkát a \Crons::requeueChurchesWithoutBoundary() végzi. Ez a fájl csak
-- regisztrálja, HAVI futással: a 30 napos korlát miatt gyakoribb futásnak nincs értelme.

INSERT INTO `crons` (`id`, `class`, `function`, `frequency`, `from`, `until`)
VALUES (497, '\\Crons', 'requeueChurchesWithoutBoundary', 'monthly', NULL, NULL)
ON DUPLICATE KEY UPDATE `class` = VALUES(`class`), `function` = VALUES(`function`);
