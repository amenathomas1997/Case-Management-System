-- 4.33.0: a staff member's sessions can go to a calendar of three kinds: Microsoft (a work or a
-- personal Outlook.com account), a CalDAV server (iCloud, Nextcloud, cPanel, Fastmail) or Google
-- Calendar through a service account. Safe to run again.
-- The CalDAV or Google calendar a person connected (My Account). The password or key is kept
-- encrypted with the meeting_tokens.key file, like the meeting accounts, never in the clear.
CREATE TABLE IF NOT EXISTS `calendar_accounts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `kind` varchar(20) NOT NULL,
  `label` varchar(190) DEFAULT NULL,
  `server_url` varchar(500) DEFAULT NULL,
  `username` varchar(190) DEFAULT NULL,
  `secret` text NOT NULL,
  `calendar_url` varchar(500) DEFAULT NULL,
  `access_token` text DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `fk_calacc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- Which kind of calendar the person's sessions go to: microsoft, caldav or google.
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `calendar_kind` varchar(20) DEFAULT NULL AFTER `calendar_error`;
-- Anyone whose sessions already went to Outlook keeps going there.
UPDATE `users` SET `calendar_kind` = 'microsoft' WHERE `calendar_sync` = 1 AND `calendar_kind` IS NULL;
-- Which kind of calendar each event was made in (the event id's shape differs by kind).
ALTER TABLE `calendar_links` ADD COLUMN IF NOT EXISTS `kind` varchar(20) NOT NULL DEFAULT 'microsoft' AFTER `event_id`;

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.33.0') ON DUPLICATE KEY UPDATE `value` = '4.33.0';
