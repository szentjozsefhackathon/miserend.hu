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
-- Table structure for table `attributes`
--

USE miserend;

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `attributes` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `church_id` int(11) NOT NULL,
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `fromOSM` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `church_id` (`church_id`),
  CONSTRAINT `attributes_ibfk_1` FOREIGN KEY (`church_id`) REFERENCES `templomok` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `boundaries`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `boundaries` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `boundary` varchar(50) NOT NULL,
  `admin_level` int(2) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `alt_name` varchar(255) DEFAULT NULL,
  `denomination` varchar(50) DEFAULT NULL,
  `osmtype` varchar(9) DEFAULT NULL,
  `osmid` int(11) DEFAULT NULL,
  `created_at` DATE NULL DEFAULT NULL,
  `updated_at` DATE NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `index1` (`boundary`,`admin_level`),
  KEY `index2` (`osmtype`,`osmid`)
) ENGINE=InnoDB AUTO_INCREMENT=7891 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cal_generated_periods`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
CREATE TABLE IF NOT EXISTS `cal_generated_periods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `period_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `weight` int NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `created_at` DATE NOT NULL DEFAULT CURRENT_DATE,
  `updated_at` DATE NULL DEFAULT CURRENT_DATE,
  `color` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `period_id` (`period_id`),
  CONSTRAINT `fk_cal_generated_periods_period_id` FOREIGN KEY (`period_id`) REFERENCES `cal_periods` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cal_masses`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
CREATE TABLE IF NOT EXISTS `cal_masses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `church_id` int(11) NOT NULL,
  `period_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `types` text,
  `rite` varchar(50) NOT NULL,
  `start_date` varchar(50) NOT NULL,
  `duration` json DEFAULT NULL,
  `rrule` json DEFAULT NULL,
  `experiod` json DEFAULT NULL,
  `manual_experiod` json DEFAULT NULL,
  `exdate` json DEFAULT NULL,
  `lang` varchar(3) NOT NULL,
  `comment` text DEFAULT NULL,
  `created_at` DATE NOT NULL DEFAULT CURRENT_DATE,
  `updated_at` DATE NULL DEFAULT CURRENT_DATE,
  PRIMARY KEY (`id`),
  KEY `period_id` (`period_id`),
  KEY `church_id` (`church_id`),
  CONSTRAINT `fk_cal_masses_period_id` FOREIGN KEY (`period_id`) REFERENCES `cal_periods` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cal_periods`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
CREATE TABLE IF NOT EXISTS `cal_periods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `weight` int NOT NULL,
  `start_month_day` varchar(5) DEFAULT NULL,
  `end_month_day` varchar(5) DEFAULT NULL,
  `start_period_id` int(11) DEFAULT NULL,
  `end_period_id` int(11) DEFAULT NULL,
  `all_inclusive` tinyint(1) DEFAULT NULL,
  `multi_day` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` DATE NOT NULL DEFAULT CURRENT_DATE,
  `updated_at` DATE NULL DEFAULT CURRENT_DATE,
  `special_type` enum('CHRISTMAS','EASTER') DEFAULT NULL,
  `selectable` tinyint(1) DEFAULT 1,
  `color` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `start_period_id` (`start_period_id`),
  KEY `end_period_id` (`end_period_id`),
  CONSTRAINT `fk_cal_periods_start_period` FOREIGN KEY (`start_period_id`) REFERENCES `cal_periods` (`id`),
  CONSTRAINT `fk_cal_periods_end_period` FOREIGN KEY (`end_period_id`) REFERENCES `cal_periods` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cal_period_years`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
CREATE TABLE IF NOT EXISTS `cal_period_years` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `period_id` int(11) NOT NULL,
  `start_year` int(4) NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` date NOT NULL DEFAULT CURRENT_DATE,
  `updated_at` date NULL DEFAULT CURRENT_DATE,
  PRIMARY KEY (`id`),
  KEY `period_id` (`period_id`),
  CONSTRAINT `fk_cal_period_years_period_id` FOREIGN KEY (`period_id`) REFERENCES `cal_periods` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cal_suggestion_packages`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
