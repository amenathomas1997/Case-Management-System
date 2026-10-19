-- v3.0.8 — catch-up and hardening. Requires MariaDB 10.4 or newer.
-- Safe to re-run: every statement only adds what is missing (IF NOT EXISTS, a
-- MODIFY that restates the same definition, or a WHERE that matches nothing the
-- second time). Nothing is deleted. Two column defaults change (training_tracks
-- funding_agency and hourly_rate, section 4). The only rows changed are the built-in
-- Administrators group's permissions; while it still has the shipped wording,
-- the built-in Billing & Reporting group's description; equipment notes written
-- before 3.0.8 that name a client (the name is removed); and device follow-ups
-- still pending for a loan already returned or for an AT repair loaner (closed
-- as Skipped).
-- Run it on every existing installation, after the earlier files in this folder.

-- ---- 1. Built-in Administrators group: the two newer permission keys ----
-- 'programs' (Pre-ETS & group programs) and 'procurement' (device funding) were
-- added to the app after this group was seeded, so an administrator who signs in
-- through the group was turned away from those two pages. Only the group named
-- 'Administrators' is touched, and only when the key is not already listed.
UPDATE `groups`
   SET `perms` = TRIM(BOTH ';' FROM CONCAT(`perms`, ';programs'))
 WHERE `name` = 'Administrators'
   AND FIND_IN_SET('programs', REPLACE(REPLACE(`perms`, ' ', ''), ';', ',')) = 0;
UPDATE `groups`
   SET `perms` = TRIM(BOTH ';' FROM CONCAT(`perms`, ';procurement'))
 WHERE `name` = 'Administrators'
   AND FIND_IN_SET('procurement', REPLACE(REPLACE(`perms`, ' ', ''), ';', ',')) = 0;

-- ---- 2. Objects the app has used since v2.4–v2.40 that never had a migration ----
-- An installation made from an early package and upgraded only with the files in
-- this folder is missing them; everywhere else these statements do nothing.

