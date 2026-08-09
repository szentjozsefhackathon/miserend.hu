/*
 * #671: index az attributes táblára.
 *
 * A Church::facilityCoverage() számláló minden olyan keresésnél lefut, ahol be van
 * kapcsolva az akadálymentesség/gluténmentes szűrő, és `key` szerint szűr. Az
 * attributes táblán eddig csak a church_id-n volt index, tehát ez teljes
 * táblaolvasás lett volna minden ilyen keresésnél.
 *
 * Külön fájlban, hogy ne a 03-migrations.sql-t bővítse: a séma-konszolidáció
 * (#669) épp onnan szedi ki a tartalmat.
 */

USE miserend;

ALTER TABLE `attributes`
    ADD INDEX IF NOT EXISTS `key_church` (`key`, `church_id`);
