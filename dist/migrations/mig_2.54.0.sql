-- v2.54.0 — Incident & safety log.
CREATE TABLE IF NOT EXISTS `incidents` (
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
