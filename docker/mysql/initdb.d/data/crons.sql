/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19-11.8.5-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: 127.0.0.1    Database: miserend
-- ------------------------------------------------------
-- Server version	12.1.2-MariaDB-ubu2404

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;

--
-- Dumping data for table `crons`
--

LOCK TABLES `crons` WRITE;
/*!40000 ALTER TABLE `crons` DISABLE KEYS */;
set autocommit=0;
INSERT INTO `crons` VALUES
(15,'\\Api\\Sqlite','cron','1 day',NULL,NULL,'2023-06-10 02:46:47',0,'2023-06-09 02:46:47','2016-01-06 04:05:02','2023-06-09 02:46:47'),
(17,'\\ExternalApi\\OverpassApi','clearOldCache','1 day',NULL,NULL,'2023-06-09 20:50:02',0,'2023-06-08 20:50:02','2016-01-06 04:05:02','2023-06-08 20:50:02'),
(19,'\\Distance','updateSome','15 min',NULL,NULL,'2018-04-30 13:15:09',3298,'2018-04-30 13:00:09','2016-01-06 04:05:02','2023-06-07 21:05:01'),
(24,'\\OSM','syncUrlMiserendFromOSM','1 day','1am','6am','2023-06-09 20:35:40',0,'2023-06-08 20:35:40','2016-01-05 17:18:23','2023-06-08 20:35:40'),
(25,'\\OSM','checkBoundaries','5 min',NULL,NULL,'2023-06-09 00:50:02',0,'2023-06-09 00:45:02','2018-05-02 14:15:01','2023-06-09 00:45:02'),
(26,'\\Token','cleanOut','2 hours',NULL,NULL,'2023-06-09 02:55:01',0,'2023-06-09 00:55:01','0000-00-00 00:00:00','2023-06-09 00:55:01'),
(27,'\\Message','clean','1 hour',NULL,NULL,'2023-06-09 01:40:01',0,'2023-06-09 00:40:01','2021-03-06 18:56:00','2023-06-09 00:40:01'),
(28,'\\Photos','cron','1 week',NULL,NULL,'2023-06-12 20:55:01',0,'2023-06-05 20:55:01','2021-03-06 18:56:00','2023-06-05 20:55:01'),
(30,'\\User','sendActivationNotification','20 minutes','1am','6am','2023-06-09 01:10:02',0,'2023-06-09 00:50:02','0000-00-00 00:00:00','2023-06-09 00:50:02'),
(31,'\\User','sendInactivityNotification','20 minutes','1am','6am','2023-06-09 01:10:02',0,'2023-06-09 00:50:02','0000-00-00 00:00:00','2023-06-09 00:50:02'),
(34,'\\User','sendUpdateNotification','20 minutes','1am','6am','2023-06-09 00:58:07',0,'2023-06-09 00:38:07','0000-00-00 00:00:00','2023-06-09 00:38:07'),
(35,'\\User','deleteNonActivatedUsers','20 minutes','1am','6am','2023-06-09 00:45:01',0,'2023-06-09 00:25:01','0000-00-00 00:00:00','2023-06-09 00:25:01'),
(37,'\\Api\\NearBy','cleanOldLogs','1 day',NULL,NULL,'2000-01-01 00:45:01',0,'2000-01-01 00:25:01','0000-00-00 00:00:00','2024-09-25 00:25:01'),
(38,'\\ExternalApi\\ElasticsearchApi','updateChurches','6 hours',NULL,NULL,'2026-01-26 23:58:05',0,'2026-01-26 17:58:05','0000-00-00 00:00:00','2026-01-26 17:58:05'),
(39,'\\ExternalApi\\ElasticsearchApi','updateMasses','6 hours',NULL,NULL,'2026-01-26 23:58:39',0,'2026-01-26 17:58:39','0000-00-00 00:00:00','2026-01-26 17:58:39'),
(40,'\\ExternalCalendarImporter','importAllExternalCalendars','1 day',NULL,NULL,'0000-00-00 00:00:00',0,'0000-00-00 00:00:00','0000-00-00 00:00:00','0000-00-00 00:00:00'),
(41,'\\Crons','cleanExternalApiStats','1 day',NULL,NULL,'0000-00-00 00:00:00',0,'0000-00-00 00:00:00','0000-00-00 00:00:00','0000-00-00 00:00:00'),
(42,'\\Crons','cleanNotificationEmails','1 day',NULL,NULL,'0000-00-00 00:00:00',0,'0000-00-00 00:00:00','0000-00-00 00:00:00','0000-00-00 00:00:00');
/*!40000 ALTER TABLE `crons` ENABLE KEYS */;
UNLOCK TABLES;

-- #306: külön INSERT-ként (nem a fenti VALUES-listába), hogy ne ütközzön más
-- branch cron-hozzáadásával a merge-nél (pl. #351 cron 41/42).
INSERT INTO `crons` VALUES
(43,'\\Crons','rollPeriodYears','1 month',NULL,NULL,'0000-00-00 00:00:00',0,'0000-00-00 00:00:00','0000-00-00 00:00:00','0000-00-00 00:00:00');

-- #315: a heti önkéntes-kiosztás cronjai külön INSERT-ként (nem a fenti VALUES-listába),
-- hogy ne ütközzön más branch cron-hozzáadásával a merge-nél. Id 46/47 (42-45 másutt foglalt).
-- Meglévő (nem friss) adatbázison ezt kézzel kell befuttatni — deploy-lépés.
INSERT INTO `crons` VALUES
(46,'\\Campaign','assignUpdates','1 week',NULL,NULL,'0000-00-00 00:00:00',0,'0000-00-00 00:00:00','0000-00-00 00:00:00','0000-00-00 00:00:00'),
(47,'\\Campaign','clearoutVolunteers','1 month',NULL,NULL,'0000-00-00 00:00:00',0,'0000-00-00 00:00:00','0000-00-00 00:00:00','0000-00-00 00:00:00');
commit;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2026-01-09  1:08:33
