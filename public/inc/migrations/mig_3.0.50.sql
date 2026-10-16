-- 3.0.50: one-off correction of scored-assessment percentages. Requires MariaDB 10.4 or newer.
-- Safe to run again (rows already right are unchanged).

-- Older app versions stored the percentage as total / highest possible, which is wrong for an
-- instrument whose scale does not start at 0. Each row is recalculated from its item scores on
-- the instrument's scale, as scored_pct() does, when the instrument's top rating still matches
-- what was stored (a scale edited since is left alone).
UPDATE `client_scored_assessments` sa
  JOIN `assessment_instruments` i ON i.id = sa.instrument_id
  JOIN (SELECT scored_id, COUNT(*) n, SUM(score) tot FROM `client_scored_assessment_items` GROUP BY scored_id) x ON x.scored_id = sa.id
  SET sa.pct = ROUND((x.tot - x.n * i.scale_min) * 100 / (x.n * (i.scale_max - i.scale_min)), 1)
  WHERE i.scale_max > i.scale_min AND sa.max_score = x.n * i.scale_max AND sa.total_score = x.tot
    AND sa.pct <> ROUND((x.tot - x.n * i.scale_min) * 100 / (x.n * (i.scale_max - i.scale_min)), 1);
