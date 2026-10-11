-- Add Rehab Technology and related AT service referral types. Safe to run again.
-- Append the values to preserve the existing ENUM value order.
ALTER TABLE `referrals`
  MODIFY `note_type` enum('Initial Intake','Tech Assessment Request','Rehab Teaching Request','Counseling Request','Pre-ETS Request','Plan Amendment','Other','Rehab Technology','Assistive Technology Training Request','Low Vision Device Request','Screen Reader Training Request','Magnification Training Request','Braille Training Request','Independent Living Skills Request','Workplace Assessment Request','Device Trial / Loan Request','Device Setup Request','Device Repair / Support Request','Device Funding Assistance Request') NOT NULL DEFAULT 'Initial Intake';
