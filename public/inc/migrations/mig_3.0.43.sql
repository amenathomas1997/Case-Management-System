-- 3.0.43: move a session, cancel the rest of a series or a whole group. Requires MariaDB
-- 10.4 or newer. Safe to run again.

-- series_uid: the sessions of one weekly series (booked together). ics_sequence: how many
-- times the session was moved, so calendars take the newest invite.
ALTER TABLE `scheduled_sessions`
  ADD COLUMN IF NOT EXISTS `series_uid` char(32) DEFAULT NULL AFTER `group_uid`,
  ADD COLUMN IF NOT EXISTS `ics_sequence` smallint(5) unsigned NOT NULL DEFAULT 0 AFTER `series_uid`,
  ADD KEY IF NOT EXISTS `series_uid` (`series_uid`);
