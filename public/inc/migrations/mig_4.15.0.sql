-- 4.15.0: staff agreements that each staff member reads and signs themselves (Administration,
-- Staff agreements; My agreements). A signed copy keeps the exact text that was signed.
-- Requires MariaDB 10.4 or newer. Safe to run again.
CREATE TABLE IF NOT EXISTS `staff_agreements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(190) NOT NULL,
  `body` mediumtext NOT NULL,
  `version` int(10) unsigned NOT NULL DEFAULT 1,
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `counts_as_confidentiality` tinyint(1) NOT NULL DEFAULT 0,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `staff_agreement_signatures` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `agreement_id` int(10) unsigned NOT NULL,
  `version` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `title` varchar(190) NOT NULL,
  `body` mediumtext NOT NULL,
  `signed_name` varchar(120) NOT NULL,
  `signed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `sign_ip` varchar(45) DEFAULT NULL,
  `via` varchar(20) NOT NULL DEFAULT 'website',
  `user_agent` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_agreement` (`user_id`,`agreement_id`,`signed_at`),
  KEY `agreement_version` (`agreement_id`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `staff_agreement_revisions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `agreement_id` int(10) unsigned NOT NULL,
  `version` int(10) unsigned NOT NULL,
  `old_title` varchar(190) NOT NULL,
  `old_body` mediumtext NOT NULL,
  `new_title` varchar(190) NOT NULL,
  `new_body` mediumtext NOT NULL,
  `small_correction` tinyint(1) NOT NULL DEFAULT 0,
  `changed_by` int(10) unsigned DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `agreement` (`agreement_id`,`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A starting text for the administrator to edit; nobody is asked to sign it until it is turned on.
INSERT INTO `staff_agreements` (`title`, `body`, `active`, `counts_as_confidentiality`)
SELECT 'Confidentiality agreement', 'Edit this text before turning the agreement on.
# Client information
- I keep everything I learn about clients confidential, during and after my work here.
- I look at a client''s record only when my work needs it.
- I share client information only as the client''s consent, a release of information or the law allows.
# Devices and accounts
- I keep my password and sign-in codes to myself, and lock my computer and phone when I step away.
- I report a lost phone or a possible privacy problem straight away.
# When I leave
- I return or delete client information I hold, and my duty of confidentiality continues.', 0, 1
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `staff_agreements`);

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.15.0') ON DUPLICATE KEY UPDATE `value` = '4.15.0';
