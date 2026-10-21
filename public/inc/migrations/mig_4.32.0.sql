-- 4.32.0: online meeting connections that end are shown and renewed. Safe to run again.
-- When Zoom or Microsoft last refused to renew this connection (the person removed the app,
-- or it went unused too long). Set by the renewal; cleared when the person connects again.
ALTER TABLE `meeting_accounts` ADD COLUMN IF NOT EXISTS `refused_at` datetime DEFAULT NULL AFTER `granted_scope`;
-- The start time a single meeting link was made for by Zoom or Teams (a link someone typed, or
-- one recurring meeting for a series, has none): a session moved more than 25 days past it gets
-- a new link, since a single meeting expires some weeks after its time.
ALTER TABLE `scheduled_sessions` ADD COLUMN IF NOT EXISTS `meeting_made_for` datetime DEFAULT NULL AFTER `meeting_url`;


INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.32.0') ON DUPLICATE KEY UPDATE `value` = '4.32.0';
