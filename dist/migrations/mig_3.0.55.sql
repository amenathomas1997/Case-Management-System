-- 3.0.55: staff accounts. Requires MariaDB 10.4 or newer. Safe to run again.

-- When each person was invited, and their confidentiality agreement and
-- privacy training dates (each renewed yearly).
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `invited_at` datetime DEFAULT NULL AFTER `cal_token`,
  ADD COLUMN IF NOT EXISTS `confidentiality_signed_on` date DEFAULT NULL AFTER `invited_at`,
  ADD COLUMN IF NOT EXISTS `privacy_training_on` date DEFAULT NULL AFTER `confidentiality_signed_on`;

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '3.0.55') ON DUPLICATE KEY UPDATE `value` = '3.0.55';
