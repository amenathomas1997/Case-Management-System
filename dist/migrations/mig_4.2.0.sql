-- 4.2.0: pricing rules set on screen. Requires MariaDB 10.4 or newer. Safe to run again.

-- Whether the person said they have an open case with a VR agency (self-referrals, or every
-- referrer the Pricing rules page chooses), which decides the rate they are quoted.
ALTER TABLE `referrals`
  ADD COLUMN IF NOT EXISTS `vr_case_open` enum('yes','no','unsure') DEFAULT NULL AFTER `income_bracket`;

-- Each VR agency's own rates (for example Iowa $40, Missouri $60, Minnesota $80 an hour),
-- used for a new track when its authorization does not give one.
ALTER TABLE `funding_agencies`
  ADD COLUMN IF NOT EXISTS `hourly_rate` decimal(8,2) DEFAULT NULL AFTER `terms_days`,
  ADD COLUMN IF NOT EXISTS `mileage_rate` decimal(6,3) DEFAULT NULL AFTER `hourly_rate`;

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.2.0') ON DUPLICATE KEY UPDATE `value` = '4.2.0';
