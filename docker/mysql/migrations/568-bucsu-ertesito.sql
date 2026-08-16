-- #568: búcsú-emlékeztető a templomgondnokoknak.
--
-- borazslo spec-je a jegyben: „A várható dátum előtt mondjuk 21 nappal küldjük ki az
-- emailt a templomgondnokoknak (nem kell egyházmegye felelős, se általános admin)".
--
-- A dátum a szabad szöveges `bucsu` mezőből jön; a pontatlanság megengedett:
-- „Egyébként nem baj, ha +/- pár nap (utána vasárnap, meg ilyenek) hiszen az
-- értesítés nem kell pontosan menjen."
--
-- NAPI futás: a cron azt nézi, mely templom búcsúja esik PONTOSAN 21 nap múlvára.

INSERT INTO `crons` (`id`, `class`, `function`, `frequency`, `from`, `until`)
VALUES (568, '\\User', 'sendBucsuReminder', '1 day', NULL, NULL)
ON DUPLICATE KEY UPDATE `class` = VALUES(`class`), `function` = VALUES(`function`);
