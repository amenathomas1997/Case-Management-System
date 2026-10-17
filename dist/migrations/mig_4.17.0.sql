-- 4.17.0: text messages (SMS). Clients' consent to texts, the text log with delivery status,
-- texts that wait for daytime, staff mobile numbers for urgent alerts, a client's reply to a
-- reminder, and whether a portal message came by text. Requires MariaDB 10.4 or newer. Safe to run again.
CREATE TABLE IF NOT EXISTS `sms_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `direction` varchar(3) NOT NULL,
  `client_id` int(10) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `session_id` int(10) unsigned DEFAULT NULL,
  `phone` varchar(20) NOT NULL,
  `body` text NOT NULL,
  `purpose` varchar(20) NOT NULL,
  `status` varchar(20) NOT NULL,
  `provider_sid` varchar(64) DEFAULT NULL,
  `error` varchar(255) DEFAULT NULL,
  `send_after` datetime DEFAULT NULL,
  `sent_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `provider_sid` (`provider_sid`),
  KEY `client` (`client_id`,`created_at`),
  KEY `held` (`status`,`send_after`),
  CONSTRAINT `fk_sms_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `clients` ADD COLUMN IF NOT EXISTS `sms_consent_at` datetime DEFAULT NULL;
ALTER TABLE `clients` ADD COLUMN IF NOT EXISTS `sms_consent_source` varchar(60) DEFAULT NULL;
ALTER TABLE `clients` ADD COLUMN IF NOT EXISTS `sms_consent_by` int(10) unsigned DEFAULT NULL;
ALTER TABLE `clients` ADD COLUMN IF NOT EXISTS `sms_opted_out_at` datetime DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `mobile` varchar(20) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `alert_sms` tinyint(1) NOT NULL DEFAULT 0;
ALTER TABLE `scheduled_sessions` ADD COLUMN IF NOT EXISTS `sms_reminder_sent` tinyint(1) NOT NULL DEFAULT 0;
ALTER TABLE `scheduled_sessions` ADD COLUMN IF NOT EXISTS `client_reply` varchar(20) DEFAULT NULL;
ALTER TABLE `scheduled_sessions` ADD COLUMN IF NOT EXISTS `client_reply_at` datetime DEFAULT NULL;
ALTER TABLE `homework` ADD COLUMN IF NOT EXISTS `sms_notified` tinyint(1) NOT NULL DEFAULT 0;
-- Homework assigned before this release is never texted about.
UPDATE `homework` SET `sms_notified` = 1 WHERE `sms_notified` = 0 AND `assigned_at` < CURDATE();
ALTER TABLE `messages` ADD COLUMN IF NOT EXISTS `via` varchar(10) NOT NULL DEFAULT 'portal';
-- A message the phone apps send again after a lost reply is posted (and texted) once.
ALTER TABLE `messages` ADD COLUMN IF NOT EXISTS `idem_key` char(36) DEFAULT NULL;
ALTER TABLE `messages` ADD INDEX IF NOT EXISTS `idx_idem` (`staff_id`,`idem_key`);
-- The referral form's "OK to text this number" (carried to the client it becomes).
ALTER TABLE `referrals` ADD COLUMN IF NOT EXISTS `sms_ok` tinyint(1) NOT NULL DEFAULT 0;

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.17.0') ON DUPLICATE KEY UPDATE `value` = '4.17.0';
