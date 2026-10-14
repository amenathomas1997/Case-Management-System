-- 3.0.21: offline-replay keys and confirmation of a client email from the public
-- referral form. Requires MariaDB 10.4 or newer. Safe to run again: every
-- statement only adds what is missing (IF NOT EXISTS). Nothing is deleted and no
-- existing row changes.

-- Replay keys for case notes and logged hours, as schema_full.sql installs them.
-- mig_3.0.8.sql adds them too; they are repeated here so a site that applies only
-- this file is sure to have them.
ALTER TABLE `client_notes`
  ADD COLUMN IF NOT EXISTS `idem_key` char(36) DEFAULT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS `uniq_idem` ON `client_notes` (`idem_key`);
ALTER TABLE `work_logs`
  ADD COLUMN IF NOT EXISTS `idem_key` char(36) DEFAULT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS `uniq_idem` ON `work_logs` (`idem_key`);

-- Replay key for track expenses: an expense the mobile app saved offline and
-- sends again is stored once.
ALTER TABLE `track_expenses`
  ADD COLUMN IF NOT EXISTS `idem_key` char(36) DEFAULT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS `uniq_idem` ON `track_expenses` (`idem_key`);

-- A client email copied from the public referral form is unconfirmed (0) until
-- staff confirm it: no session reminders, outcome surveys or portal links go to
-- it before then. Every client that already exists keeps its email as confirmed
-- (1, the column default), as do clients staff enter themselves.
ALTER TABLE `clients`
  ADD COLUMN IF NOT EXISTS `email_verified` tinyint(1) NOT NULL DEFAULT 1 AFTER `email`;
