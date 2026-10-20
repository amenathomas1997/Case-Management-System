-- 4.31.0: confirm a session, land in its case note. Safe to run again.
-- The session a case note was written for (set by the website's note editor in session mode
-- and by the app's add_note with session_id). A session removed later leaves the note unlinked.
ALTER TABLE `client_notes` ADD COLUMN IF NOT EXISTS `session_id` int(10) unsigned DEFAULT NULL AFTER `addendum_to`;
ALTER TABLE `client_notes` ADD KEY IF NOT EXISTS `idx_note_session` (`session_id`);
ALTER TABLE `client_notes` DROP FOREIGN KEY IF EXISTS `fk_note_session`;
ALTER TABLE `client_notes` ADD CONSTRAINT `fk_note_session` FOREIGN KEY (`session_id`) REFERENCES `scheduled_sessions` (`id`) ON DELETE SET NULL;
-- A completed session whose case note is not written yet (1 from completion until a note
-- linked to it is saved). Sessions completed before 4.31.0 stay 0: no backfill.
ALTER TABLE `scheduled_sessions` ADD COLUMN IF NOT EXISTS `note_pending` tinyint(1) NOT NULL DEFAULT 0 AFTER `work_log_id`;
-- When the note became due (the scheduled job adds the reminder task an hour later if it is still unwritten).
ALTER TABLE `scheduled_sessions` ADD COLUMN IF NOT EXISTS `note_pending_since` datetime DEFAULT NULL AFTER `note_pending`;
ALTER TABLE `scheduled_sessions` ADD KEY IF NOT EXISTS `idx_note_pending` (`note_pending`, `user_id`);


INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.31.0') ON DUPLICATE KEY UPDATE `value` = '4.31.0';
