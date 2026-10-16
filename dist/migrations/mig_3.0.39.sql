-- 3.0.39: one phone at a time. Requires MariaDB 10.4 or newer. Safe to run again.

-- Each app session names its phone, so one can be signed out, or reported lost: its next
-- request tells the app to remove what it keeps on the phone, and the session ends.
ALTER TABLE `api_tokens`
  ADD COLUMN IF NOT EXISTS `device_name` varchar(120) DEFAULT NULL AFTER `expires_at`,
  ADD COLUMN IF NOT EXISTS `wipe_requested_at` datetime DEFAULT NULL AFTER `device_name`,
  ADD COLUMN IF NOT EXISTS `wiped_at` datetime DEFAULT NULL AFTER `wipe_requested_at`;
