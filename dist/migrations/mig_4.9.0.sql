-- 4.9.0: booking several sessions at once (several days a week, a list of dates, or the whole authorization). No table changes. Safe to run again.
INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.9.0') ON DUPLICATE KEY UPDATE `value` = '4.9.0';
