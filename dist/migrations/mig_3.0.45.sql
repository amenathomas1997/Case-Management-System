-- 3.0.45: the plan for employment on the client record. Requires MariaDB 10.4 or newer.
-- Safe to run again.

-- The VR agency's case or participant number, the IPE employment goal, the IPE date and
-- the latest amendment date; printed on reports and invoices.
ALTER TABLE `clients`
  ADD COLUMN IF NOT EXISTS `vr_case_number` varchar(60) DEFAULT NULL AFTER `on_hold_until`,
  ADD COLUMN IF NOT EXISTS `ipe_goal` varchar(255) DEFAULT NULL AFTER `vr_case_number`,
  ADD COLUMN IF NOT EXISTS `ipe_date` date DEFAULT NULL AFTER `ipe_goal`,
  ADD COLUMN IF NOT EXISTS `ipe_amended_on` date DEFAULT NULL AFTER `ipe_date`;
