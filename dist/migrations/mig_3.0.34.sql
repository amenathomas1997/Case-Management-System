-- 3.0.34: getting paid. Requires MariaDB 10.4 or newer. Safe to run again: every
-- statement adds only what is missing, and the backfills touch only rows not yet
-- converted. Nothing is deleted.

-- Training tracks: how the work is paid for. 'vr' (an agency authorization, as
-- before), 'private' (the client pays; invoiced to the client) or 'none' (no
-- charge: hours are logged at $0 and never invoiced). authorized_amount is an
-- optional dollar limit on a VR authorization; without one, the limit is the
-- authorized hours at the hourly rate.
ALTER TABLE `training_tracks`
  ADD COLUMN IF NOT EXISTS `authorized_amount` decimal(10,2) DEFAULT NULL AFTER `authorized_hours`,
  ADD COLUMN IF NOT EXISTS `billing_mode` enum('vr','private','none') NOT NULL DEFAULT 'vr' AFTER `last_report_at`;

-- Invoices: denied and void invoices stay on record (their work is released to be
-- billed again); a balance can be written off; the due date comes from the payment
-- terms when the invoice is submitted; private-pay invoices are billed to the client.
ALTER TABLE `invoices`
  MODIFY COLUMN `status` enum('Draft','Submitted','Paid','Denied','Void') NOT NULL DEFAULT 'Draft',
  ADD COLUMN IF NOT EXISTS `closed_reason` varchar(255) DEFAULT NULL AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `due_date` date DEFAULT NULL AFTER `submitted_at`,
  ADD COLUMN IF NOT EXISTS `written_off` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `paid_amount`,
  ADD COLUMN IF NOT EXISTS `bill_mode` enum('vr','private') NOT NULL DEFAULT 'vr' AFTER `expenses_amount`;

-- Every payment and write-off, with the date it was received and its reference.
-- invoices.paid_amount and invoices.written_off are their running totals.
CREATE TABLE IF NOT EXISTS `invoice_payments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int(10) unsigned NOT NULL,
  `kind` enum('payment','writeoff') NOT NULL DEFAULT 'payment',
  `amount` decimal(10,2) NOT NULL,
  `received_on` date NOT NULL,
  `reference` varchar(80) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `recorded_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `invoice_id` (`invoice_id`),
  CONSTRAINT `fk_payment_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Payments recorded before 3.0.34 (one amount on the invoice) become one payment row,
-- dated as recorded; an old Paid invoice with no amount was paid in full.
UPDATE `invoices` SET `paid_amount` = `total_amount` WHERE `status` = 'Paid' AND `paid_amount` IS NULL;
INSERT INTO `invoice_payments` (`invoice_id`, `kind`, `amount`, `received_on`, `reference`, `note`)
  SELECT i.`id`, 'payment', i.`paid_amount`, COALESCE(i.`paid_at`, DATE(i.`created_at`)), i.`payment_ref`, 'Recorded before 3.0.34'
  FROM `invoices` i
  WHERE i.`paid_amount` IS NOT NULL AND i.`paid_amount` > 0
    AND NOT EXISTS (SELECT 1 FROM `invoice_payments` p WHERE p.`invoice_id` = i.`id`);
-- Submitted invoices get the due date the CSV export used (30 days).
UPDATE `invoices` SET `due_date` = DATE_ADD(`submitted_at`, INTERVAL 30 DAY) WHERE `due_date` IS NULL AND `submitted_at` IS NOT NULL;
