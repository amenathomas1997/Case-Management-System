-- 3.0.52: times offered to a client on a portal request. Requires MariaDB 10.4 or newer.
-- Safe to run again.

-- Two or three times staff offer for an appointment or reschedule request, each checked
-- against the instructor's calendar and the authorization; the client confirms one in the
-- portal, which books it (or moves the session) and closes the request.
CREATE TABLE IF NOT EXISTS `portal_request_offers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int(10) unsigned NOT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `track_id` int(10) unsigned DEFAULT NULL,
  `is_remote` tinyint(1) NOT NULL DEFAULT 0,
  `location` varchar(190) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Offered',
  `session_id` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `chosen_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`, `status`),
  CONSTRAINT `fk_offer_request` FOREIGN KEY (`request_id`) REFERENCES `portal_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
