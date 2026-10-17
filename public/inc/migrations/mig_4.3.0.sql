-- 4.3.0: branding, privacy notice wording and the app icon set on screen. Requires MariaDB 10.4 or newer. Safe to run again.

-- Calendar event IDs: a site upgraded from an earlier version keeps the "cms" prefix its
-- invitations already went out with, so a later update or cancellation still finds them.
-- A new install has no row and uses "cms".
INSERT IGNORE INTO `settings` (`name`, `value`) VALUES ('ics_uid_prefix', 'cms');

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.3.0') ON DUPLICATE KEY UPDATE `value` = '4.3.0';
