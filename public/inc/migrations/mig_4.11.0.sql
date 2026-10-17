-- 4.11.0: client statuses, progress report schedules and referral sources become lists an
-- administrator can add to (Lists and time limits). The columns become text; every existing
-- value is kept. Requires MariaDB 10.4 or newer. Safe to run again.
ALTER TABLE `clients` MODIFY `status` varchar(40) NOT NULL DEFAULT 'Pending Assessment';
ALTER TABLE `training_tracks` MODIFY `report_cadence` varchar(40) NOT NULL DEFAULT 'monthly';
ALTER TABLE `referrals` MODIFY `referral_source` varchar(40) NOT NULL DEFAULT 'counselor';

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.11.0') ON DUPLICATE KEY UPDATE `value` = '4.11.0';
