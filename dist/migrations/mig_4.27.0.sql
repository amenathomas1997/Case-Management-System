-- 4.27.0: fixes from the 2026-10-18 code audit. Safe to run again.
-- A per-user session epoch: removing a passkey, an administrator's two-step reset and an
-- administrator's change of email add 1, which ends that account's other browser sessions.
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `session_epoch` int(11) NOT NULL DEFAULT 0;
-- The address Microsoft 365 authenticated as the sender of a mailbox authorization email: only
-- a counselor address equal to it is taken as confirmed.
ALTER TABLE `incoming_authorizations` ADD COLUMN IF NOT EXISTS `sender_email` varchar(190) DEFAULT NULL AFTER `counselor_email`;
-- Session reminders recorded per recipient, so a failure for one never re-sends to the other.
ALTER TABLE `scheduled_sessions` ADD COLUMN IF NOT EXISTS `client_reminded_at` datetime DEFAULT NULL;
ALTER TABLE `scheduled_sessions` ADD COLUMN IF NOT EXISTS `staff_reminded_at` datetime DEFAULT NULL;

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.27.0') ON DUPLICATE KEY UPDATE `value` = '4.27.0';
