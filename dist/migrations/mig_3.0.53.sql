-- 3.0.53: running the system safely (backups, restore check, mail log). Requires MariaDB 10.4
-- or newer. Safe to run again.

-- Every email the system sends: when, to what kind of recipient, by which route, and whether
-- it went. No address, subject or content is kept.
CREATE TABLE IF NOT EXISTS `mail_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `recipient_type` varchar(20) NOT NULL DEFAULT 'other',
  `via` varchar(10) NOT NULL DEFAULT 'graph',
  `ok` tinyint(1) NOT NULL DEFAULT 0,
  `with_attachment` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
