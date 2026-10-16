-- v2.55.0 — Equipment depreciation / asset-value tracking.
-- Safe to re-run (MariaDB IF NOT EXISTS).
ALTER TABLE `equipment`
  ADD COLUMN IF NOT EXISTS `purchase_date` date DEFAULT NULL AFTER `value`,
  ADD COLUMN IF NOT EXISTS `useful_life_months` smallint(5) unsigned NOT NULL DEFAULT 36 AFTER `purchase_date`;
