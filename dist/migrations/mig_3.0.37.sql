-- 3.0.37: privacy follow-ups. Requires MariaDB 10.4 or newer. Safe to run again: every
-- statement adds only what is missing. Nothing is deleted.

-- When and how the client was given the privacy notice, and a hold that keeps the record
-- from being archived, purged or emptied from the trash (who placed it, when and why;
-- each place and lift is also in the audit log).
ALTER TABLE `clients`
  ADD COLUMN IF NOT EXISTS `privacy_notice_at` datetime DEFAULT NULL AFTER `guardian_email`,
  ADD COLUMN IF NOT EXISTS `privacy_notice_by` int(10) unsigned DEFAULT NULL AFTER `privacy_notice_at`,
  ADD COLUMN IF NOT EXISTS `privacy_notice_how` varchar(30) DEFAULT NULL AFTER `privacy_notice_by`,
  ADD COLUMN IF NOT EXISTS `hold_at` datetime DEFAULT NULL AFTER `privacy_notice_how`,
  ADD COLUMN IF NOT EXISTS `hold_by` int(10) unsigned DEFAULT NULL AFTER `hold_at`,
  ADD COLUMN IF NOT EXISTS `hold_reason` varchar(255) DEFAULT NULL AFTER `hold_by`;

-- The audit log is searched by client (file views are now recorded).
ALTER TABLE `audit_log` ADD KEY IF NOT EXISTS `idx_entity` (`entity`, `entity_id`);