-- Client self-service requests from the portal (v2.36).
CREATE TABLE IF NOT EXISTS `portal_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `type` varchar(20) NOT NULL,
  `session_id` int(10) unsigned DEFAULT NULL,
  `details` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'New',
  `handled_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  KEY `status` (`status`),
  CONSTRAINT `preq_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Structured case notes (v2.39–v2.40) and the offline-replay key (v2.4).
ALTER TABLE `client_notes`
  ADD COLUMN IF NOT EXISTS `note_format` varchar(10) NOT NULL DEFAULT 'free' AFTER `note_type`,
  ADD COLUMN IF NOT EXISTS `birp_behavior` text DEFAULT NULL AFTER `body`,
  ADD COLUMN IF NOT EXISTS `birp_intervention` text DEFAULT NULL AFTER `birp_behavior`,
  ADD COLUMN IF NOT EXISTS `birp_response` text DEFAULT NULL AFTER `birp_intervention`,
  ADD COLUMN IF NOT EXISTS `birp_plan` text DEFAULT NULL AFTER `birp_response`,
  ADD COLUMN IF NOT EXISTS `idem_key` char(36) DEFAULT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS `uniq_idem` ON `client_notes` (`idem_key`);

-- Offline-replay key for logged hours (v2.4).
ALTER TABLE `work_logs`
  ADD COLUMN IF NOT EXISTS `idem_key` char(36) DEFAULT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS `uniq_idem` ON `work_logs` (`idem_key`);

-- ---- 3. Indexes for lookups that scanned the whole table ----
-- Caseload scoping and the client lists.
CREATE INDEX IF NOT EXISTS `idx_assigned_to` ON `clients` (`assigned_to`);
CREATE INDEX IF NOT EXISTS `idx_status` ON `clients` (`status`);
CREATE INDEX IF NOT EXISTS `idx_archived_at` ON `clients` (`archived_at`);
-- Schedule, capacity, calendar feed, reminders, group sessions.
CREATE INDEX IF NOT EXISTS `idx_user_start` ON `scheduled_sessions` (`user_id`, `start_at`);
CREATE INDEX IF NOT EXISTS `idx_start_at` ON `scheduled_sessions` (`start_at`);
CREATE INDEX IF NOT EXISTS `idx_track` ON `scheduled_sessions` (`track_id`);
CREATE INDEX IF NOT EXISTS `idx_group_uid` ON `scheduled_sessions` (`group_uid`);
-- Invoices and date-range reports.
CREATE INDEX IF NOT EXISTS `idx_invoice` ON `work_logs` (`invoice_id`);
CREATE INDEX IF NOT EXISTS `idx_work_date` ON `work_logs` (`work_date`);
CREATE INDEX IF NOT EXISTS `idx_invoice` ON `track_expenses` (`invoice_id`);
-- Token and identity lookups made on every calendar-feed, single-sign-on and
-- password-reset request (the reset hash is 64 characters, so a 64-character
-- prefix covers it exactly).
CREATE INDEX IF NOT EXISTS `idx_cal_token` ON `users` (`cal_token`);
CREATE INDEX IF NOT EXISTS `idx_entra_oid` ON `users` (`entra_oid`);
CREATE INDEX IF NOT EXISTS `idx_token_hash` ON `password_resets` (`token_hash`(64));
-- Report receipts per track, and the referral queue.
CREATE INDEX IF NOT EXISTS `idx_track` ON `report_acks` (`track_id`);
CREATE INDEX IF NOT EXISTS `idx_status` ON `referrals` (`status`);
CREATE INDEX IF NOT EXISTS `idx_assigned_to` ON `referrals` (`assigned_to`);
CREATE INDEX IF NOT EXISTS `idx_client` ON `referrals` (`client_id`);

-- ---- 4. Columns and values the 3.0.8 application writes ----
-- Documents a client uploads through the portal are filed as 'Client Upload'.
-- A text column, as 4.5.0 made it (Lists allows custom document categories), so
-- running this file again never narrows it and never blanks a custom category.
ALTER TABLE `client_documents`
  MODIFY `category` varchar(60) NOT NULL DEFAULT 'Other';
-- A server that was not in strict mode stored a portal upload made before this
-- value existed with a blank category; file those rows as client uploads.
UPDATE `client_documents` SET `category` = 'Client Upload' WHERE `category` = '' AND `uploaded_by` IS NULL;
-- Which sign-in a one-time code was issued for (website or mobile app), so a
-- code sent for one cannot complete the other.
ALTER TABLE `login_otps`
  ADD COLUMN IF NOT EXISTS `channel` enum('web','api') NOT NULL DEFAULT 'web';
-- What an invoice was issued under, captured when it is generated, so a later
-- change to the track does not rewrite a bill already sent to the funder.
ALTER TABLE `invoices`
  ADD COLUMN IF NOT EXISTS `auth_number` varchar(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `funding_agency` varchar(190) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `bill_to` text DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `hourly_rate` decimal(8,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `mileage_rate` decimal(6,3) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `period_start` date DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `period_end` date DEFAULT NULL;
-- When a guardian withdrew a signed consent, and who recorded it.
ALTER TABLE `consent_forms`
  ADD COLUMN IF NOT EXISTS `withdrawn_at` datetime DEFAULT NULL AFTER `sign_ip`,
  ADD COLUMN IF NOT EXISTS `withdrawn_by` int(10) unsigned DEFAULT NULL AFTER `withdrawn_at`;
-- Authorization-number and mileage-rate changes in a track's amendment history.
ALTER TABLE `auth_amendments`
  ADD COLUMN IF NOT EXISTS `old_auth_number` varchar(80) DEFAULT NULL AFTER `new_expiration`,
  ADD COLUMN IF NOT EXISTS `new_auth_number` varchar(80) DEFAULT NULL AFTER `old_auth_number`,
  ADD COLUMN IF NOT EXISTS `old_mileage_rate` decimal(6,3) DEFAULT NULL AFTER `new_auth_number`,
  ADD COLUMN IF NOT EXISTS `new_mileage_rate` decimal(6,3) DEFAULT NULL AFTER `old_mileage_rate`;
-- When a student left a program cohort. The membership row is kept so the
-- hours they attended stay in the cohort's attendance record.
ALTER TABLE `cohort_members`
  ADD COLUMN IF NOT EXISTS `left_at` datetime DEFAULT NULL AFTER `added_at`;
-- A new track must state its funder and rate: the 3.0.7 defaults ('State VR' and
-- $95.00 an hour) could bill a track nobody had priced. Existing tracks keep their
-- values; only the column defaults change.
ALTER TABLE `training_tracks`
  MODIFY `funding_agency` varchar(190) NOT NULL,
  MODIFY `hourly_rate` decimal(8,2) NOT NULL DEFAULT 0.00;

-- ---- 5. Built-in 'Billing & Reporting' group: say what it really grants ----
-- Its 'clients' permission is full read and write access to every client record,
-- but the shipped description called it "read-focused". Only the untouched
-- wording is replaced, so a description an administrator wrote is kept.
UPDATE `groups`
   SET `description` = 'Invoices, agency reports, analytics, and full read and write access to every client record (not limited to a caseload)'
 WHERE `name` = 'Billing & Reporting'
   AND `description` = 'Invoices, agency reports, analytics, and client records (unscoped, read-focused)';

-- ---- 6. Equipment notes that named a client ----
-- Before 3.0.8 a device added to the inventory from a delivered device request
-- was noted "From device request #N for <client name>". The inventory is seen by
-- staff outside that client's caseload, so the name is removed and the request
-- number kept. Nothing else writes or edits these notes.
UPDATE `equipment`
   SET `notes` = SUBSTRING_INDEX(`notes`, ' for ', 1)
 WHERE `notes` LIKE 'From device request #% for %';

-- ---- 7. Device follow-ups that no longer apply ----
-- Returning a device now closes its pending "still in use?" follow-ups, and an
-- AT repair loaner (a temporary device) gets none. Close the ones left from
-- before, so they stop showing as due: loans already returned first, then the
-- loaners issued for a repair (the repair record's details hold the loan id).
UPDATE `equipment_followups` f
  JOIN `equipment_loans` l ON l.`id` = f.`loan_id`
   SET f.`status` = 'Skipped', f.`completed_at` = NOW(), f.`notes` = 'Device returned'
 WHERE f.`status` = 'Pending'
   AND l.`returned_at` IS NOT NULL;
UPDATE `equipment_followups` f
  JOIN `at_records` r ON r.`kind` = 'repair'
   AND JSON_VALUE(r.`details`, '$.loan_id') REGEXP '^[0-9]+$'
   AND CAST(JSON_VALUE(r.`details`, '$.loan_id') AS UNSIGNED) = f.`loan_id`
   SET f.`status` = 'Skipped', f.`completed_at` = NOW(), f.`notes` = 'Repair loaner'
 WHERE f.`status` = 'Pending';
