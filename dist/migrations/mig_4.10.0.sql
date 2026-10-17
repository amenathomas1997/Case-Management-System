-- 4.10.0: each staff member's own Zoom and Microsoft (Teams) connection, for making meeting
-- links. The tokens are encrypted with a key kept outside the database. Requires MariaDB 10.4
-- or newer. Safe to run again.
CREATE TABLE IF NOT EXISTS `meeting_accounts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `provider` varchar(20) NOT NULL,
  `account_email` varchar(190) DEFAULT NULL,
  `access_token` text NOT NULL,
  `refresh_token` text NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_provider` (`user_id`,`provider`),
  CONSTRAINT `meeting_accounts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.10.0') ON DUPLICATE KEY UPDATE `value` = '4.10.0';
