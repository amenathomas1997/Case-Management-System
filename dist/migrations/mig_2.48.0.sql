-- v2.48.0 — Telepractice / remote sessions.
-- Safe to re-run (MariaDB IF NOT EXISTS).
ALTER TABLE `scheduled_sessions`
  ADD COLUMN IF NOT EXISTS `is_remote` tinyint(1) NOT NULL DEFAULT 0 AFTER `location`,
  ADD COLUMN IF NOT EXISTS `meeting_url` varchar(500) DEFAULT NULL AFTER `is_remote`;
