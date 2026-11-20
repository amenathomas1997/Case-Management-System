<?php
declare(strict_types=1);
// Tech assessments (4.35.0): the checks the client page, the AI-guided intake and the app's
// add_assessment share, so a date or a field is refused with the same sentence everywhere.

/**
 * The assessment date's problem, or null. A real date from 2000 onwards, never in the future:
 * the newest assessment is read as the client's current one everywhere (RSA-911,
 * recommendations), and none can be edited later. $slackDays lets the app's date be that many
 * days ahead (a phone in another time zone); the website passes none.
 */
function tech_assessment_date_error(string $date, int $slackDays = 0): ?string {
    $date = trim($date);
    $latest = date('Y-m-d', strtotime('+' . max(0, $slackDays) . ' days'));
    if ($date === '') return 'Assessment date is required.';
    if (!valid_date($date, 2000, (int)substr($latest, 0, 4))) return 'Enter the assessment date as a valid date (YYYY-MM-DD) from the year 2000 onwards.';
    if ($date > $latest) return 'The assessment date cannot be in the future. Check the year.';
    return null;
}

/**
 * Every problem with an assessment's fields, keyed by field (assessment_date, primary_disability,
 * hardware_details, software_version, summary), as client.php's form names them; empty when it
 * can be saved. The limits are the tech_assessments columns.
 */
function tech_assessment_errors(array $in, int $slackDays = 0): array {
    $str = fn(string $k): string => is_scalar($in[$k] ?? null) ? trim((string)$in[$k]) : '';
    $errs = [];
    if (($dateErr = tech_assessment_date_error($str('assessment_date'), $slackDays)) !== null) $errs['assessment_date'] = $dateErr;
    foreach (['primary_disability' => [190, 'Primary disability / needs'], 'hardware_details' => [255, 'Hardware details'],
              'software_version' => [190, 'Software & version']] as $k => [$max, $label]) {
        $n = mb_strlen($str($k));
        if ($n > $max) $errs[$k] = "{$label} is {$n} characters; shorten it to {$max} or fewer (more detail can go in the summary).";
    }
    if (strlen($str('summary')) > 65535) {
        $errs['summary'] = 'Assessment summary is too long (65,535 bytes maximum; accented letters and symbols count as 2 to 4 bytes each).';
    }
    return $errs;
}
