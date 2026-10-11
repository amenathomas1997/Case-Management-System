-- Table definitions of the v3.0.7 release (git abb0e89:dist/schema_full.sql) with the
-- seed rows and AUTO_INCREMENT counters left out. tests/full_schema.php loads it to
-- check that the migrations bring a real 3.0.7 database to the fresh-install schema.
-- Never edit it: it records what 3.0.7 shipped.
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `access_bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `access_bookings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `service_type` varchar(40) NOT NULL,
  `provider` varchar(190) DEFAULT NULL,
  `needed_at` datetime DEFAULT NULL,
  `duration_min` int(10) unsigned NOT NULL DEFAULT 0,
  `location` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Requested',
  `cost` decimal(10,2) DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  KEY `needed_at` (`needed_at`),
  CONSTRAINT `ab_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_usage`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_usage` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `feature` varchar(40) NOT NULL,
  `provider` varchar(40) DEFAULT NULL,
  `model` varchar(120) DEFAULT NULL,
  `chars_in` int(10) unsigned NOT NULL DEFAULT 0,
  `chars_out` int(10) unsigned NOT NULL DEFAULT 0,
  `redacted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `feature` (`feature`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `api_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `api_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `assessment_instrument_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `assessment_instrument_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `instrument_id` int(10) unsigned NOT NULL,
  `label` varchar(255) NOT NULL,
  `sort` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `instrument_id` (`instrument_id`),
  CONSTRAINT `aii_ibfk_1` FOREIGN KEY (`instrument_id`) REFERENCES `assessment_instruments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `assessment_instruments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `assessment_instruments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `scale_min` tinyint(4) NOT NULL DEFAULT 0,
  `scale_max` tinyint(4) NOT NULL DEFAULT 4,
  `scale_labels` varchar(600) DEFAULT NULL,
  `higher_is_better` tinyint(1) NOT NULL DEFAULT 1,
  `category` varchar(80) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `assessment_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `assessment_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `primary_tech` enum('Screen Reader','Magnification','Braille Display','Speech-to-Text','Independent Living Skills','Braille Literacy','Low Vision Devices','Rehabilitation Technology','Other') NOT NULL DEFAULT 'Screen Reader',
  `hardware_details` varchar(255) DEFAULT NULL,
  `software_version` varchar(190) DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `entity` varchar(40) DEFAULT NULL,
  `entity_id` int(10) unsigned DEFAULT NULL,
  `detail` varchar(255) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `auth_amendments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_amendments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `track_id` int(10) unsigned NOT NULL,
  `old_hours` decimal(7,2) DEFAULT NULL,
  `new_hours` decimal(7,2) DEFAULT NULL,
  `old_rate` decimal(8,2) DEFAULT NULL,
  `new_rate` decimal(8,2) DEFAULT NULL,
  `old_expiration` date DEFAULT NULL,
  `new_expiration` date DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `changed_by` int(10) unsigned DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `track_id` (`track_id`),
  CONSTRAINT `auth_amendments_ibfk_1` FOREIGN KEY (`track_id`) REFERENCES `training_tracks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_competency`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_competency` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `skill_id` int(10) unsigned NOT NULL,
  `level` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `note` varchar(500) DEFAULT NULL,
  `assessed_by` int(10) unsigned DEFAULT NULL,
  `assessed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `client_skill` (`client_id`,`skill_id`),
  KEY `cc_ibfk_2` (`skill_id`),
  CONSTRAINT `cc_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cc_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `curriculum_skills` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `category` enum('Authorization','IPE','Release','Report','Resource','Other') NOT NULL DEFAULT 'Other',
  `title` varchar(190) NOT NULL,
  `orig_name` varchar(190) NOT NULL,
  `path` varchar(255) NOT NULL,
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `client_documents_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_notes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `track_id` int(10) unsigned DEFAULT NULL,
  `note_type` enum('Case Note','Phone Call','Progress Report','Monthly Summary','Intake') NOT NULL DEFAULT 'Case Note',
  `note_format` varchar(10) NOT NULL DEFAULT 'free',
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `birp_behavior` text DEFAULT NULL,
  `birp_intervention` text DEFAULT NULL,
  `birp_response` text DEFAULT NULL,
  `birp_plan` text DEFAULT NULL,
  `author_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `idem_key` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_idem` (`idem_key`),
  KEY `client_id` (`client_id`),
  KEY `idx_note_track` (`track_id`),
  CONSTRAINT `fk_note_track` FOREIGN KEY (`track_id`) REFERENCES `training_tracks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_notes_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_scored_assessment_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_scored_assessment_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `scored_id` int(10) unsigned NOT NULL,
  `item_id` int(10) unsigned NOT NULL,
  `score` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `scored_id` (`scored_id`),
  CONSTRAINT `csai_ibfk_1` FOREIGN KEY (`scored_id`) REFERENCES `client_scored_assessments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_scored_assessments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_scored_assessments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `instrument_id` int(10) unsigned NOT NULL,
  `assessed_on` date NOT NULL,
  `assessor_id` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `total_score` int(11) NOT NULL DEFAULT 0,
  `max_score` int(11) NOT NULL DEFAULT 0,
  `pct` decimal(5,1) NOT NULL DEFAULT 0.0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  KEY `instrument_id` (`instrument_id`),
  CONSTRAINT `csa_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `csa_ibfk_2` FOREIGN KEY (`instrument_id`) REFERENCES `assessment_instruments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_status_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_status_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `old_status` varchar(40) DEFAULT NULL,
  `new_status` varchar(40) NOT NULL,
  `changed_by` int(10) unsigned DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `client_status_history_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `clients` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `dob` date DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `status` enum('Pending Assessment','Waitlist','Active Training','On Hold','Closed - Rehabilitated','Closed - Other') NOT NULL DEFAULT 'Pending Assessment',
  `client_type` enum('VR','Pre-ETS') NOT NULL DEFAULT 'VR',
  `a11y_screen_reader` tinyint(1) NOT NULL DEFAULT 0,
  `a11y_format` varchar(40) DEFAULT NULL,
  `a11y_primary_tech` varchar(120) DEFAULT NULL,
  `a11y_contact` varchar(40) DEFAULT NULL,
  `a11y_notes` text DEFAULT NULL,
  `school` varchar(190) DEFAULT NULL,
  `counselor_name` varchar(120) DEFAULT NULL,
  `counselor_email` varchar(190) DEFAULT NULL,
  `counselor_phone` varchar(40) DEFAULT NULL,
  `counselor_agency` varchar(190) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `assigned_to` int(10) unsigned DEFAULT NULL,
  `guardian_name` varchar(120) DEFAULT NULL,
  `guardian_phone` varchar(40) DEFAULT NULL,
  `guardian_email` varchar(190) DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cohort_attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cohort_attendance` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` int(10) unsigned NOT NULL,
  `client_id` int(10) unsigned NOT NULL,
  `present` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_client` (`session_id`,`client_id`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `ca_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `cohort_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ca_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cohort_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cohort_members` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cohort_id` int(10) unsigned NOT NULL,
  `client_id` int(10) unsigned NOT NULL,
  `added_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `cohort_client` (`cohort_id`,`client_id`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `cm_ibfk_1` FOREIGN KEY (`cohort_id`) REFERENCES `cohorts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cm_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cohort_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cohort_sessions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cohort_id` int(10) unsigned NOT NULL,
  `session_date` date NOT NULL,
  `activity` varchar(80) NOT NULL,
  `topic` varchar(190) DEFAULT NULL,
  `duration_min` int(10) unsigned NOT NULL DEFAULT 0,
  `staff_id` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `cohort_id` (`cohort_id`),
  CONSTRAINT `cs_ibfk_1` FOREIGN KEY (`cohort_id`) REFERENCES `cohorts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cohorts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cohorts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `school` varchar(190) DEFAULT NULL,
  `coordinator_id` int(10) unsigned DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `consent_forms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `consent_forms` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `guardian_name` varchar(120) NOT NULL,
  `guardian_email` varchar(190) NOT NULL,
  `token` char(48) NOT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `signed_at` datetime DEFAULT NULL,
  `signed_name` varchar(120) DEFAULT NULL,
  `sign_ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `consent_forms_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contract_deliverables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contract_deliverables` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `contract_id` int(10) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `due_date` date DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Pending',
  `notes` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `contract_id` (`contract_id`),
  CONSTRAINT `cd2_ibfk_1` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contract_spend`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contract_spend` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `contract_id` int(10) unsigned NOT NULL,
  `spent_on` date NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `memo` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `contract_id` (`contract_id`),
  CONSTRAINT `csp_ibfk_1` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contracts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contracts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `funder` varchar(190) DEFAULT NULL,
  `contract_number` varchar(120) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `curricula`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `curricula` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `tech` varchar(120) DEFAULT NULL,
  `description` varchar(500) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `curriculum_skills`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `curriculum_skills` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `curriculum_id` int(10) unsigned NOT NULL,
  `skill` varchar(300) NOT NULL,
  `sort` int(10) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `curriculum_id` (`curriculum_id`),
  CONSTRAINT `cks_ibfk_1` FOREIGN KEY (`curriculum_id`) REFERENCES `curricula` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `device_request_quotes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `device_request_quotes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int(10) unsigned NOT NULL,
  `vendor` varchar(190) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `quote_ref` varchar(120) DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  CONSTRAINT `drq_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `device_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `device_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `device_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `requested_by` int(10) unsigned DEFAULT NULL,
  `device_name` varchar(190) NOT NULL,
  `category` varchar(60) DEFAULT NULL,
  `justification` text DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'Requested',
  `funding_source` varchar(120) DEFAULT NULL,
  `auth_number` varchar(80) DEFAULT NULL,
  `authorized_amount` decimal(10,2) DEFAULT NULL,
  `authorized_at` date DEFAULT NULL,
  `selected_quote_id` int(10) unsigned DEFAULT NULL,
  `po_number` varchar(80) DEFAULT NULL,
  `ordered_at` date DEFAULT NULL,
  `delivered_at` date DEFAULT NULL,
  `trained_at` date DEFAULT NULL,
  `warranty_until` date DEFAULT NULL,
  `serial` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  KEY `status` (`status`),
  CONSTRAINT `dr_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `equipment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `equipment` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `category` enum('Braille Display','Screen Reader License','Magnifier','Tablet/Phone','Computer','Keyboard/Input','Audio','Other') NOT NULL DEFAULT 'Other',
  `serial` varchar(120) DEFAULT NULL,
  `item_condition` enum('New','Good','Fair','Needs Repair') NOT NULL DEFAULT 'Good',
  `value` decimal(9,2) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `useful_life_months` smallint(5) unsigned NOT NULL DEFAULT 36,
  `notes` varchar(255) DEFAULT NULL,
  `status` enum('Available','On Loan','Repair','Retired') NOT NULL DEFAULT 'Available',
  `maintenance_interval_days` int(10) unsigned DEFAULT NULL,
  `last_service_date` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `equipment_followups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `equipment_followups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `loan_id` int(10) unsigned NOT NULL,
  `client_id` int(10) unsigned NOT NULL,
  `equipment_id` int(10) unsigned NOT NULL,
  `interval_days` smallint(5) unsigned NOT NULL,
  `due_date` date NOT NULL,
  `status` enum('Pending','Completed','Skipped') NOT NULL DEFAULT 'Pending',
  `in_use` tinyint(1) DEFAULT NULL,
  `satisfaction` tinyint(3) unsigned DEFAULT NULL,
  `barriers` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `completed_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_due` (`status`,`due_date`),
  KEY `idx_loan` (`loan_id`),
  KEY `idx_client` (`client_id`),
  KEY `idx_equip` (`equipment_id`),
  CONSTRAINT `fk_ef_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ef_equip` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ef_loan` FOREIGN KEY (`loan_id`) REFERENCES `equipment_loans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `equipment_loans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `equipment_loans` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `equipment_id` int(10) unsigned NOT NULL,
  `client_id` int(10) unsigned NOT NULL,
  `loaned_at` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `returned_at` date DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `equipment_id` (`equipment_id`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `equipment_loans_ibfk_1` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE,
  CONSTRAINT `equipment_loans_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `equipment_maintenance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `equipment_maintenance` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `equipment_id` int(10) unsigned NOT NULL,
  `service_date` date NOT NULL,
  `service_type` enum('Maintenance','Calibration','Repair','Inspection','Cleaning','Other') NOT NULL DEFAULT 'Maintenance',
  `notes` varchar(500) DEFAULT NULL,
  `cost` decimal(9,2) DEFAULT NULL,
  `performed_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `equipment_id` (`equipment_id`),
  CONSTRAINT `equipment_maintenance_ibfk_1` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `equipment_reservations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `equipment_reservations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `equipment_id` int(10) unsigned NOT NULL,
  `client_id` int(10) unsigned NOT NULL,
  `reserved_by` int(10) unsigned DEFAULT NULL,
  `needed_from` date DEFAULT NULL,
  `needed_until` date DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Reserved',
  `notes` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `equipment_id` (`equipment_id`),
  KEY `client_id` (`client_id`),
  KEY `status` (`status`),
  CONSTRAINT `er_ibfk_1` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE,
  CONSTRAINT `er_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `goal_progress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `goal_progress` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `goal_id` int(10) unsigned NOT NULL,
  `progress_pct` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `status` enum('Not Started','In Progress','Achieved') NOT NULL DEFAULT 'In Progress',
  `note` text DEFAULT NULL,
  `recorded_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `goal_id` (`goal_id`),
  CONSTRAINT `goal_progress_ibfk_1` FOREIGN KEY (`goal_id`) REFERENCES `smart_goals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `goal_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `goal_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `category` varchar(80) NOT NULL,
  `specific_goal` text NOT NULL,
  `measurable_metric` text NOT NULL,
  `focus_area` varchar(190) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `perms` varchar(255) NOT NULL,
  `own_caseload_only` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `homework`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `homework` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `track_id` int(10) unsigned NOT NULL,
  `title` varchar(190) NOT NULL,
  `instructions` text DEFAULT NULL,
  `assigned_at` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('Assigned','Completed') NOT NULL DEFAULT 'Assigned',
  `client_reported_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `track_id` (`track_id`),
  CONSTRAINT `homework_ibfk_1` FOREIGN KEY (`track_id`) REFERENCES `training_tracks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incidents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `incidents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned DEFAULT NULL,
  `reported_by` int(10) unsigned DEFAULT NULL,
  `incident_date` date NOT NULL,
  `location` varchar(190) DEFAULT NULL,
  `type` varchar(40) NOT NULL DEFAULT 'Other',
  `severity` enum('Low','Medium','High','Critical') NOT NULL DEFAULT 'Low',
  `description` text NOT NULL,
  `action_taken` text DEFAULT NULL,
  `status` enum('Open','Under review','Closed') NOT NULL DEFAULT 'Open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`,`incident_date`),
  KEY `idx_client` (`client_id`),
  CONSTRAINT `fk_inc_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `track_id` int(10) unsigned NOT NULL,
  `invoice_number` varchar(40) NOT NULL,
  `status` enum('Draft','Submitted','Paid') NOT NULL DEFAULT 'Draft',
  `hours` decimal(7,2) NOT NULL DEFAULT 0.00,
  `hours_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `mileage` decimal(8,1) NOT NULL DEFAULT 0.0,
  `mileage_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `submitted_at` date DEFAULT NULL,
  `paid_at` date DEFAULT NULL,
  `payment_ref` varchar(80) DEFAULT NULL,
  `paid_amount` decimal(10,2) DEFAULT NULL,
  `expenses_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `track_id` (`track_id`),
  CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`track_id`) REFERENCES `training_tracks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `kb_articles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kb_articles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `category` varchar(80) DEFAULT NULL,
  `body` mediumtext DEFAULT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `is_public` (`is_public`),
  KEY `category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `email` varchar(190) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip`,`attempted_at`),
  KEY `idx_email_time` (`email`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `login_otps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_otps` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `consumed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `login_otps_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `channel` enum('client','counselor') NOT NULL,
  `sender` enum('staff','client','counselor') NOT NULL,
  `staff_id` int(10) unsigned DEFAULT NULL,
  `body` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_thread` (`client_id`,`channel`,`created_at`),
  KEY `idx_unread` (`channel`,`read_at`),
  CONSTRAINT `fk_msg_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `message` varchar(255) NOT NULL,
  `link` varchar(190) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_unread` (`user_id`,`read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `passkeys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `passkeys` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `credential_id` varchar(700) NOT NULL,
  `public_key` text NOT NULL,
  `alg` varchar(10) NOT NULL DEFAULT 'ES256',
  `sign_count` bigint(20) unsigned NOT NULL DEFAULT 0,
  `name` varchar(120) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cred` (`credential_id`(191)),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `portal_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `type` varchar(20) NOT NULL,
  `session_id` int(10) unsigned DEFAULT NULL,
  `details` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'New',
  `handled_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  KEY `status` (`status`),
  CONSTRAINT `preq_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `portal_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `token` char(48) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_id` (`client_id`),
  UNIQUE KEY `token` (`token`),
  CONSTRAINT `portal_tokens_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `referrals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `referrals` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `reason` varchar(255) NOT NULL,
  `note_type` enum('Initial Intake','Tech Assessment Request','Rehab Teaching Request','Counseling Request','Pre-ETS Request','Plan Amendment','Other') NOT NULL DEFAULT 'Initial Intake',
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `counselor_name` varchar(120) NOT NULL,
  `counselor_agency` varchar(190) NOT NULL,
  `counselor_email` varchar(190) NOT NULL,
  `counselor_phone` varchar(40) NOT NULL,
  `client_name` varchar(120) NOT NULL,
  `client_dob` date DEFAULT NULL,
  `client_phone` varchar(40) DEFAULT NULL,
  `client_email` varchar(190) DEFAULT NULL,
  `client_address` varchar(255) DEFAULT NULL,
  `status` enum('New','Reviewed','Converted') NOT NULL DEFAULT 'New',
  `assigned_to` int(10) unsigned DEFAULT NULL,
  `client_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `referral_source` enum('counselor','self','school','medical','family','agency','other') NOT NULL DEFAULT 'counselor',
  `income_bracket` varchar(30) DEFAULT NULL,
  `quoted_fee` decimal(6,2) DEFAULT NULL,
  `school` varchar(190) DEFAULT NULL,
  `grade_level` varchar(40) DEFAULT NULL,
  `guardian_name` varchar(120) DEFAULT NULL,
  `guardian_phone` varchar(40) DEFAULT NULL,
  `relationship` varchar(80) DEFAULT NULL,
  `contact_first` varchar(20) DEFAULT NULL,
  `consent_confirmed` tinyint(1) NOT NULL DEFAULT 0,
  `preets_services` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `report_acks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `report_acks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `track_id` int(10) unsigned NOT NULL,
  `token` char(48) NOT NULL,
  `sent_to` varchar(190) NOT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `acked_at` datetime DEFAULT NULL,
  `ack_ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `report_acks_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scheduled_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scheduled_sessions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `track_id` int(10) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime NOT NULL,
  `location` varchar(190) DEFAULT NULL,
  `is_remote` tinyint(1) NOT NULL DEFAULT 0,
  `meeting_url` varchar(500) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `status` enum('Scheduled','Completed','Cancelled','No Show') NOT NULL DEFAULT 'Scheduled',
  `work_log_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reminder_sent` tinyint(1) NOT NULL DEFAULT 0,
  `group_uid` char(12) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `scheduled_sessions_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sent_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sent_alerts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `track_id` int(10) unsigned NOT NULL,
  `alert_key` varchar(20) NOT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_track_alert` (`track_id`,`alert_key`),
  CONSTRAINT `sent_alerts_ibfk_1` FOREIGN KEY (`track_id`) REFERENCES `training_tracks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_bundles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_bundles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `client_type` enum('VR','Pre-ETS','Any') NOT NULL DEFAULT 'Any',
  `categories` text NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `name` varchar(64) NOT NULL,
  `value` text NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `smart_goals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `smart_goals` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `track_id` int(10) unsigned NOT NULL,
  `specific_goal` text NOT NULL,
  `measurable_metric` text NOT NULL,
  `focus_area` varchar(190) DEFAULT NULL,
  `status` enum('Not Started','In Progress','Achieved') NOT NULL DEFAULT 'Not Started',
  `progress_pct` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `target_date` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `track_id` (`track_id`),
  CONSTRAINT `smart_goals_ibfk_1` FOREIGN KEY (`track_id`) REFERENCES `training_tracks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `surveys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `surveys` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `token` char(48) NOT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `satisfaction` tinyint(4) DEFAULT NULL,
  `goals_met` tinyint(4) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `surveys_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tasks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(190) NOT NULL,
  `details` text DEFAULT NULL,
  `client_id` int(10) unsigned DEFAULT NULL,
  `referral_id` int(10) unsigned DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `assigned_to` int(10) unsigned DEFAULT NULL,
  `status` enum('Open','Done') NOT NULL DEFAULT 'Open',
  `auto_key` varchar(80) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `done_at` datetime DEFAULT NULL,
  `done_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_auto` (`auto_key`),
  KEY `idx_assigned` (`assigned_to`),
  KEY `idx_status` (`status`),
  KEY `idx_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tech_assessments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tech_assessments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `assessment_date` date NOT NULL,
  `primary_disability` varchar(190) DEFAULT NULL,
  `primary_tech` enum('Screen Reader','Magnification','Braille Display','Speech-to-Text','Independent Living Skills','Braille Literacy','Low Vision Devices','Rehabilitation Technology','Other') NOT NULL DEFAULT 'Screen Reader',
  `hardware_details` varchar(255) DEFAULT NULL,
  `software_version` varchar(190) DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `attachment_name` varchar(190) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `tech_assessments_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `track_expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `track_expenses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `track_id` int(10) unsigned NOT NULL,
  `expense_date` date NOT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(9,2) NOT NULL,
  `is_billed` tinyint(1) NOT NULL DEFAULT 0,
  `invoice_id` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `track_id` (`track_id`),
  CONSTRAINT `track_expenses_ibfk_1` FOREIGN KEY (`track_id`) REFERENCES `training_tracks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `training_tracks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_tracks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `title` varchar(190) NOT NULL,
  `service_type` enum('Assistive Technology','Rehab Teaching','Counseling','Pre-ETS','Other') NOT NULL DEFAULT 'Assistive Technology',
  `rsa_category` varchar(80) DEFAULT NULL,
  `bundle_id` int(10) unsigned DEFAULT NULL,
  `status` enum('Active','Completed','Cancelled') NOT NULL DEFAULT 'Active',
  `auth_number` varchar(80) NOT NULL,
  `funding_agency` varchar(190) NOT NULL DEFAULT 'State VR',
  `authorized_hours` decimal(7,2) NOT NULL DEFAULT 0.00,
  `hourly_rate` decimal(8,2) NOT NULL DEFAULT 95.00,
  `mileage_rate` decimal(6,3) NOT NULL DEFAULT 0.000,
  `start_date` date DEFAULT NULL,
  `expiration_date` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `report_cadence` enum('none','weekly','biweekly','monthly') NOT NULL DEFAULT 'monthly',
  `last_report_at` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `training_tracks_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `trash`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `trash` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `entity` varchar(40) NOT NULL,
  `entity_id` int(10) unsigned NOT NULL,
  `label` varchar(190) DEFAULT NULL,
  `payload` mediumtext NOT NULL,
  `deleted_by` int(10) unsigned DEFAULT NULL,
  `deleted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','instructor') NOT NULL DEFAULT 'instructor',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `totp_secret` varchar(64) DEFAULT NULL,
  `totp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `weekly_hours` decimal(5,2) NOT NULL DEFAULT 25.00,
  `group_id` int(10) unsigned DEFAULT NULL,
  `entra_oid` varchar(64) DEFAULT NULL,
  `totp_last_step` bigint(20) DEFAULT NULL,
  `cal_token` char(48) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vrc_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vrc_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `counselor_email` varchar(190) NOT NULL,
  `counselor_name` varchar(120) NOT NULL,
  `token` char(48) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `counselor_email` (`counselor_email`),
  UNIQUE KEY `token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `work_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `work_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `track_id` int(10) unsigned NOT NULL,
  `work_date` date NOT NULL,
  `service_type` varchar(40) DEFAULT NULL,
  `rsa_category` varchar(80) DEFAULT NULL,
  `goal_id` int(10) unsigned DEFAULT NULL,
  `hours` decimal(5,2) NOT NULL,
  `mileage` decimal(6,1) NOT NULL DEFAULT 0.0,
  `description` text NOT NULL,
  `is_billed` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `hourly_rate` decimal(8,2) DEFAULT NULL,
  `mileage_rate` decimal(8,3) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `invoice_id` int(10) unsigned DEFAULT NULL,
  `signature_path` varchar(80) DEFAULT NULL,
  `signed_name` varchar(120) DEFAULT NULL,
  `idem_key` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_idem` (`idem_key`),
  KEY `track_id` (`track_id`),
  CONSTRAINT `work_logs_ibfk_1` FOREIGN KEY (`track_id`) REFERENCES `training_tracks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `worksite_assessments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `worksite_assessments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `employer` varchar(190) DEFAULT NULL,
  `job_title` varchar(190) DEFAULT NULL,
  `worksite_location` varchar(255) DEFAULT NULL,
  `assessor_id` int(10) unsigned DEFAULT NULL,
  `assessment_date` date DEFAULT NULL,
  `job_tasks` text DEFAULT NULL,
  `barriers` text DEFAULT NULL,
  `recommendations` text DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'Scheduled',
  `follow_up_date` date DEFAULT NULL,
  `outcome` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  KEY `status` (`status`),
  CONSTRAINT `wa_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- SEED DATA --

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;






/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


-- AT service workflows. Safe to rerun; no existing records are rewritten.
CREATE TABLE IF NOT EXISTS at_records (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 kind VARCHAR(32) NOT NULL,
 client_id INT UNSIGNED NULL,
 track_id INT UNSIGNED NULL,
 equipment_id INT UNSIGNED NULL,
 staff_id INT UNSIGNED NULL,
 parent_id INT UNSIGNED NULL,
 title VARCHAR(190) NOT NULL,
 status VARCHAR(40) NOT NULL,
 due_date DATE NULL,
 details LONGTEXT NOT NULL,
 source_key VARCHAR(120) NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_by INT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY at_source (source_key),
 KEY at_kind_due (kind, status, due_date),
 KEY at_client (client_id),
 CONSTRAINT at_client_fk FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
 CONSTRAINT at_track_fk FOREIGN KEY (track_id) REFERENCES training_tracks(id) ON DELETE SET NULL,
 CONSTRAINT at_equipment_fk FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE SET NULL,
 CONSTRAINT at_staff_fk FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT at_parent_fk FOREIGN KEY (parent_id) REFERENCES at_records(id) ON DELETE CASCADE,
 CONSTRAINT at_details_json CHECK (JSON_VALID(details))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS at_reminders (
 record_id INT UNSIGNED NOT NULL,
 due_date DATE NOT NULL,
 user_id INT UNSIGNED NOT NULL,
 PRIMARY KEY (record_id, due_date, user_id),
 CONSTRAINT at_reminder_record_fk FOREIGN KEY (record_id) REFERENCES at_records(id) ON DELETE CASCADE,
 CONSTRAINT at_reminder_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
