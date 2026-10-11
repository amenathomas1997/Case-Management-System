-- v2.46.0 — Standardized AT outcome instruments + per-instrument scoring scale.
-- The scored-assessment engine was fixed at 0-4; generalize it so outcome tools
-- with other scales (satisfaction 1-5, psychosocial impact -3..+3) score correctly,
-- and store a normalized 0-100 percent so trends compare fairly across scales.
-- Safe to re-run (MariaDB IF NOT EXISTS): columns are added only when missing, the
-- backfill skips rows that already have a percent, and each seed instrument is
-- inserted only when no instrument of that name exists.

ALTER TABLE `assessment_instruments`
  ADD COLUMN IF NOT EXISTS `scale_min` tinyint(4) NOT NULL DEFAULT 0 AFTER `description`,
  ADD COLUMN IF NOT EXISTS `scale_max` tinyint(4) NOT NULL DEFAULT 4 AFTER `scale_min`,
  ADD COLUMN IF NOT EXISTS `scale_labels` varchar(600) DEFAULT NULL AFTER `scale_max`,
  ADD COLUMN IF NOT EXISTS `higher_is_better` tinyint(1) NOT NULL DEFAULT 1 AFTER `scale_labels`,
  ADD COLUMN IF NOT EXISTS `category` varchar(80) DEFAULT NULL AFTER `higher_is_better`;

ALTER TABLE `client_scored_assessments`
  ADD COLUMN IF NOT EXISTS `pct` decimal(5,1) NOT NULL DEFAULT 0 AFTER `max_score`;

-- Backfill normalized percent for rows that predate the column. Limited to rows
-- that still have no percent and whose instrument is on a 0-based scale (every
-- instrument was, before this file first ran), so running the file again can
-- never overwrite a percent the app has since stored for a 1-5 or -3..+3 scale.
UPDATE `client_scored_assessments` a
  JOIN `assessment_instruments` i ON i.`id` = a.`instrument_id`
   SET a.`pct` = IF(a.`max_score` > 0, ROUND(a.`total_score` / a.`max_score` * 100, 1), 0)
 WHERE a.`pct` = 0 AND i.`scale_min` = 0;

-- ---- Seed: AT Device Satisfaction (1-5) ----
INSERT INTO `assessment_instruments` (`name`, `description`, `scale_min`, `scale_max`, `scale_labels`, `higher_is_better`, `category`)
SELECT 'AT Device Satisfaction (1-5)', 'User satisfaction with an assistive device and the services around it. Re-administer to show change. Original items; replace with a licensed instrument''s wording if your program uses one.', 1, 5,
  '1=1 Not satisfied at all;2=2 Not very satisfied;3=3 More or less satisfied;4=4 Quite satisfied;5=5 Very satisfied', 1, 'Satisfaction'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `assessment_instruments` WHERE `name` = 'AT Device Satisfaction (1-5)');
SET @sat := (SELECT MIN(`id`) FROM `assessment_instruments` WHERE `name` = 'AT Device Satisfaction (1-5)');
INSERT INTO `assessment_instrument_items` (`instrument_id`, `label`, `sort`)
SELECT @sat, v.`label`, v.`sort` FROM (
            SELECT 'The device''s size and weight suit how I use it' AS `label`, 1 AS `sort`
  UNION ALL SELECT 'The device is comfortable and safe to use', 2
  UNION ALL SELECT 'The device is easy to adjust and operate', 3
  UNION ALL SELECT 'The device is dependable and works when I need it', 4
  UNION ALL SELECT 'The device holds up well with daily use', 5
  UNION ALL SELECT 'The device is easy to clean and maintain', 6
  UNION ALL SELECT 'The device meets the needs I got it for', 7
  UNION ALL SELECT 'The device works well in the places I use it (home, work, community)', 8
  UNION ALL SELECT 'Delivery and setup of the device were timely', 9
  UNION ALL SELECT 'Repairs and technical support are handled well', 10
  UNION ALL SELECT 'Training on how to use the device was adequate', 11
  UNION ALL SELECT 'Follow-up and check-ins have been helpful', 12
) v
 WHERE NOT EXISTS (SELECT 1 FROM `assessment_instrument_items` x WHERE x.`instrument_id` = @sat);

