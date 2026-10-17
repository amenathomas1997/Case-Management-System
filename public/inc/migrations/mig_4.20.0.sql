-- 4.20.0: two-way Outlook calendar sync and client self-booking. Each staff member's sessions go to
-- their own Outlook calendar (calendar_links remembers which event is which session); their Outlook
-- busy times are kept (calendar_busy) so the system never books over them; and the hours they take
-- bookings (staff_availability) let clients book their own sessions in the portal. Safe to run again.
CREATE TABLE IF NOT EXISTS `calendar_links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `event_id` varchar(255) NOT NULL,
  `pushed_hash` char(40) NOT NULL,
  `event_start` datetime NOT NULL,
  `event_end` datetime NOT NULL,
  `outlook_change` varchar(20) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_user` (`session_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_callink_session` FOREIGN KEY (`session_id`) REFERENCES `scheduled_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_callink_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `calendar_busy` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_start` (`user_id`,`start_at`),
  CONSTRAINT `fk_calbusy_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `staff_availability` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `weekday` tinyint(3) unsigned NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_remote` tinyint(1) NOT NULL DEFAULT 1,
  `location` varchar(190) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_day` (`user_id`,`weekday`),
  CONSTRAINT `fk_avail_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `calendar_sync` tinyint(1) NOT NULL DEFAULT 0;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `calendar_synced_at` datetime DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `calendar_error` varchar(255) DEFAULT NULL;
-- What each person granted their Microsoft connection (renewals ask for that only).
ALTER TABLE `meeting_accounts` ADD COLUMN IF NOT EXISTS `granted_scope` varchar(500) DEFAULT NULL;
-- Who booked a session: staff (NULL) or the client themselves in the portal.
ALTER TABLE `scheduled_sessions` ADD COLUMN IF NOT EXISTS `booked_by_client` tinyint(1) NOT NULL DEFAULT 0;

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.20.0') ON DUPLICATE KEY UPDATE `value` = '4.20.0';
