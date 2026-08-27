-- #872: értesítési gyakoriság és a napi/heti összefoglaló várólistája.
--
-- Az éles migrációt lásd: docker/mysql/migrations/872-ertesites-gyakorisag.sql

USE `miserend`;

/*
 * Az alapérték SZÁNDÉKOSAN 'daily', a meglévő felhasználókra is.
 *
 * borazslo döntése a #872-ben: „Legyen rögtön a B (azonnal / napi / heti), és persze
 * mindenki napira állítva. Ennyi késleltetése az adatok befutásának kb soha sem gond."
 *
 * A `notifications` oszlop marad, és erősebb: `0` esetén semmilyen értesítő nem megy,
 * a gyakoriságtól függetlenül.
 */
ALTER TABLE `user`
  ADD COLUMN IF NOT EXISTS `notification_frequency`
    ENUM('instant','daily','weekly') NOT NULL DEFAULT 'daily' AFTER `notifications`;

/*
 * A halasztott értesítések. Nem a kész levelet tesszük félre, hanem az ESEMÉNYT:
 * a digest egyetlen, templomonként csoportosított levél lesz, nem N levél egymás alá
 * fűzve. A `sent_at` a kiküldés bélyege — a sorokat nem töröljük, mert a „mit küldtünk
 * ki neki" kérdésre az `emails` tábla mellett ez a másik nyom.
 */
CREATE TABLE IF NOT EXISTS `notification_digest_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `type` varchar(30) NOT NULL COMMENT 'remark | suggestion | image',
  `church_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `url` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `uid_sent` (`uid`,`sent_at`),
  KEY `sent_created` (`sent_at`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
