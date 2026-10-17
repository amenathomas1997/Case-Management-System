-- 4.7.0: saved custom reports. Requires MariaDB 10.4 or newer. Safe to run again.
CREATE TABLE IF NOT EXISTS `saved_reports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `owner_id` int(10) unsigned NOT NULL,
  `shared` tinyint(1) NOT NULL DEFAULT 0,
  `definition` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `owner_id` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.7.0') ON DUPLICATE KEY UPDATE `value` = '4.7.0';
