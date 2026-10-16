-- 3.0.36: record keeping and privacy. Requires MariaDB 10.4 or newer. Safe to run again:
-- every statement adds only what is missing. Nothing is deleted.

-- Case notes lock 30 days after they are written (setting note_lock_days). A later change
-- is an Addendum: a new note linked to the original (addendum_to).
ALTER TABLE `client_notes`
  MODIFY COLUMN `note_type` enum('Case Note','Phone Call','Progress Report','Monthly Summary','Intake','Addendum') NOT NULL DEFAULT 'Case Note',
  ADD COLUMN IF NOT EXISTS `addendum_to` int(10) unsigned DEFAULT NULL AFTER `track_id`,
  ADD KEY IF NOT EXISTS `addendum_to` (`addendum_to`);

-- Every earlier version of an edited note, saved just before the change. Tied to the
-- client (removed when the client record is purged), not to the note row.
CREATE TABLE IF NOT EXISTS `client_note_revisions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `note_id` int(10) unsigned NOT NULL,
  `client_id` int(10) unsigned NOT NULL,
  `track_id` int(10) unsigned DEFAULT NULL,
  `note_type` varchar(40) NOT NULL,
  `note_format` varchar(10) NOT NULL DEFAULT 'free',
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `birp_behavior` text DEFAULT NULL,
  `birp_intervention` text DEFAULT NULL,
  `birp_response` text DEFAULT NULL,
  `birp_plan` text DEFAULT NULL,
  `version_written_at` datetime DEFAULT NULL,
  `version_written_by` int(10) unsigned DEFAULT NULL,
  `replaced_at` datetime NOT NULL DEFAULT current_timestamp(),
  `replaced_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `note_id` (`note_id`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `fk_note_rev_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- An adult client's own consent and release of information (a guardian may sign for a
-- client with a guardian). Each release names one recipient, what it covers and why. It
-- is signed by email with a typed name (release.php) or recorded from a paper copy, is
-- valid for 1 year from signing (setting release_validity_days), and can be withdrawn.
CREATE TABLE IF NOT EXISTS `client_releases` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `signer_role` varchar(20) NOT NULL DEFAULT 'Client',
  `signer_name` varchar(120) NOT NULL,
  `signer_email` varchar(190) DEFAULT NULL,
  `recipient_type` varchar(40) NOT NULL,
  `recipient_name` varchar(190) NOT NULL,
  `recipient_email` varchar(190) DEFAULT NULL,
  `info_scope` varchar(500) NOT NULL,
  `purpose` varchar(255) NOT NULL,
  `method` varchar(10) NOT NULL DEFAULT 'Email',
  `token` char(48) DEFAULT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(10) unsigned DEFAULT NULL,
  `signed_at` datetime DEFAULT NULL,
  `signed_name` varchar(120) DEFAULT NULL,
  `sign_ip` varchar(45) DEFAULT NULL,
  `expires_on` date DEFAULT NULL,
  `withdrawn_at` datetime DEFAULT NULL,
  `withdrawn_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `fk_release_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Business associate agreements with the services that handle client information
-- (vendors.php). The owner records what is signed; nothing is turned off by it.
CREATE TABLE IF NOT EXISTS `vendor_agreements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vendor` varchar(120) NOT NULL,
  `service` varchar(255) NOT NULL,
  `client_info` varchar(255) NOT NULL DEFAULT '',
  `agreement_status` varchar(20) NOT NULL DEFAULT 'Not signed',
  `signed_on` date DEFAULT NULL,
  `renews_on` date DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vendor` (`vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The services this system already uses, as of 2026-10-16: no agreements signed.
INSERT IGNORE INTO `vendor_agreements` (`vendor`, `service`, `client_info`, `agreement_status`) VALUES
  ('Microsoft 365', 'Staff email, sign-in with Microsoft, and all email the system sends (Microsoft Graph)', 'Client names and details in emails and reports', 'Not signed'),
  ('Web host (Linode)', 'The server that runs the website, database and uploaded documents', 'All client records', 'Not signed'),
  ('AI provider', 'AI note drafting, read-aloud, translation and assistant features', 'Note text and client details sent to AI features (redacted where the system can)', 'Not signed'),
  ('Backup mailbox', 'Off-site copies of the database and documents', 'All client records', 'Not signed');
