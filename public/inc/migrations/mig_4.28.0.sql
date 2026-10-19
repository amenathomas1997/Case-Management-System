-- 4.28.0: fixes from the second 2026-10-18 code audit. Safe to run again.
-- NULL qbo_id (payments not in QuickBooks, write-offs) never clash: a UNIQUE key allows many NULLs.
-- Before 4.28.0 a payment sent from here whose reply was lost could be imported back as a second row
-- with the same QuickBooks id (the H2 double count). Such a pair is one payment: the imported copy
-- (reference 'QuickBooks payment <id>') is removed, keeping the row recorded here, and the invoice's
-- totals are recalculated as invoice_retotal() does. Any other remaining duplicate keeps its lowest id.
-- Safe to run again.
DELETE d FROM `invoice_payments` d
  JOIN `invoice_payments` k ON k.`invoice_id` = d.`invoice_id` AND k.`qbo_id` = d.`qbo_id` AND k.`id` <> d.`id`
  WHERE d.`qbo_id` IS NOT NULL AND d.`reference` = CONCAT('QuickBooks payment ', d.`qbo_id`)
    AND (k.`reference` IS NULL OR k.`reference` <> CONCAT('QuickBooks payment ', k.`qbo_id`));
DELETE d FROM `invoice_payments` d
  JOIN `invoice_payments` k ON k.`invoice_id` = d.`invoice_id` AND k.`qbo_id` = d.`qbo_id` AND k.`id` < d.`id`
  WHERE d.`qbo_id` IS NOT NULL;
UPDATE `invoices` i
  LEFT JOIN (SELECT `invoice_id`,
      SUM(CASE WHEN `kind` = 'payment' THEN `amount` END) AS paid,
      COALESCE(SUM(CASE WHEN `kind` = 'writeoff' THEN `amount` END), 0) AS written,
      MAX(CASE WHEN `kind` = 'payment' THEN `received_on` END) AS last_on
    FROM `invoice_payments` GROUP BY `invoice_id`) t ON t.`invoice_id` = i.`id`
  SET i.`paid_amount` = IF(COALESCE(t.paid, 0) > 0, ROUND(t.paid, 2), NULL),
      i.`written_off` = ROUND(COALESCE(t.written, 0), 2),
      i.`paid_at` = t.last_on,
      i.`status` = CASE WHEN i.`status` IN ('Submitted','Paid') THEN IF(i.`total_amount` - COALESCE(t.paid, 0) - COALESCE(t.written, 0) <= 0.004, 'Paid', 'Submitted') ELSE i.`status` END
  WHERE i.`qbo_id` IS NOT NULL AND ABS(COALESCE(i.`paid_amount`, 0) - COALESCE(t.paid, 0)) > 0.004;
ALTER TABLE `invoice_payments` ADD UNIQUE KEY IF NOT EXISTS `invoice_qbo_payment` (`invoice_id`, `qbo_id`);

INSERT INTO `settings` (`name`, `value`) VALUES ('schema_version', '4.28.0') ON DUPLICATE KEY UPDATE `value` = '4.28.0';
