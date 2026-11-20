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

/** The report attachment's allowed types (client.php's "Report attachment"). */
const TECH_ATTACHMENT_EXTENSIONS = ['pdf', 'doc', 'docx', 'txt', 'rtf', 'png', 'jpg', 'jpeg'];

/** The attachment's name and size checked before anything is stored (4.37.0: moved from client.php,
 *  shared with the app's add_assessment). Returns [error or null, extension, the name as stored for
 *  display (anything outside a plain set becomes one underscore per character)]. */
function tech_assessment_attachment_check(string $orig, int $size): array {
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $name = preg_replace('/[^A-Za-z0-9._ -]/u', '_', $orig) ?? preg_replace('/[^A-Za-z0-9._ -]/', '_', $orig);
    if (!in_array($ext, TECH_ATTACHMENT_EXTENSIONS, true)) return ['Attachment type not allowed. Use PDF, Word, text, or image files.', $ext, $name];
    if ($size > 15 * 1024 * 1024) return ['Attachment is too large (15 MB maximum).', $ext, $name];
    if (mb_strlen($name) > 190) return ["The attachment's file name is too long (190 characters maximum). Rename the file and choose it again.", $ext, $name];
    return [null, $ext, $name];
}

/** The attachment's file scanned, checked to be what its name says, and stored in the upload
 *  folder. $ownTemp: a file this code wrote (the app's), moved into place; otherwise a browser upload
 *  (the test harness's posted files are copied, on the command line only). Returns [error or null,
 *  the stored file name or null]. */
function tech_assessment_attachment_finish(string $tmp, string $ext, bool $ownTemp): array {
    if (($virus = upload_virus_check($tmp)) !== null) return [$virus, null];
    // The content must be what the name says (4.24.0).
    if (!upload_content_ok($tmp, $ext, true)) return ['That file is not really a .' . $ext . ' file, so it was not stored. Save it again in that format and choose it again.', null];
    $dir = cfg('upload_dir');
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $moved = $ownTemp ? rename($tmp, $dir . '/' . $stored)
        : (is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dir . '/' . $stored) : (PHP_SAPI === 'cli' && getenv('CMS_TEST_FILES') !== false && copy($tmp, $dir . '/' . $stored)));
    if (!$moved) return ['The attachment could not be saved. Choose it again and resend the form.', null];
    return [null, $stored];
}

/** An attachment the app sent (its name and bytes) checked and stored, as client.php checks an
 *  uploaded one. Returns [error or null, ['path', 'name'] or null]. */
function tech_assessment_attachment_store(string $name, string $bytes): array {
    [$err, $ext, $safe] = tech_assessment_attachment_check($name, strlen($bytes));
    if ($err !== null) return [$err, null];
    $tmp = tempnam(sys_get_temp_dir(), 'cmsatt');
    if ($tmp === false || file_put_contents($tmp, $bytes) === false) return ['The attachment could not be saved. Choose it again and resend the form.', null];
    [$err, $stored] = tech_assessment_attachment_finish($tmp, $ext, true);
    if (is_file($tmp)) @unlink($tmp);
    return $err !== null ? [$err, null] : [null, ['path' => $stored, 'name' => $safe]];
}

/** The assessment templates staff can start a new assessment from (templates.php), by name. */
function assessment_templates_list(): array {
    return db()->query('SELECT id, name, primary_tech, hardware_details, software_version, summary FROM assessment_templates ORDER BY name')->fetchAll();
}
