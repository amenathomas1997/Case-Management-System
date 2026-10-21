-- 4.3.0: branding, privacy notice wording and the app icon set on screen. Requires MariaDB 10.4 or newer. Safe to run again.

-- Calendar event IDs start with setting ics_uid_prefix, "cms" when there is no row. A site whose
-- invitations already went out with another prefix keeps that prefix in the setting, so a later
-- update or cancellation still finds them; this migration changes nothing there.

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.3.0') ON DUPLICATE KEY UPDATE `value` = '4.3.0';
