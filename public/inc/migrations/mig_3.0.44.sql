-- 3.0.44: amendment requests followed to their reply; On Hold with a reason and a return
-- date. Requires MariaDB 10.4 or newer. Safe to run again.

-- Why a client is On Hold, and when they are expected back (owner decision: On Hold, with a
-- reason, is the "waiting for authorization" stage).
ALTER TABLE `clients`
  ADD COLUMN IF NOT EXISTS `on_hold_reason` varchar(255) DEFAULT NULL AFTER `hold_reason`,
  ADD COLUMN IF NOT EXISTS `on_hold_until` date DEFAULT NULL AFTER `on_hold_reason`;

-- An authorization amendment request: when it was sent and to whom, what was asked for,
-- the letter, the reply, and the auth_amendments row the approved change was entered as.
CREATE TABLE IF NOT EXISTS `amendment_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `track_id` int(10) unsigned NOT NULL,
  `client_id` int(10) unsigned NOT NULL,
  `requested_on` date NOT NULL,
  `sent_to` varchar(190) DEFAULT NULL,
  `hours_requested` decimal(7,2) DEFAULT NULL,
  `amount_requested` decimal(10,2) DEFAULT NULL,
  `new_end_date` date DEFAULT NULL,
  `letter` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Awaiting reply',
  `replied_on` date DEFAULT NULL,
  `reply_note` varchar(255) DEFAULT NULL,
  `amendment_id` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `track_id` (`track_id`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `fk_amendreq_track` FOREIGN KEY (`track_id`) REFERENCES `training_tracks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_amendreq_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
