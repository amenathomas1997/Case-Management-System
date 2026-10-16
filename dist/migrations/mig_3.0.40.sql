-- 3.0.40: portal links that renew while they are used and warn before they lapse.
-- Requires MariaDB 10.4 or newer. Safe to run again.

-- When the holder was last warned that the link is about to stop working (cleared when
-- the link is used, which also keeps it open for another 180 days).
ALTER TABLE `portal_tokens` ADD COLUMN IF NOT EXISTS `expiry_warned_at` datetime DEFAULT NULL AFTER `expires_at`;
ALTER TABLE `vrc_tokens` ADD COLUMN IF NOT EXISTS `expiry_warned_at` datetime DEFAULT NULL AFTER `expires_at`;