CREATE TABLE IF NOT EXISTS `cal_suggestion_packages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `church_id` bigint(20) unsigned DEFAULT NULL,
  `sender_name` varchar(255) DEFAULT NULL,
  `sender_email` varchar(255) DEFAULT NULL,
  `sender_user_id` bigint(20) unsigned DEFAULT NULL,
  `sender_message` text DEFAULT NULL,
  `state` enum('ACCEPTED','REJECTED','PENDING') DEFAULT 'PENDING',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `church_id` (`church_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cal_suggestions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
CREATE TABLE IF NOT EXISTS `cal_suggestions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `package_id` bigint(20) unsigned NOT NULL,
  `period_id` bigint(20) unsigned DEFAULT NULL,
  `mass_id` bigint(20) unsigned DEFAULT NULL,
  `mass_state` enum('NEW','DELETED','MODIFIED') NOT NULL,
  `changes` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `package_id` (`package_id`),
  CONSTRAINT `fk_cal_suggestions_package` FOREIGN KEY (`package_id`) REFERENCES `cal_suggestion_packages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `chat` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `datum` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user` varchar(20) NOT NULL DEFAULT '',
  `kinek` varchar(20) NOT NULL DEFAULT '',
  `szoveg` tinytext NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `church_holders`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `church_holders` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `church_id` int(10) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` enum('asked','allowed','denied','revoked') NOT NULL DEFAULT 'asked',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `church_links`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `church_links` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `church_id` int(10) NOT NULL,
  `href` varchar(255) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1505 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `church_relationships`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `church_relationships` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `parent_church_id` int(11) NOT NULL COMMENT 'felsőbbrendű misézőhely',
  `child_church_id`  int(11) NOT NULL COMMENT 'alsóbbrendű misézőhely',
  `type` enum(
    'subordinate',
    'associated',
    'territorially_independent'
  ) NOT NULL COMMENT 'kapcsolat típusa',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_pair` (`parent_church_id`, `child_church_id`),
  KEY `parent_idx` (`parent_church_id`),
  KEY `child_idx`  (`child_church_id`),
  CONSTRAINT `fk_cr_parent` FOREIGN KEY (`parent_church_id`)
    REFERENCES `templomok` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cr_child`  FOREIGN KEY (`child_church_id`)
    REFERENCES `templomok` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `confessions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `confessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `deduplicationId` char(36) NOT NULL,
  `church_id` int(11) NOT NULL,
  `local_id` int(11) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `fulldata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`fulldata`)),
  `status` enum('ON','OFF') NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `deduplicationId` (`deduplicationId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crons`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `crons` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class` varchar(45) DEFAULT NULL,
  `function` varchar(45) DEFAULT NULL,
  `frequency` varchar(45) NOT NULL,
  `from` varchar(45) DEFAULT NULL COMMENT 'strtotime',
  `until` varchar(45) DEFAULT NULL COMMENT 'strtotime',
  `deadline_at` timestamp NULL DEFAULT NULL,
  `attempts` int(2) DEFAULT 0,
  `lastsuccess_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_At` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `distances`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `distances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fromLat` decimal(11,7) NOT NULL,
  `fromLon` decimal(11,7) NOT NULL,
  `toLat` decimal(11,7) NOT NULL,
  `toLon` decimal(11,7) NOT NULL,
  `distance` float NOT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `toupdate` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `Coord` (`fromLat`,`fromLon`,`toLat`,`toLon`),
  KEY `From` (`fromLat`,`fromLon`)
) ENGINE=InnoDB AUTO_INCREMENT=58224 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `egyhazmegye`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `egyhazmegye` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nev` varchar(250) NOT NULL DEFAULT '',
  `sorrend` int(3) NOT NULL DEFAULT 0,
  `ok` enum('i','n') NOT NULL DEFAULT 'i',
  `felelos` varchar(20) NOT NULL DEFAULT '',
  `email` varchar(50) NOT NULL DEFAULT '',
  `csakez` enum('i','n') NOT NULL DEFAULT 'i',
  `osm_relation` int(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci PACK_KEYS=1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `emails`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `emails` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(30) DEFAULT NULL,
  `to` varchar(100) NOT NULL,
  `header` text DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  `status` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `espereskerulet`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `espereskerulet` (
  `id` int(3) NOT NULL AUTO_INCREMENT,
  `ehm` int(2) NOT NULL DEFAULT 0,
  `nev` varchar(50) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=239 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `events`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `year` varchar(4) DEFAULT NULL,
  `date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_UNIQUE` (`id`),
  UNIQUE KEY `name+year` (`name`,`year`)
) ENGINE=InnoDB AUTO_INCREMENT=381 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `external_calendars`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `external_calendars` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `church_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `url` varchar(2048) NOT NULL,
  `active` tinyint(1) DEFAULT 1,
  `last_import_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_church_external` (`church_id`,`name`),
  KEY `fk_external_calendars_church_id` (`church_id`),
  CONSTRAINT `fk_external_calendars_church_id` FOREIGN KEY (`church_id`) REFERENCES `templomok` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `favorites`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `favorites` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL,
  `tid` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid_tid_UNIQUE` (`uid`,`tid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `keyword_shortcuts`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `keyword_shortcuts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `church_id` int(11) NOT NULL,
  `osmtag_id` int(11) NOT NULL,
  `type` varchar(30) NOT NULL,
  `value` varchar(200) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `index_value` (`value`),
  KEY `FK_keyword_shortchuts_church_idx` (`church_id`),
  KEY `FK_keyword_shortchuts_osmtag_idx` (`osmtag_id`),
  KEY `church_type_value` (`church_id`,`type`,`value`),
  KEY `type_value` (`type`,`value`)
) ENGINE=InnoDB AUTO_INCREMENT=12825 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `lookup_boundary_church`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `lookup_boundary_church` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `boundary_id` int(11) NOT NULL,
  `church_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique` (`church_id`,`boundary_id`),
  KEY `FK_church_id_idx` (`church_id`),
  KEY `FK_boundary_id_idx` (`boundary_id`)
) ENGINE=InnoDB AUTO_INCREMENT=84777320 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `lookup_church_osm`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `lookup_church_osm` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `church_id` int(11) NOT NULL,
  `osm_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique` (`church_id`,`osm_id`),
  KEY `FK_church_id_idx` (`church_id`),
  KEY `FK_osm_id_idx` (`osm_id`),
  CONSTRAINT `FK_lookup_church_osm_osm_id` FOREIGN KEY (`osm_id`) REFERENCES `osm` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB AUTO_INCREMENT=5317741 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `lookup_osm_enclosed`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `lookup_osm_enclosed` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `osm_id` int(11) NOT NULL,
  `enclosing_id` int(11) NOT NULL,
  `created_at` varchar(45) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique` (`enclosing_id`,`osm_id`),
  KEY `FK_osm_id_idx` (`osm_id`),
  KEY `FK_osm_enclosing_id_idx` (`enclosing_id`),
  CONSTRAINT `FK_lookup_osm_enclosed_enclosing` FOREIGN KEY (`enclosing_id`) REFERENCES `osm` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `FK_lookup_osm_enclosed_osm` FOREIGN KEY (`osm_id`) REFERENCES `osm` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB AUTO_INCREMENT=36847 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `megye`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `megye` (
  `id` int(2) NOT NULL AUTO_INCREMENT,
  `megyenev` varchar(50) NOT NULL DEFAULT '',
  `orszag` int(2) NOT NULL DEFAULT 12,
  `egyeb` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sid` varchar(45) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `severity` varchar(10) DEFAULT 'info',
  `text` text DEFAULT NULL,
  `shown` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `orszagok`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `orszagok` (
  `id` int(3) NOT NULL AUTO_INCREMENT,
  `nev` varchar(50) NOT NULL DEFAULT '',
  `telkod` varchar(5) NOT NULL DEFAULT '',
  `ok` enum('i','n') NOT NULL DEFAULT 'i',
  `kiemelt` enum('i','n') NOT NULL DEFAULT 'n',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `osm`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `osm` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `osmid` varchar(11) NOT NULL,
  `osmtype` varchar(9) NOT NULL,
  `lon` decimal(10,8) DEFAULT NULL,
  `lat` decimal(11,8) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_UNIQUE` (`osmid`,`osmtype`),
  UNIQUE KEY `idUNIQUE` (`osmid`,`osmtype`)
) ENGINE=InnoDB AUTO_INCREMENT=8439 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `photos`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `photos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `church_id` int(11) NOT NULL,
  `filename` varchar(100) NOT NULL DEFAULT '',
  `title` varchar(250) NOT NULL DEFAULT '',
  `weight` int(2) NOT NULL DEFAULT 0,
  `flag` enum('i','n') NOT NULL DEFAULT 'i',
  `height` int(11) DEFAULT NULL,
  `width` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `kiemelt` (`flag`),
  KEY `FKchurch` (`church_id`),
  CONSTRAINT `FKchurch` FOREIGN KEY (`church_id`) REFERENCES `templomok` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB AUTO_INCREMENT=44673 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `remarks`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `remarks` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `nev` varchar(50) NOT NULL DEFAULT '',
  `login` varchar(20) NOT NULL DEFAULT '',
  `email` varchar(50) NOT NULL DEFAULT '',
  `megbizhato` enum('?','i','n','e') NOT NULL DEFAULT '?',
  `church_id` int(11) NOT NULL DEFAULT 0,
  `allapot` enum('u','f','j') NOT NULL DEFAULT 'u',
  `admin` varchar(20) NOT NULL DEFAULT '',
  `admindatum` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `leiras` text NOT NULL,
  `adminmegj` text DEFAULT NULL,
  `log` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `index2` (`id`,`church_id`,`allapot`),
  KEY `index1` (`id`,`church_id`),
  KEY `FK_church_id` (`church_id`),
  CONSTRAINT `FK_church_id` FOREIGN KEY (`church_id`) REFERENCES `templomok` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stats_externalapi`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `stats_externalapi` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(45) DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `responsecode` int(11) DEFAULT NULL,
  `rawdata` longtext DEFAULT NULL,
  `date` date DEFAULT NULL,
  `count` int(11) DEFAULT NULL,
  `diff` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fast` (`url`,`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `szentsegimadasok`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `szentsegimadasok` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `church_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `starttime` varchar(5) NOT NULL,
  `endtime` varchar(5) NOT NULL,
  `type` varchar(40) DEFAULT NULL,
  `info` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `templomok`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `templomok` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nev` varchar(150) NOT NULL DEFAULT '',
  `ismertnev` varchar(150) NOT NULL DEFAULT '',
  `orszag` int(2) NOT NULL DEFAULT 0,
  `megye` int(2) NOT NULL DEFAULT 0,
  `varos` varchar(100) NOT NULL DEFAULT '',
  `cim` varchar(250) NOT NULL DEFAULT '',
  `megkozelites` tinytext NOT NULL DEFAULT '',
  `plebania` text NOT NULL,
  `pleb_url` varchar(100) NOT NULL DEFAULT '',
  `pleb_eml` varchar(100) NOT NULL DEFAULT '',
  `egyhazmegye` int(2) NOT NULL DEFAULT 0,
  `espereskerulet` int(3) NOT NULL DEFAULT 0,
  `leiras` mediumtext NOT NULL,
  `megjegyzes` text NOT NULL,
  `miseaktiv` int(11) DEFAULT 1,
  `misemegj` text NOT NULL,
  `bucsu` text NOT NULL,
  `frissites` date NULL DEFAULT NULL,
  `kontakt` varchar(250) NOT NULL DEFAULT '',
  `kontaktmail` varchar(70) NOT NULL DEFAULT '',
  `adminmegj` text NOT NULL,
  `letrehozta` varchar(20) NOT NULL DEFAULT '',
  `megbizhato` enum('i','n') NOT NULL DEFAULT 'n',
  `created_at` timestamp NULL DEFAULT NULL,
  `modositotta` varchar(20) NOT NULL DEFAULT '',
  `moddatum` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `log` text NOT NULL,
  `ok` enum('i','n','f') NOT NULL DEFAULT 'i',
  `eszrevetel` enum('i','n','f') NOT NULL DEFAULT 'n',
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `osmid` varchar(11) DEFAULT NULL,
  `osmtype` varchar(9) DEFAULT NULL,
  `lat` decimal(11,7) DEFAULT NULL,
  `lon` decimal(10,7) DEFAULT NULL,
  `boundaries_checked_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id` (`id`),
  KEY `varos` (`varos`),
  KEY `ismertnev` (`ismertnev`),
  KEY `egyhazmegye` (`egyhazmegye`),
  KEY `espereskerulet` (`espereskerulet`),
  KEY `osm` (`osmid`,`osmtype`),
  KEY `boundaries_checked_at` (`boundaries_checked_at`)
) ENGINE=InnoDB AUTO_INCREMENT=5420 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `templomok_full`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `templomok_full` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nev` varchar(150) NOT NULL DEFAULT '',
  `ismertnev` varchar(150) NOT NULL DEFAULT '',
  `orszag` int(2) NOT NULL DEFAULT 0,
  `megye` int(2) NOT NULL DEFAULT 0,
  `varos` varchar(100) NOT NULL DEFAULT '',
  `cim` varchar(250) NOT NULL DEFAULT '',
  `megkozelites` tinytext NOT NULL,
  `plebania` text NOT NULL,
  `pleb_url` varchar(100) NOT NULL DEFAULT '',
  `pleb_eml` varchar(100) NOT NULL DEFAULT '',
  `egyhazmegye` int(2) NOT NULL DEFAULT 0,
  `espereskerulet` int(3) NOT NULL DEFAULT 0,
  `leiras` text NOT NULL,
  `megjegyzes` text NOT NULL,
  `miseaktiv` int(11) DEFAULT 1,
  `misemegj` text NOT NULL,
  `bucsu` text NOT NULL,
  `frissites` date NULL DEFAULT NULL,
  `kontakt` varchar(250) NOT NULL DEFAULT '',
  `kontaktmail` varchar(70) NOT NULL DEFAULT '',
  `adminmegj` text NOT NULL,
  `letrehozta` varchar(20) NOT NULL DEFAULT '',
  `megbizhato` enum('i','n') NOT NULL DEFAULT 'n',
  `created_at` timestamp NULL DEFAULT NULL,
  `modositotta` varchar(20) NOT NULL DEFAULT '',
  `moddatum` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `log` text NOT NULL,
  `ok` enum('i','n','f') NOT NULL DEFAULT 'i',
  `eszrevetel` enum('i','n','f') NOT NULL DEFAULT 'n',
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `osmid` varchar(11) DEFAULT NULL,
  `osmtype` varchar(9) DEFAULT NULL,
  `lat` decimal(11,7) DEFAULT NULL,
  `lon` decimal(10,7) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id` (`id`),
  KEY `varos` (`varos`),
  KEY `ismertnev` (`ismertnev`),
  KEY `egyhazmegye` (`egyhazmegye`),
  KEY `espereskerulet` (`espereskerulet`),
  KEY `osm` (`osmid`,`osmtype`)
) ENGINE=InnoDB AUTO_INCREMENT=5420 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `church_update_tokens`
--

