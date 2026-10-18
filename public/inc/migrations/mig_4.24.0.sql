-- 4.24.0: file exchange in the portals (documents shared with the client or the VR counselor, files
-- attached to messages), assistive technology outcomes recorded at closure, and custom fields (an intake
-- form builder: fields of your own on client records, optionally asked on the public referral form).
-- Safe to run again.
ALTER TABLE `client_documents` ADD COLUMN IF NOT EXISTS `portal_shared` tinyint(1) NOT NULL DEFAULT 0;
ALTER TABLE `client_documents` ADD COLUMN IF NOT EXISTS `counselor_shared` tinyint(1) NOT NULL DEFAULT 0;
ALTER TABLE `client_documents` ADD COLUMN IF NOT EXISTS `uploaded_via` varchar(20) DEFAULT NULL;
ALTER TABLE `messages` ADD COLUMN IF NOT EXISTS `document_id` int(10) unsigned DEFAULT NULL;
ALTER TABLE `client_closures` ADD COLUMN IF NOT EXISTS `at_recommended` smallint(5) unsigned DEFAULT NULL;
ALTER TABLE `client_closures` ADD COLUMN IF NOT EXISTS `at_acquired` smallint(5) unsigned DEFAULT NULL;
ALTER TABLE `client_closures` ADD COLUMN IF NOT EXISTS `at_in_use` tinyint(1) DEFAULT NULL;
ALTER TABLE `client_closures` ADD COLUMN IF NOT EXISTS `at_note` varchar(255) DEFAULT NULL;
CREATE TABLE IF NOT EXISTS `custom_fields` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(120) NOT NULL,
  `label_es` varchar(120) DEFAULT NULL,
  `hint` varchar(255) DEFAULT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'text',
  `options` text DEFAULT NULL,
  `required` tinyint(1) NOT NULL DEFAULT 0,
  `on_referral` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `custom_values` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `field_id` int(10) unsigned NOT NULL,
  `client_id` int(10) unsigned DEFAULT NULL,
  `referral_id` int(10) unsigned DEFAULT NULL,
  `value` text NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `field_client` (`field_id`,`client_id`),
  UNIQUE KEY `field_referral` (`field_id`,`referral_id`),
  KEY `client_id` (`client_id`),
  KEY `referral_id` (`referral_id`),
  CONSTRAINT `custom_values_field` FOREIGN KEY (`field_id`) REFERENCES `custom_fields` (`id`) ON DELETE CASCADE,
  CONSTRAINT `custom_values_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `custom_values_referral` FOREIGN KEY (`referral_id`) REFERENCES `referrals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.24.0') ON DUPLICATE KEY UPDATE `value` = '4.24.0';
