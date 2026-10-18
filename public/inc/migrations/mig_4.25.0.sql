-- 4.25.0: signing in person (a document signed on a staff member's phone, tablet or computer with the client
-- there, by typed name and, from the apps, a drawn signature).
-- Safe to run again.
ALTER TABLE `client_signatures` ADD COLUMN IF NOT EXISTS `method` varchar(20) NOT NULL DEFAULT 'Email';
ALTER TABLE `client_signatures` ADD COLUMN IF NOT EXISTS `signature_path` varchar(255) DEFAULT NULL;

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.25.0') ON DUPLICATE KEY UPDATE `value` = '4.25.0';
