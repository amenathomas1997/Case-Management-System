-- 3.0.54: settings on screen and safe upgrades. Requires MariaDB 10.4 or newer. Safe to run again.

-- The database version, so the application can tell when it needs an upgrade (Database
-- upgrade, upgrade.php). Every migration from now on records its own version here.
INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '3.0.54')
  ON DUPLICATE KEY UPDATE `value` = '3.0.54';
