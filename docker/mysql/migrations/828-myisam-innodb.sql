-- #828: a maradék MyISAM táblák átállítása InnoDB-re.
--
-- A MyISAM NEM TÁMOGAT TRANZAKCIÓT. Ez nem elméleti kérdés: az integrációs tesztek
-- java része `DB::beginTransaction()` / `rollBack()` párral dolgozik, és azt hiszi,
-- hogy tisztán maga után takarít. A `user` táblára viszont a rollback NÉMÁN nem hat —
-- a beszúrt sor ottmarad. A fejlesztői adatbázisban emiatt 5167 felhasználóból 1943
-- teszt-maradék volt, és a `teszt_plebanos` login 90-szer szerepelt.
--
-- Ez nem csak szemét: a duplikátumok miatt a `where('login', ...)` lekérdezések
-- találomra választanak sort, tehát a tesztek egymás adatán is dolgozhatnak.
--
-- Élesben a `user` a legérzékenyebb tábla erre: a MyISAM tábla-szintű zárolást
-- használ (nem sor-szintűt), és összeomlás után nem áll helyre magától.
--
-- FULLTEXT index egyiken sincs, tehát a konverziónak nincs funkcionális ára.
-- A megye/orszagok/varosok táblák a 496-497-498-as migrációval amúgy is megszűnnek;
-- itt csak azért szerepelnek, hogy a lépés sorrendtől függetlenül lefusson.

USE `miserend`;

ALTER TABLE `user`            ENGINE=InnoDB;
ALTER TABLE `chat`            ENGINE=InnoDB;
ALTER TABLE `egyhazmegye`     ENGINE=InnoDB;
ALTER TABLE `espereskerulet`  ENGINE=InnoDB;

-- Ezek a 496-497-498 után már nem léteznek; addig viszont ugyanaz vonatkozik rájuk.
ALTER TABLE IF EXISTS `megye`     ENGINE=InnoDB;
ALTER TABLE IF EXISTS `orszagok`  ENGINE=InnoDB;
ALTER TABLE IF EXISTS `varosok`   ENGINE=InnoDB;
