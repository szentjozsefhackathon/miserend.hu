-- #845: a levélhiba OKA legyen visszakereshető, és a saját összeomlásunk ne számítson
-- kézbesíthetetlen címnek.
--
-- Az `emails` táblában eddig csak annyi állt, hogy `status='error'`. A tényleges ok
-- (SMTP-visszautasítás? érvénytelen cím? nincs konfigurálva a kiszolgáló?) kizárólag az
-- `error_log`-ba ment, ráadásul `[miserend]` előtag NÉLKÜL — tehát a docs/logok.md-ben
-- dokumentált `docker logs | grep '[miserend]'` sem találta meg. Az éles /health 117
-- hibás `user_pleaselogin` levelet mutat, és egyikről sem tudjuk megmondani, MIÉRT.
--
-- Az index sem szépészeti kérdés: az `User::isUndeliverable()` merítésenként kétszer
-- kérdezi ezt a táblát (type + to + status), a tábla pedig monoton nő, mert a #823 óta
-- a hibás sorokat szándékosan megtartjuk — azok az egyetlen bizonyítékok.

ALTER TABLE `emails`
    ADD COLUMN IF NOT EXISTS `error_reason` TEXT NULL DEFAULT NULL AFTER `status`,
    ADD COLUMN IF NOT EXISTS `failed_at` TIMESTAMP NULL DEFAULT NULL AFTER `error_reason`;

ALTER TABLE `emails`
    ADD INDEX IF NOT EXISTS `type_to_status` (`type`, `to`, `status`);
