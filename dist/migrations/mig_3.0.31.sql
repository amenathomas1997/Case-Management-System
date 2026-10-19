-- 3.0.31: client emails no longer wait for staff confirmation (owner decision,
-- 2026-10-15). Before this release a client email copied from a public referral was
-- stored with email_verified = 0, so no reminder, survey or portal link went to it.
-- New conversions store it as confirmed; this marks the ones already waiting.
-- Counselor emails (counselor_verified) are not changed.
-- Safe to run again (4.29.0): only clients added before 3.0.31 was released (2026-10-15)
-- are touched. Since 3.0.55, Client import stores each imported email as unconfirmed on
-- purpose until staff confirm it; a rerun of the migrations (Database upgrade, or by hand)
-- must never confirm those, so no client added on or after 2026-10-16 is changed.
UPDATE `clients` SET `email_verified` = 1 WHERE `email_verified` = 0 AND `created_at` < '2026-10-16 00:00:00';
