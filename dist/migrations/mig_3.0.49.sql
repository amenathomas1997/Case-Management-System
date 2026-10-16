-- 3.0.49: reports and data quality. Requires MariaDB 10.4 or newer. Safe to run again.

-- When a track was marked Completed or Cancelled, so the unused-authorization report can
-- tell which authorizations ended in a period. Tracks already ended get the date of their
-- last logged work (or the day the track was created when none was logged).
ALTER TABLE `training_tracks`
  ADD COLUMN IF NOT EXISTS `completed_at` datetime DEFAULT NULL AFTER `billing_mode`;
UPDATE `training_tracks` t SET t.completed_at = COALESCE((SELECT MAX(w.work_date) FROM work_logs w WHERE w.track_id = t.id), t.created_at)
  WHERE t.status IN ('Completed', 'Cancelled') AND t.completed_at IS NULL;
