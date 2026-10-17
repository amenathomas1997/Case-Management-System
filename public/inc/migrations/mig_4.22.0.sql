-- 4.22.0: offices within one organisation. Each office has its own address, phone and time zone;
-- staff and clients belong to an office, lists filter by it, and a client with no state or time
-- zone of their own follows their office's time zone. Safe to run again.
CREATE TABLE IF NOT EXISTS `offices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `time_zone` varchar(40) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `office_id` int(10) unsigned DEFAULT NULL;
ALTER TABLE `clients` ADD COLUMN IF NOT EXISTS `office_id` int(10) unsigned DEFAULT NULL;
ALTER TABLE `clients` ADD INDEX IF NOT EXISTS `idx_office` (`office_id`);
ALTER TABLE `users` ADD INDEX IF NOT EXISTS `idx_office` (`office_id`);

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.22.0') ON DUPLICATE KEY UPDATE `value` = '4.22.0';
