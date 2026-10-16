-- 3.0.48: authorization emails read into new tracks. Requires MariaDB 10.4 or newer.
-- Safe to run again.

-- Each approved-authorization email pasted in or forwarded to the CMS mailbox, with the
-- details read from it, until staff create the track or dismiss it.
CREATE TABLE IF NOT EXISTS `incoming_authorizations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `auth_number` varchar(80) NOT NULL,
  `client_name` varchar(190) DEFAULT NULL,
  `service_category` varchar(190) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `hours` decimal(7,2) DEFAULT NULL,
  `approved_on` date DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `counselor_name` varchar(120) DEFAULT NULL,
  `counselor_email` varchar(190) DEFAULT NULL,
  `agency` varchar(190) DEFAULT NULL,
  `email_text` mediumtext DEFAULT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'Pasted',
  `message_key` char(64) DEFAULT NULL,
  `client_id` int(10) unsigned DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'New',
  `track_id` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `handled_by` int(10) unsigned DEFAULT NULL,
  `handled_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `message_key` (`message_key`),
  KEY `idx_status` (`status`),
  KEY `auth_number` (`auth_number`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `fk_incauth_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
