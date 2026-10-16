-- 3.0.47: progress reports, case closure and outcomes. Requires MariaDB 10.4 or newer.
-- Safe to run again.

-- The report as it was emailed, so the counselor's link shows what was sent.
ALTER TABLE `report_acks`
  ADD COLUMN IF NOT EXISTS `report_text` mediumtext DEFAULT NULL AFTER `ack_ip`;

-- Surveys answered by phone (staff enter the answers) as well as by email link.
ALTER TABLE `surveys`
  ADD COLUMN IF NOT EXISTS `channel` varchar(20) NOT NULL DEFAULT 'Email' AFTER `comments`,
  ADD COLUMN IF NOT EXISTS `recorded_by` int(10) unsigned DEFAULT NULL AFTER `channel`,
  ADD COLUMN IF NOT EXISTS `reminded_at` datetime DEFAULT NULL AFTER `recorded_by`;

-- Vision status and age of onset, for the Outcome report's breakdowns.
ALTER TABLE `clients`
  ADD COLUMN IF NOT EXISTS `vision_status` varchar(40) DEFAULT NULL AFTER `ipe_amended_on`,
  ADD COLUMN IF NOT EXISTS `vision_onset_age` tinyint(3) unsigned DEFAULT NULL AFTER `vision_status`;

-- 30, 60 and 90-day checks after a case closes.
CREATE TABLE IF NOT EXISTS `closure_followups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `closure_id` int(10) unsigned DEFAULT NULL,
  `interval_days` smallint(5) unsigned NOT NULL,
  `due_on` date NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Pending',
  `still_employed` tinyint(1) DEFAULT NULL,
  `still_using_tech` tinyint(1) DEFAULT NULL,
  `independent` tinyint(1) DEFAULT NULL,
  `needs_service` tinyint(1) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `completed_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  KEY `idx_due` (`status`,`due_on`),
  CONSTRAINT `fk_closefu_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
