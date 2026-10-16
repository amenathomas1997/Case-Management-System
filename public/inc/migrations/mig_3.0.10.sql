-- Structured referral questions; preserve all original referral types and records.
-- NULL distinguishes historic submissions from the new form. Safe to rerun.
ALTER TABLE `referrals`
  ADD COLUMN IF NOT EXISTS `referral_purpose` varchar(40) DEFAULT NULL AFTER `quoted_fee`,
  ADD COLUMN IF NOT EXISTS `requested_service` varchar(80) DEFAULT NULL AFTER `referral_purpose`,
  ADD COLUMN IF NOT EXISTS `specific_needs` text DEFAULT NULL AFTER `requested_service`;
