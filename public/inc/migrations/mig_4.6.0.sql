-- 4.6.0: reminder and review windows and a default mileage rate set on screen. No table changes. Safe to run again.
INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.6.0') ON DUPLICATE KEY UPDATE `value` = '4.6.0';
