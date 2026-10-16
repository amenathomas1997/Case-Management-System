-- 3.0.35: referral outcomes and case closure. Requires MariaDB 10.4 or newer. Safe to
-- run again: every statement adds only what is missing. Nothing is deleted.

-- A referral that does not become a client is Closed with a reason (Declined, Not
-- eligible, Withdrawn, Unable to reach, Duplicate, Referred elsewhere or Other), and the
-- referring counselor is emailed the outcome (outcome_emailed_at).
ALTER TABLE `referrals`
  MODIFY COLUMN `status` enum('New','Reviewed','Converted','Closed') NOT NULL DEFAULT 'New',
  ADD COLUMN IF NOT EXISTS `closed_reason` varchar(40) DEFAULT NULL AFTER `client_id`,
  ADD COLUMN IF NOT EXISTS `closed_note` varchar(255) DEFAULT NULL AFTER `closed_reason`,
  ADD COLUMN IF NOT EXISTS `closed_at` datetime DEFAULT NULL AFTER `closed_note`,
  ADD COLUMN IF NOT EXISTS `closed_by` int(10) unsigned DEFAULT NULL AFTER `closed_at`,
  ADD COLUMN IF NOT EXISTS `outcome_emailed_at` datetime DEFAULT NULL AFTER `closed_by`;

-- Each case closure: why it closed and what the person came away with (employment, or
-- an independence outcome), recorded with the status change. The newest row for a
-- client is the current closure; a reopened and re-closed case has more than one.
CREATE TABLE IF NOT EXISTS `client_closures` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `status` varchar(40) NOT NULL,
  `reason` varchar(60) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `employed` tinyint(1) DEFAULT NULL,
  `employer` varchar(190) DEFAULT NULL,
  `job_title` varchar(120) DEFAULT NULL,
  `job_start` date DEFAULT NULL,
  `hours_per_week` decimal(5,2) DEFAULT NULL,
  `hourly_wage` decimal(8,2) DEFAULT NULL,
  `independence_outcome` varchar(500) DEFAULT NULL,
  `closed_by` int(10) unsigned DEFAULT NULL,
  `closed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `fk_closure_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
