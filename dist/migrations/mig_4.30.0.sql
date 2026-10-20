-- 4.30.0: accessibility fixes. Safe to run again.
-- The language a survey was answered in, so a Spanish comment is marked lang="es" for screen readers.
ALTER TABLE `surveys` ADD COLUMN IF NOT EXISTS `answer_lang` varchar(5) DEFAULT NULL AFTER `channel`;

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.30.0') ON DUPLICATE KEY UPDATE `value` = '4.30.0';
