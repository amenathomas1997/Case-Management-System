-- v2.62.0 — per-track case notes: optionally attach a client_note to a training track.
-- Safe to re-run (MariaDB IF NOT EXISTS). The foreign key is its own statement,
-- last in the file, so the column and index are in place whatever happens to it.
ALTER TABLE `client_notes`
  ADD COLUMN IF NOT EXISTS `track_id` int(10) unsigned DEFAULT NULL AFTER `client_id`,
  ADD KEY IF NOT EXISTS `idx_note_track` (`track_id`);
ALTER TABLE `client_notes`
  ADD CONSTRAINT `fk_note_track` FOREIGN KEY IF NOT EXISTS (`track_id`) REFERENCES `training_tracks` (`id`) ON DELETE SET NULL;
