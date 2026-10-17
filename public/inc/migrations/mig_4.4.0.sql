-- 4.4.0: lists and time limits set on screen. No table changes (they are settings). Safe to run again.
INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.4.0') ON DUPLICATE KEY UPDATE `value` = '4.4.0';
