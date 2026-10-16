-- 3.0.51: portal history, report copies and practice feedback. Requires MariaDB 10.4 or newer.
-- Safe to run again.

-- Staff feedback on a client's practice update, shown to the client in their portal.
ALTER TABLE `homework_practice`
  ADD COLUMN IF NOT EXISTS `staff_feedback` varchar(2000) DEFAULT NULL AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `feedback_by` int(10) unsigned DEFAULT NULL AFTER `staff_feedback`,
  ADD COLUMN IF NOT EXISTS `feedback_at` datetime DEFAULT NULL AFTER `feedback_by`;
