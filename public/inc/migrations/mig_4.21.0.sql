-- 4.21.0: the AT Act report and QuickBooks Online. Each device gets an AT Act type; each loan says why it
-- was lent, who borrowed it and what was decided; and invoices remember their QuickBooks copy, with the
-- connection to the organisation's QuickBooks company. Safe to run again.
ALTER TABLE `equipment` ADD COLUMN IF NOT EXISTS `at_type` varchar(60) DEFAULT NULL;
ALTER TABLE `equipment_loans` ADD COLUMN IF NOT EXISTS `purpose` varchar(20) DEFAULT NULL;
ALTER TABLE `equipment_loans` ADD COLUMN IF NOT EXISTS `borrower_type` varchar(20) DEFAULT NULL;
ALTER TABLE `equipment_loans` ADD COLUMN IF NOT EXISTS `decision` varchar(20) DEFAULT NULL;
ALTER TABLE `invoices` ADD COLUMN IF NOT EXISTS `qbo_id` varchar(40) DEFAULT NULL;
ALTER TABLE `invoices` ADD COLUMN IF NOT EXISTS `qbo_synced_at` datetime DEFAULT NULL;
ALTER TABLE `invoices` ADD COLUMN IF NOT EXISTS `qbo_error` varchar(255) DEFAULT NULL;
-- Its state there once sent: NULL (open, checked for payments), 'voided' (voided there after it was voided or denied here), 'paid', or 'missing'.
ALTER TABLE `invoices` ADD COLUMN IF NOT EXISTS `qbo_status` varchar(20) DEFAULT NULL;
-- A payment recorded here, once it is in QuickBooks too.
ALTER TABLE `invoice_payments` ADD COLUMN IF NOT EXISTS `qbo_id` varchar(40) DEFAULT NULL;
CREATE TABLE IF NOT EXISTS `qbo_connection` (
  `id` tinyint(3) unsigned NOT NULL,
  `realm_id` varchar(40) NOT NULL,
  `sandbox` tinyint(1) NOT NULL DEFAULT 0,
  `company_name` varchar(190) DEFAULT NULL,
  `access_token` text NOT NULL,
  `refresh_token` text NOT NULL,
  `expires_at` datetime NOT NULL,
  `refresh_expires_at` datetime DEFAULT NULL,
  `connected_by` int(10) unsigned DEFAULT NULL,
  `connected_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `qbo_customers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name_key` char(40) NOT NULL,
  `display_name` varchar(190) NOT NULL,
  `qbo_id` varchar(40) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_key` (`name_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.21.0') ON DUPLICATE KEY UPDATE `value` = '4.21.0';
