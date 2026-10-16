-- 4.0.0: voice, alerts outside the website, and the iPhone app's new screens. Requires
-- MariaDB 10.4 or newer. Safe to run again.

-- Whether each person is emailed about alerts: urgent ones (a client's portal request or
-- message, or a call for help) at once plus a daily count, a daily count only, or none
-- (the default, so nobody gets new email without choosing it). The email never names a
-- client: it says to sign in.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `alert_email` varchar(10) NOT NULL DEFAULT 'off' AFTER `privacy_training_on`;

-- The equipment hand-off: the item's condition when it went out and came back, and the
-- client's typed name accepting the loan agreement, with when.
ALTER TABLE `equipment_loans`
  ADD COLUMN IF NOT EXISTS `condition_out` varchar(20) DEFAULT NULL AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `condition_in` varchar(20) DEFAULT NULL AFTER `condition_out`,
  ADD COLUMN IF NOT EXISTS `accepted_name` varchar(120) DEFAULT NULL AFTER `condition_in`,
  ADD COLUMN IF NOT EXISTS `accepted_at` datetime DEFAULT NULL AFTER `accepted_name`;

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.0.0') ON DUPLICATE KEY UPDATE `value` = '4.0.0';
