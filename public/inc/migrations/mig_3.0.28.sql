-- 3.0.28: session check-in (the moment a client arrived for a scheduled session).
-- Requires MariaDB 10.4 or newer. Safe to run again: the statement only adds what
-- is missing (IF NOT EXISTS). Nothing is deleted and no existing row changes.

-- Set once by the check_in_session API action (and shown on the Schedule page as
-- "Arrived HH:MM"); NULL until the client is checked in. A second check-in is
-- refused, so the first arrival time is kept.
ALTER TABLE `scheduled_sessions`
  ADD COLUMN IF NOT EXISTS `checked_in_at` datetime DEFAULT NULL AFTER `group_uid`;
