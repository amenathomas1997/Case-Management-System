-- 4.8.0: more of the iPhone app works without a signal. Requires MariaDB 10.4 or newer. Safe to run again.

-- A goal progress entry sent again from the phone (its offline queue, or a lost reply) is
-- recognised by the key the phone gave it, and recorded once.
ALTER TABLE `goal_progress`
  ADD COLUMN IF NOT EXISTS `idem_key` char(36) DEFAULT NULL AFTER `recorded_by`,
  ADD UNIQUE KEY IF NOT EXISTS `idem` (`recorded_by`, `idem_key`);

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.8.0') ON DUPLICATE KEY UPDATE `value` = '4.8.0';