CREATE TABLE IF NOT EXISTS `church_update_tokens` (
  `token` varchar(64) NOT NULL,
  `uid` int(11) NOT NULL,
  `church_id` int(11) DEFAULT NULL,
  `email_batch_id` varchar(64) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `tokens`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(15) DEFAULT NULL,
  `name` varchar(40) NOT NULL,
  `uid` int(11) DEFAULT NULL,
  `timeout` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_UNIQUE` (`id`),
  UNIQUE KEY `name_UNIQUE` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `updates`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `updates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL,
  `tid` int(11) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_UNIQUE` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `user` (
  `uid` int(11) NOT NULL AUTO_INCREMENT,
  `login` varchar(20) NOT NULL DEFAULT '',
  `jelszo` varchar(255) NOT NULL DEFAULT '',
  `jogok` varchar(200) NOT NULL DEFAULT '',
  `regdatum` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lastlogin` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lastactive` datetime DEFAULT NULL,
  `email` varchar(100) NOT NULL DEFAULT '',
  `notifications` int(1) DEFAULT 1,
  `becenev` varchar(50) NOT NULL DEFAULT '',
  `nev` varchar(100) NOT NULL DEFAULT '',
  `volunteer` int(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`uid`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `varosok`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `varosok` (
  `id` int(3) NOT NULL AUTO_INCREMENT,
  `irsz` int(4) NOT NULL DEFAULT 0,
  `megye_id` int(2) NOT NULL DEFAULT 0,
  `orszag` int(2) NOT NULL DEFAULT 46,
  `nev` varchar(50) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7845 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping events for database 'miserend'
--

--
-- Dumping routines for database 'miserend'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2026-01-09  1:08:33
