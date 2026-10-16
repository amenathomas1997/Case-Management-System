-- 4.1.0: referrals from VR agencies in other states. Requires MariaDB 10.4 or newer. Safe to run again.

-- Each funding agency's own details, matched to tracks by name (training_tracks.funding_agency
-- and invoices.funding_agency stay as they are): its state, the time zone its clients are
-- usually in, the email domains its authorizations come from, the vendor number it gave you,
-- its payment terms and the address invoices go to. Empty values fall back to Billing details.
CREATE TABLE IF NOT EXISTS `funding_agencies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `state` char(2) DEFAULT NULL,
  `time_zone` varchar(40) DEFAULT NULL,
  `email_domains` varchar(255) DEFAULT NULL,
  `vendor_number` varchar(80) DEFAULT NULL,
  `terms_days` smallint(5) unsigned DEFAULT NULL,
  `bill_to` varchar(500) DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The agencies already on tracks, so nothing has to be typed again. Spaces are collapsed, and
-- of two spellings differing only in capitals the one on most tracks is kept (the first inserted).
INSERT IGNORE INTO `funding_agencies` (`name`)
  SELECT CONVERT(`spelling` USING utf8mb4) FROM (
    SELECT CAST(REGEXP_REPLACE(TRIM(`funding_agency`), '[[:space:]]+', ' ') AS BINARY) AS `spelling`, COUNT(*) AS `uses` FROM `training_tracks`
    WHERE TRIM(`funding_agency`) <> '' AND COALESCE(`billing_mode`, 'vr') = 'vr' AND TRIM(`funding_agency`) NOT IN ('Private pay', 'No charge')
    GROUP BY `spelling`) AS `seen` ORDER BY `uses` DESC;
-- Iowa Department for the Blind, unless it is already listed (perhaps renamed) with its domain.
INSERT INTO `funding_agencies` (`name`, `state`, `time_zone`, `email_domains`)
  SELECT 'Iowa Department for the Blind', 'IA', 'America/Chicago', 'blind.state.ia.us' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `funding_agencies` WHERE `name` = 'Iowa Department for the Blind' OR `email_domains` LIKE '%blind.state.ia.us%');
UPDATE `funding_agencies` SET `state` = 'IA', `time_zone` = 'America/Chicago', `email_domains` = 'blind.state.ia.us'
  WHERE `name` = 'Iowa Department for the Blind' AND `state` IS NULL;

-- Where each client lives, and the time zone their emails and portal show times in
-- (empty: the site's own time zone).
ALTER TABLE `clients`
  ADD COLUMN IF NOT EXISTS `state` char(2) DEFAULT NULL AFTER `address`,
  ADD COLUMN IF NOT EXISTS `time_zone` varchar(40) DEFAULT NULL AFTER `state`;

-- The vendor number printed on each invoice, kept as it was sent (an agency renamed or given
-- a new number later does not change an invoice already sent).
ALTER TABLE `invoices`
  ADD COLUMN IF NOT EXISTS `vendor_number` varchar(80) DEFAULT NULL AFTER `period_end`;

-- The state given on a referral, carried to the client.
ALTER TABLE `referrals`
  ADD COLUMN IF NOT EXISTS `client_state` char(2) DEFAULT NULL AFTER `client_address`;

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.1.0') ON DUPLICATE KEY UPDATE `value` = '4.1.0';