-- ---- Seed: Psychosocial Impact of AT (-3..+3) ----
INSERT INTO `assessment_instruments` (`name`, `description`, `scale_min`, `scale_max`, `scale_labels`, `higher_is_better`, `category`)
SELECT 'Psychosocial Impact of AT (-3..+3)', 'How the assistive technology has changed the user''s competence, adaptability and self-esteem. Rate each: since using the device, this has gotten worse (-) / no change (0) / better (+). Original items.', -3, 3,
  '-3=-3 Much worse;-2=-2 Worse;-1=-1 Slightly worse;0=0 No change;1=+1 Slightly better;2=+2 Better;3=+3 Much better', 1, 'Psychosocial impact'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `assessment_instruments` WHERE `name` = 'Psychosocial Impact of AT (-3..+3)');
SET @psi := (SELECT MIN(`id`) FROM `assessment_instruments` WHERE `name` = 'Psychosocial Impact of AT (-3..+3)');
INSERT INTO `assessment_instrument_items` (`instrument_id`, `label`, `sort`)
SELECT @psi, v.`label`, v.`sort` FROM (
            SELECT 'My ability to do the task on my own' AS `label`, 1 AS `sort`
  UNION ALL SELECT 'My efficiency getting things done', 2
  UNION ALL SELECT 'My productivity at work or school', 3
  UNION ALL SELECT 'My independence from help by others', 4
  UNION ALL SELECT 'My ability to take part in activities I value', 5
  UNION ALL SELECT 'My willingness to try new activities', 6
  UNION ALL SELECT 'My ability to take advantage of opportunities', 7
  UNION ALL SELECT 'My ability to adapt to daily demands', 8
  UNION ALL SELECT 'My self-confidence', 9
  UNION ALL SELECT 'My sense of control over my life', 10
  UNION ALL SELECT 'My sense of wellbeing', 11
  UNION ALL SELECT 'My feeling of being able to keep up with others', 12
) v
 WHERE NOT EXISTS (SELECT 1 FROM `assessment_instrument_items` x WHERE x.`instrument_id` = @psi);

-- ---- Seed: Person-Technology Match Check (0-4) ----
INSERT INTO `assessment_instruments` (`name`, `description`, `scale_min`, `scale_max`, `scale_labels`, `higher_is_better`, `category`)
SELECT 'Person-Technology Match Check (0-4)', 'A readiness/fit checklist to judge how well a device matches the person and their environments before and after provision. Original items.', 0, 4,
  '0=0 Not at all;1=1 Slightly;2=2 Somewhat;3=3 Mostly;4=4 Fully', 1, 'Match'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `assessment_instruments` WHERE `name` = 'Person-Technology Match Check (0-4)');
SET @mtc := (SELECT MIN(`id`) FROM `assessment_instruments` WHERE `name` = 'Person-Technology Match Check (0-4)');
INSERT INTO `assessment_instrument_items` (`instrument_id`, `label`, `sort`)
SELECT @mtc, v.`label`, v.`sort` FROM (
            SELECT 'The device matches the user''s goals and priorities' AS `label`, 1 AS `sort`
  UNION ALL SELECT 'The user is motivated to use this device', 2
  UNION ALL SELECT 'The device fits the user''s physical abilities and comfort', 3
  UNION ALL SELECT 'The device fits the user''s sensory abilities (vision/hearing)', 4
  UNION ALL SELECT 'The device fits the user''s cognitive and learning needs', 5
  UNION ALL SELECT 'The user has the training and support to use it', 6
  UNION ALL SELECT 'The environments where it is needed support its use', 7
  UNION ALL SELECT 'Funding and maintenance for the device are in place', 8
  UNION ALL SELECT 'The device works with the user''s other tools and technology', 9
  UNION ALL SELECT 'The user and family accept the device', 10
) v
 WHERE NOT EXISTS (SELECT 1 FROM `assessment_instrument_items` x WHERE x.`instrument_id` = @mtc);
