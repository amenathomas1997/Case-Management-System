-- 4.23.0: competing with the VR agencies' own tools. Referral deadlines per funding agency
-- (contact and assessment), the VR portal tracker (each authorization's state in the agency's
-- portal, each invoice's payment request there), and e-signature for client documents (consent
-- to services, equipment loan agreements, any document). Safe to run again.
ALTER TABLE `funding_agencies` ADD COLUMN IF NOT EXISTS `contact_days` smallint(5) unsigned DEFAULT NULL;
ALTER TABLE `funding_agencies` ADD COLUMN IF NOT EXISTS `assess_days` smallint(5) unsigned DEFAULT NULL;
ALTER TABLE `funding_agencies` ADD COLUMN IF NOT EXISTS `portal_name` varchar(60) DEFAULT NULL;
ALTER TABLE `funding_agencies` ADD COLUMN IF NOT EXISTS `portal_url` varchar(255) DEFAULT NULL;
ALTER TABLE `training_tracks` ADD COLUMN IF NOT EXISTS `portal_status` varchar(20) DEFAULT NULL;
ALTER TABLE `training_tracks` ADD COLUMN IF NOT EXISTS `portal_at` date DEFAULT NULL;
ALTER TABLE `training_tracks` ADD COLUMN IF NOT EXISTS `portal_note` varchar(255) DEFAULT NULL;
ALTER TABLE `training_tracks` ADD COLUMN IF NOT EXISTS `portal_auth` varchar(80) DEFAULT NULL;
ALTER TABLE `invoices` ADD COLUMN IF NOT EXISTS `portal_status` varchar(20) DEFAULT NULL;
ALTER TABLE `invoices` ADD COLUMN IF NOT EXISTS `portal_ref` varchar(80) DEFAULT NULL;
ALTER TABLE `invoices` ADD COLUMN IF NOT EXISTS `portal_at` date DEFAULT NULL;
ALTER TABLE `invoices` ADD COLUMN IF NOT EXISTS `portal_note` varchar(255) DEFAULT NULL;
CREATE TABLE IF NOT EXISTS `client_signatures` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `kind` varchar(20) NOT NULL,
  `title` varchar(190) NOT NULL,
  `body` text NOT NULL,
  `loan_id` int(10) unsigned DEFAULT NULL,
  `signer_role` varchar(20) NOT NULL DEFAULT 'Client',
  `signer_name` varchar(120) NOT NULL,
  `signer_email` varchar(190) NOT NULL,
  `token` char(48) NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `signed_at` datetime DEFAULT NULL,
  `signed_name` varchar(120) DEFAULT NULL,
  `sign_ip` varchar(45) DEFAULT NULL,
  `withdrawn_at` datetime DEFAULT NULL,
  `withdrawn_by` int(10) unsigned DEFAULT NULL,
  `lang` char(2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `client_signatures_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The portal tracker counts authorizations and invoices from the day this runs (an earlier run keeps its day):
-- the ones before were dealt with before it existed.
INSERT IGNORE INTO `settings` (`name`, `value`) VALUES ('vr_portal_since', CURDATE());
INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.23.0') ON DUPLICATE KEY UPDATE `value` = '4.23.0';
