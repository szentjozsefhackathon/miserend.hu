-- #431: az alkalom saját helyszíne, ha nem a templomban van.
--
-- vlacko0930 kérése: „Jó lenne, ha templomtól távoleső szabadtéri alkalmakat lehetne
-- templom nélkül is felvenni pl koordinátákkal." A használati eset: „Röszke plébánia
-- biciklitúrát szervez időnként, és van mise valami random pusztai helyen."
--
-- A helyet az ALKALOMHOZ kötjük, nem új misézőhelyhez: így a mise a szervező
-- plébániáé marad, és nem keletkezik minden szabadtéri alkalomból egy örökre
-- ottmaradó, mise nélküli pont a térképen.
--
-- Mindhárom mező opcionális: NULL = a mise a templomban van. A meglévő misék tehát
-- érintetlenek maradnak.

USE `miserend`;

ALTER TABLE `cal_masses`
  ADD COLUMN IF NOT EXISTS `location_lat` DECIMAL(10,7) DEFAULT NULL AFTER `comment`,
  ADD COLUMN IF NOT EXISTS `location_lon` DECIMAL(10,7) DEFAULT NULL AFTER `location_lat`,
  ADD COLUMN IF NOT EXISTS `location_name` VARCHAR(255) DEFAULT NULL AFTER `location_lon`;
