-- 3.0.12: a counselor email that arrives through the public referral form is
-- unconfirmed until staff confirm it on the client's edit page. Automatic progress
-- reports, counselor portal links and email filing skip an unconfirmed address.
-- The column is added with DEFAULT 1 so every client that already exists keeps
-- its counselor as confirmed (those addresses were already in use); the default
-- then becomes 0 for new rows, and a client with no counselor email is set to 0. Safe to run again: a second run adds nothing and
-- never re-confirms an address that is waiting for confirmation.
ALTER TABLE `clients`
  ADD COLUMN IF NOT EXISTS `counselor_verified` tinyint(1) NOT NULL DEFAULT 1 AFTER `counselor_agency`;
ALTER TABLE `clients`
  MODIFY `counselor_verified` tinyint(1) NOT NULL DEFAULT 0;
-- A client with no counselor email has nothing to confirm: store 0, as every save
-- does, so a counselor email later filled in (for example by merging a duplicate
-- client) is never treated as confirmed. Safe to run again.
UPDATE `clients` SET `counselor_verified` = 0 WHERE `counselor_email` IS NULL OR `counselor_email` = '';

-- Sites that already ran mig_3.0.8.sql: a server that was not in strict mode
-- stored a portal upload made before 'Client Upload' existed with a blank
-- category; file those rows as client uploads. Safe to run again.
UPDATE `client_documents` SET `category` = 'Client Upload' WHERE `category` = '' AND `uploaded_by` IS NULL;
