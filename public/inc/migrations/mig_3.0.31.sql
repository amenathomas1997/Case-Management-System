-- 3.0.31: client emails no longer wait for staff confirmation (owner decision,
-- 2026-10-15). Before this release a client email copied from a public referral was
-- stored with email_verified = 0, so no reminder, survey or portal link went to it.
-- New conversions store it as confirmed; this marks the ones already waiting.
-- Safe to run again. Counselor emails (counselor_verified) are not changed.
UPDATE `clients` SET `email_verified` = 1 WHERE `email_verified` = 0;
