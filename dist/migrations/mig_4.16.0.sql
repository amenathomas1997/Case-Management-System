-- 4.16.0: staff training with a quiz (privacy training), holding back client records until
-- required agreements and training are done, staff credentials with expiry dates, the exit
-- acknowledgement, and the staff compliance report. Requires MariaDB 10.4 or newer. Safe to run again.
CREATE TABLE IF NOT EXISTS `training_modules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(190) NOT NULL,
  `body` mediumtext NOT NULL,
  `questions` mediumtext NOT NULL,
  `pass_pct` tinyint(3) unsigned NOT NULL DEFAULT 80,
  `version` int(10) unsigned NOT NULL DEFAULT 1,
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `required` tinyint(1) NOT NULL DEFAULT 1,
  `counts_as_privacy` tinyint(1) NOT NULL DEFAULT 0,
  `activated_at` datetime DEFAULT NULL,
  `version_at` datetime DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `training_completions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `module_id` int(10) unsigned NOT NULL,
  `version` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `title` varchar(190) NOT NULL,
  `score` smallint(5) unsigned NOT NULL,
  `total` smallint(5) unsigned NOT NULL,
  `passed` tinyint(1) NOT NULL,
  `answers` mediumtext NOT NULL,
  `via` varchar(20) NOT NULL DEFAULT 'website',
  `completed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_module` (`user_id`,`module_id`,`completed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `staff_credentials` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `number` varchar(80) DEFAULT NULL,
  `issuer` varchar(120) DEFAULT NULL,
  `issued_on` date DEFAULT NULL,
  `expires_on` date DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `added_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_expires` (`user_id`,`expires_on`),
  CONSTRAINT `staff_credentials_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- An agreement is for all staff, or the exit acknowledgement asked of someone leaving.
ALTER TABLE `staff_agreements` ADD COLUMN IF NOT EXISTS `kind` varchar(10) NOT NULL DEFAULT 'staff';
ALTER TABLE `staff_agreements` ADD COLUMN IF NOT EXISTS `activated_at` datetime DEFAULT NULL;
ALTER TABLE `staff_agreements` ADD COLUMN IF NOT EXISTS `version_at` datetime DEFAULT NULL;
UPDATE `staff_agreements` SET `activated_at` = `updated_at` WHERE `active` = 1 AND `activated_at` IS NULL;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `exit_requested_at` datetime DEFAULT NULL;

INSERT INTO `staff_agreements` (`title`, `body`, `kind`, `active`, `counts_as_confidentiality`)
SELECT 'Exit acknowledgement', 'Edit this text before turning the agreement on.
# Before I leave
- I have returned every device, key and badge that belongs to the organisation.
- I have deleted client information from any personal phone, computer or account.
- I have handed over my open work as my supervisor asked.
# After I leave
- My duty to keep client information confidential continues.', 'exit', 0, 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `staff_agreements` WHERE `kind` = 'exit');

INSERT INTO `training_modules` (`title`, `body`, `questions`, `counts_as_privacy`)
SELECT 'Privacy training', 'Edit this text before turning the training on.
# Why privacy matters
Clients trust us with their health, disability and work information. Protecting it is part of every job here.
# The rules
- Look at a client record only when your work needs it.
- Share client information only as the client''s consent, a release of information or the law allows.
- Never send client details by text message or to a personal email address.
- Lock your computer and phone whenever you step away.
# If something goes wrong
Report a lost phone, a wrong email, or anything that might expose client information to your supervisor straight away.', '? When may you open a client''s record?
- Whenever you are curious
* When your work needs it
- When a friend asks about them
? A counselor you don''t know phones asking about a client. What do you do?
- Tell them what they ask, since they work in VR
* Check there is a release of information or consent before sharing anything
- Read them the case notes
? You lose your work phone. What do you do?
- Wait a week in case it turns up
* Report it straight away
- Nothing, the app is locked', 1
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `training_modules`);

-- New staff wait for client records until they have signed and passed (changed on Staff training).
INSERT IGNORE INTO `settings` (`name`, `value`) VALUES ('access_hold', 'new');

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.16.0') ON DUPLICATE KEY UPDATE `value` = '4.16.0';
