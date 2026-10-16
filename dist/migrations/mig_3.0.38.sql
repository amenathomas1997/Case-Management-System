-- 3.0.38: privacy incident log; record copy and correction requests. Requires MariaDB
-- 10.4 or newer. Safe to run again: every statement adds only what is missing.

-- A privacy incident (a possible breach): what happened, when it was discovered and the
-- 60-day notice deadline, the risk assessment, and who was affected. The affected
-- person's name is kept (client_label) when their record is purged later.
CREATE TABLE IF NOT EXISTS `privacy_incidents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(40) NOT NULL,
  `occurred_on` date DEFAULT NULL,
  `discovered_on` date NOT NULL,
  `notice_deadline` date NOT NULL,
  `description` text NOT NULL,
  `info_involved` varchar(500) DEFAULT NULL,
  `risk_nature` text DEFAULT NULL,
  `risk_recipient` text DEFAULT NULL,
  `risk_acquired` text DEFAULT NULL,
  `risk_mitigation` text DEFAULT NULL,
  `risk_level` varchar(40) NOT NULL DEFAULT 'Under assessment',
  `status` varchar(20) NOT NULL DEFAULT 'Open',
  `reported_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `closed_at` datetime DEFAULT NULL,
  `closed_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `privacy_incident_clients` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `incident_id` int(10) unsigned NOT NULL,
  `client_id` int(10) unsigned DEFAULT NULL,
  `client_label` varchar(190) NOT NULL,
  `notified_at` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `incident_client` (`incident_id`, `client_id`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `fk_pic_incident` FOREIGN KEY (`incident_id`) REFERENCES `privacy_incidents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pic_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Notices sent (to whom, how, when) and dated follow-up entries.
CREATE TABLE IF NOT EXISTS `privacy_incident_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `incident_id` int(10) unsigned NOT NULL,
  `event_type` varchar(20) NOT NULL,
  `recipient` varchar(60) DEFAULT NULL,
  `method` varchar(60) DEFAULT NULL,
  `happened_on` date NOT NULL,
  `detail` text NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `incident_id` (`incident_id`),
  CONSTRAINT `fk_pie_incident` FOREIGN KEY (`incident_id`) REFERENCES `privacy_incidents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A request for a copy of the record or a correction (portal types 'records' and
-- 'correction') is due in 30 days and closes with the outcome the client sees.
ALTER TABLE `portal_requests`
  ADD COLUMN IF NOT EXISTS `due_on` date DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `outcome` varchar(500) DEFAULT NULL AFTER `due_on`;
