-- 3.0.27: the language a client prefers (English or Spanish). Requires MariaDB
-- 10.4 or newer. Safe to run again: every statement only adds what is missing
-- (IF NOT EXISTS). Nothing is deleted and no existing row changes.

-- 'en' or 'es'. Set on the client's edit page; a referral sent on the Spanish
-- public form sets it to 'es' when it is converted. The portal opens in that
-- language, and the emails the application sends the client (portal link,
-- session reminder, outcome survey, guardian consent request) are sent in it.
ALTER TABLE `clients`
  ADD COLUMN IF NOT EXISTS `preferred_language` varchar(5) NOT NULL DEFAULT 'en' AFTER `a11y_notes`;

-- The language the public referral form was sent in, carried to the client
-- record on conversion (apply_referral_language() in inc/core.php).
ALTER TABLE `referrals`
  ADD COLUMN IF NOT EXISTS `preferred_language` varchar(5) NOT NULL DEFAULT 'en' AFTER `preets_services`;
