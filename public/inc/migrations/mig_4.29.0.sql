-- 4.29.0: fixes from the third 2026-10-18 code audit. Safe to run again.
-- One account per Microsoft identity: users.entra_oid becomes UNIQUE (P1).
-- Existing data may hold the same identity on more than one row (nothing stopped it before).
-- A UNIQUE key allows many NULLs, so each extra row's link is cleared first, keeping it on:
--   1. an active account, the lowest id first (the row sso.php signed in to before 4.29.0), else
--   2. the lowest-id deactivated account (so that identity is still refused, as P1 requires).
-- Each cleared link is recorded in the audit log. A person whose extra row lost its link can
-- link again from My Account (if that row is active). Safe to run again: nothing is left to clear.
UPDATE `users` SET `entra_oid` = NULL WHERE `entra_oid` = '';
INSERT INTO `audit_log` (`user_id`, `action`, `entity`, `entity_id`, `detail`)
SELECT NULL, 'user.sso_unlink', 'user', u.`id`, CONCAT('Database upgrade 4.29.0: this Microsoft identity was also linked to account #', d.`keep_id`, ', so the link here was removed (one account per identity)')
FROM `users` u
JOIN (SELECT `entra_oid`, CAST(SUBSTRING_INDEX(GROUP_CONCAT(`id` ORDER BY `is_active` DESC, `id` ASC), ',', 1) AS UNSIGNED) AS `keep_id`
      FROM `users` WHERE `entra_oid` IS NOT NULL GROUP BY `entra_oid` HAVING COUNT(*) > 1) d ON d.`entra_oid` = u.`entra_oid`
WHERE u.`id` <> d.`keep_id`;
UPDATE `users` u
JOIN (SELECT `entra_oid`, CAST(SUBSTRING_INDEX(GROUP_CONCAT(`id` ORDER BY `is_active` DESC, `id` ASC), ',', 1) AS UNSIGNED) AS `keep_id`
      FROM `users` WHERE `entra_oid` IS NOT NULL GROUP BY `entra_oid` HAVING COUNT(*) > 1) d ON d.`entra_oid` = u.`entra_oid`
SET u.`entra_oid` = NULL
WHERE u.`id` <> d.`keep_id`;
ALTER TABLE `users` DROP INDEX IF EXISTS `idx_entra_oid`;
ALTER TABLE `users` ADD UNIQUE KEY IF NOT EXISTS `idx_entra_oid` (`entra_oid`);

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.29.0') ON DUPLICATE KEY UPDATE `value` = '4.29.0';
