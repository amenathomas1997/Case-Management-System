-- 3.0.42: clients answer their own device check-ins in the portal. Requires MariaDB 10.4
-- or newer. Safe to run again.

-- A check-in answered by the client in the portal (completed_by stays empty).
ALTER TABLE `equipment_followups` ADD COLUMN IF NOT EXISTS `client_reported` tinyint(1) NOT NULL DEFAULT 0 AFTER `completed_by`;
