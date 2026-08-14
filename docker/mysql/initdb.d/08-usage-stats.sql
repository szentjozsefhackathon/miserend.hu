/*
 * #724: használati statisztika — süti nélkül, IP nélkül, azonosítás nélkül.
 *
 * A kérdés az volt, hogyan lehetne "a legtöbb használható adatot gyűjteni anélkül, hogy
 * ehhez sokat kéne papírozni". A válasz: NE gyűjtsünk semmit, amiből személy azonosítható.
 * Itt csak napi összesítés van — nincs sor, ami egy látogatóhoz köthető lenne, tehát
 * nincs mit elfelejteni, nincs mihez hozzájárulást kérni, és nem kell süti-elfogadó ablak.
 *
 * A minta a már meglévő `stats_externalapi`: napi bontású UPSERT-számláló. Ott a KIMENŐ
 * hívásokat mérjük, itt a bejövőket.
 *
 * Nullázni nem kell: a napi bontásból bármelyik időszak (hét/hónap/év) kiszámolható,
 * és a takarítást a `\Crons::cleanUsageStats()` végzi.
 */

USE miserend;

/*
 * Oldalletöltések. A `route` NEM a nyers URL, hanem a normalizált útvonal
 * (`templom/{id}`, `SearchResultsMasses`, `api/v4/nearby`) — így a sorok száma napi
 * néhány tucat marad, és a query-stringben esetleg érkező szabad szöveg sem kerül be.
 */
CREATE TABLE IF NOT EXISTS `stats_pageviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `route` varchar(120) NOT NULL,
  `kind` enum('html','api','ajax') NOT NULL DEFAULT 'html',
  `count` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nap_utvonal` (`date`,`route`,`kind`),
  KEY `nap` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
 * Keresőkifejezések. Ez a jegyben külön kiemelt kérdés ("Pláne a kereső izgalmas, hogy
 * mit is használnak"), és a legértékesebb belőle a NULLA találatos keresés: az mondja meg,
 * mit keresnek nálunk, amit nem találnak meg.
 *
 * A kifejezés kisbetűsítve, összevont szóközökkel tárolódik, és semmi nem köti
 * látogatóhoz — se idő percre, se munkamenet, se sorrend.
 */
CREATE TABLE IF NOT EXISTS `stats_searches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `keyword` varchar(100) NOT NULL,
  `hits` tinyint(1) NOT NULL DEFAULT 1,
  `count` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nap_kulcsszo` (`date`,`keyword`,`hits`),
  KEY `nap` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
