-- v2.53.0 — Secure two-way threaded messaging (staff <-> client, staff <-> counselor).
CREATE TABLE IF NOT EXISTS `messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `channel` enum('client','counselor') NOT NULL,
  `sender` enum('staff','client','counselor') NOT NULL,
  `staff_id` int(10) unsigned DEFAULT NULL,
  `body` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_thread` (`client_id`,`channel`,`created_at`),
  KEY `idx_unread` (`channel`,`read_at`),
  CONSTRAINT `fk_msg_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
