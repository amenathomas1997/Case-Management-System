-- v3.0.1: bound the lifetime of VR counselor portal tokens (they previously never expired).
-- Safe to rerun (MariaDB IF NOT EXISTS); backfills existing tokens to 180 days from issue.
ALTER TABLE vrc_tokens ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL;
UPDATE vrc_tokens SET expires_at = DATE_ADD(created_at, INTERVAL 180 DAY) WHERE expires_at IS NULL;
