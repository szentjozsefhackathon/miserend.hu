-- #157: melyik külső naptárból jött a mise.
--
-- Eddig a `comment` mező pontos értéke ('External calendar import') jelölte, hogy egy
-- mise importált. Ez két dolgot tett lehetetlenné:
--
--  1. A naptár DESCRIPTION mezőjét nem lehetett átvenni, mert a `comment` foglalt volt.
--  2. Templomonként csak EGY naptár működhetett: az import a templom ÖSSZES jelölt
--     miséjét törölte, tehát a második naptár importja kitörölte az elsőét.
--
-- Mostantól a forrás azonosítja a misét, nem egy szövegkonstans. A régi sorok
-- visszamenőleg megkapják a templomuk egyetlen naptárát, ahol ez egyértelmű; ahol nem
-- (mert több naptár van), ott NULL marad, és a régi jelölés viszi tovább őket, amíg a
-- következő import felül nem írja.

USE `miserend`;

ALTER TABLE `cal_masses`
  ADD COLUMN IF NOT EXISTS `external_calendar_id` INT(11) DEFAULT NULL AFTER `church_id`;

ALTER TABLE `cal_masses`
  ADD INDEX IF NOT EXISTS `external_calendar_id` (`external_calendar_id`);

-- Visszamenőleges hozzárendelés: csak ott, ahol a templomnak PONTOSAN egy naptára van.
UPDATE `cal_masses` m
  JOIN (
    SELECT `church_id`, MIN(`id`) AS `calendar_id`, COUNT(*) AS `db`
    FROM `external_calendars`
    GROUP BY `church_id`
    HAVING `db` = 1
  ) c ON c.`church_id` = m.`church_id`
SET m.`external_calendar_id` = c.`calendar_id`
WHERE m.`comment` = 'External calendar import'
  AND m.`external_calendar_id` IS NULL;
