<?php
declare(strict_types=1);
// What the client sees of their finished work in the portal (3.0.51): report copies staff
// share, staff feedback on practice updates, completed homework, and a completion summary
// for each finished training plan. Also the HTML document helper letters use.

/** Save an HTML page as one of the client's documents. Resource documents show in their portal. Returns the document id, or 0. */
function client_document_save_html(int $clientId, string $category, string $title, string $fileName, string $html, int $actor): int {
    $dir = cfg('upload_dir');
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $stored = bin2hex(random_bytes(16)) . '.html';
    if (file_put_contents($dir . '/' . $stored, $html) === false) return 0;
    try {
        db()->prepare('INSERT INTO client_documents (client_id, category, title, orig_name, path, uploaded_by) VALUES (?,?,?,?,?,?)')
            ->execute([$clientId, $category, mb_substr($title, 0, 190), mb_substr($fileName, 0, 190), $stored, $actor]);
        return (int)db()->lastInsertId();
    } catch (Throwable $e) {
        @unlink($dir . '/' . $stored);
        throw $e;
    }
}

/** A plain-text body as a whole accessible HTML page (a heading, then paragraphs). */
function plain_document_html(string $title, string $body, string $lang = 'en'): string {
    $paras = array_filter(array_map('trim', preg_split('/\R{2,}/', trim($body)) ?: []), fn($p) => $p !== '');
    return '<!doctype html><html lang="' . h($lang) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . h($title) . '</title>'
        . '<style>body{font-family:Arial,Helvetica,sans-serif;font-size:1.15rem;line-height:1.6;max-width:44rem;margin:2rem auto;padding:0 1rem;color:#000;background:#fff}</style></head><body>'
        . '<h1>' . h($title) . '</h1>' . implode('', array_map(fn($p) => '<p>' . nl2br(h($p)) . '</p>', $paras)) . '</body></html>';
}

/** Share a sent report with the client: an accessible copy in their portal's resources. */
function report_share_to_portal(int $clientId, string $title, string $text, int $actor): int {
    $id = client_document_save_html($clientId, 'Resource', $title, 'report-' . date('Y-m-d') . '.html', plain_document_html($title, $text), $actor);
    if ($id) audit($actor, 'report.share_portal', 'client', $clientId, $title . ' (doc #' . $id . ')');
    return $id;
}

/** Record staff feedback on a practice update. Returns an error, or null. */
function practice_feedback_save(int $practiceId, string $feedback, int $actor): ?string {
    $feedback = trim($feedback);
    if ($feedback === '') return 'Write the feedback first.';
    if (mb_strlen($feedback) > 2000) return 'Keep the feedback to 2,000 characters or fewer.';
    $q = db()->prepare('SELECT c.id, c.assigned_to FROM homework_practice p JOIN homework h ON h.id = p.homework_id JOIN training_tracks t ON t.id = h.track_id JOIN clients c ON c.id = t.client_id WHERE p.id = ?');
    $q->execute([$practiceId]);
    $c = $q->fetch();
    if (!$c) return 'That practice update no longer exists.';
    if (caseload_scoped() && (int)$c['assigned_to'] !== $actor) return 'That client is not on your caseload.';
    db()->prepare('UPDATE homework_practice SET staff_feedback = ?, feedback_by = ?, feedback_at = NOW() WHERE id = ?')->execute([$feedback, $actor, $practiceId]);
    complete_task_by_key('practice:' . $practiceId, $actor);
    audit($actor, 'homework.feedback', 'client', (int)$c['id'], 'practice update #' . $practiceId);
    return null;
}

/** The client's recent practice updates, with any feedback, newest first. */
function portal_practice_history(int $clientId, int $limit = 15): array {
    try {
        $q = db()->prepare('SELECT p.minutes, p.difficulty, p.notes, p.staff_feedback, p.created_at, p.feedback_at, h.title FROM homework_practice p JOIN homework h ON h.id = p.homework_id
            JOIN training_tracks t ON t.id = h.track_id WHERE t.client_id = ? ORDER BY p.created_at DESC, p.id DESC LIMIT ' . (int)$limit);
        $q->execute([$clientId]);
        return $q->fetchAll();
    } catch (PDOException $e) {
        return []; // mig_3.0.51 not run yet
    }
}

/** Completed homework, newest first. */
function portal_completed_homework(int $clientId): array {
    $q = db()->prepare("SELECT h.title, h.due_date, t.title AS track_title FROM homework h JOIN training_tracks t ON t.id = h.track_id WHERE t.client_id = ? AND h.status = 'Completed' ORDER BY h.id DESC LIMIT 30");
    $q->execute([$clientId]);
    return $q->fetchAll();
}

/** Finished training plans, each with its hours, dates and goals, for the completion summary. */
function portal_finished_tracks(int $clientId): array {
    $q = db()->prepare("SELECT * FROM training_tracks WHERE client_id = ? AND status = 'Completed' ORDER BY COALESCE(completed_at, created_at) DESC");
    $q->execute([$clientId]);
    $out = [];
    foreach ($q->fetchAll() as $t) {
        $g = db()->prepare('SELECT specific_goal, status FROM smart_goals WHERE track_id = ? ORDER BY status = "Achieved" DESC, id');
        $g->execute([(int)$t['id']]);
        $r = track_rollup((int)$t['id']);
        $out[] = ['track' => $t, 'goals' => $g->fetchAll(), 'hours' => (float)$r['hours_worked']];
    }
    return $out;
}

/* ---------- Letters the organisation can edit (3.0.51) ---------- */

/** Built-in letters: key => [name, default wording]. Administrators change the wording on letters.php. */
const LETTER_TEMPLATES = [
    'welcome' => ['Welcome letter', "{date}\n\nDear {first_name},\n\nWelcome to {org}. Your VR counselor, {counselor}, referred you to us for assistive technology training, and {instructor} will be your instructor.\n\nYour training plan is {track}, authorized for {authorized_hours} hours until {expiration}. Your first session is {next_session}.\n\nIf you have any questions, please contact us.\n\nSincerely,\n\n{instructor}\n{org}"],
    'appointment' => ['Appointment confirmation', "{date}\n\nDear {first_name},\n\nThis letter confirms your next training session: {next_session}.\n\nIf you need to change this time, please contact {instructor} as soon as you can.\n\nSincerely,\n\n{instructor}\n{org}"],
    'no_show' => ['Missed appointment', "{date}\n\nDear {first_name},\n\nWe missed you at your training session on {last_missed}. We hope everything is all right.\n\nPlease contact {instructor} to arrange a new time. Regular attendance helps you reach your goals and keeps your authorization on track.\n\nSincerely,\n\n{instructor}\n{org}"],
    'loan' => ['Equipment loan agreement', "{date}\n\nDear {first_name},\n\n{org} is lending you the following equipment: {item}. Please return it by {loan_due}.\n\nPlease keep it safe and dry, use it for your training, and tell us straight away if it is lost, stolen or damaged. We will arrange its return with you.\n\nSigned: ______________________   Date: __________\n\nSincerely,\n\n{instructor}\n{org}"],
    'return' => ['Equipment return receipt', "{date}\n\nDear {first_name},\n\nThank you. We have received the following equipment back: {item}.\n\nSincerely,\n\n{instructor}\n{org}"],
    'referral_ack' => ['Referral acknowledgement to the counselor', "{date}\n\nDear {counselor},\n\nThank you for referring {client} to {org}. We have received the referral and {instructor} will contact them to arrange an assessment.\n\nWe will send progress reports under authorization {auth_number}.\n\nSincerely,\n\n{instructor}\n{org}"],
    'completion' => ['Training completion', "{date}\n\nDear {first_name},\n\nCongratulations on completing your training plan, {track}. You worked {hours_used} hours with {instructor}.\n\nWe will contact you in a month or so to see how things are going. If you need help in the meantime, please get in touch.\n\nSincerely,\n\n{instructor}\n{org}"],
];

/** The merge fields letters can use, as the letters page lists them. */
const LETTER_FIELDS = ['{date}', '{client}', '{first_name}', '{counselor}', '{counselor_agency}', '{instructor}', '{track}', '{auth_number}', '{authorized_hours}', '{hours_used}', '{expiration}', '{next_session}', '{last_missed}', '{item}', '{loan_due}', '{org}'];

/** A letter's wording: the organisation's own, else the built-in. */
function letter_template(string $key): string {
    $own = setting('letter_' . $key);
    return $own !== null && trim($own) !== '' ? $own : (LETTER_TEMPLATES[$key][1] ?? '');
}

/** The merge values for one client: their latest active track, next session, last missed one and newest loan. */
function letter_values(int $clientId, string $key = ''): array {
    $one = function (string $sql) use ($clientId) { $q = db()->prepare($sql); $q->execute([$clientId]); return $q->fetch() ?: []; };
    $c = $one('SELECT c.name, c.counselor_name, c.counselor_agency, c.time_zone, c.state, u.name AS instructor FROM clients c LEFT JOIN users u ON u.id = c.assigned_to WHERE c.id = ?');
    // A completion letter is about the plan just finished; every other letter about the current one.
    $t = $one("SELECT id, title, auth_number, authorized_hours, expiration_date FROM training_tracks WHERE client_id = ? ORDER BY "
        . ($key === 'completion' ? "status = 'Completed' DESC, COALESCE(completed_at, created_at) DESC" : "status = 'Active' DESC, id DESC") . ' LIMIT 1');
    $next = $one("SELECT start_at FROM scheduled_sessions WHERE client_id = ? AND status = 'Scheduled' AND start_at > NOW() ORDER BY start_at LIMIT 1");
    $missed = $one("SELECT start_at FROM scheduled_sessions WHERE client_id = ? AND status = 'No Show' ORDER BY start_at DESC LIMIT 1");
    $loan = $one('SELECT e.name, l.due_date FROM equipment_loans l JOIN equipment e ON e.id = l.equipment_id WHERE l.client_id = ? ORDER BY l.id DESC LIMIT 1');
    $blank = '[not on file]';
    $v = fn($x) => trim((string)$x) !== '' ? (string)$x : $blank;
    $name = trim((string)($c['name'] ?? ''));
    // The first name, past a title ("Dr. Jane Doe" is Jane).
    $first = (string)(preg_split('/\s+/', (string)preg_replace('/^(Dr|Mr|Mrs|Ms|Mx|Miss|Prof)\.?\s+/i', '', $name))[0] ?? '');
    return [
        '{date}' => date('F j, Y'), '{client}' => $v($name), '{first_name}' => $v($first !== '' ? $first : $name),
        '{counselor}' => $v($c['counselor_name'] ?? ''), '{counselor_agency}' => $v($c['counselor_agency'] ?? ''),
        '{instructor}' => $v($c['instructor'] ?? ''), '{track}' => $v($t['title'] ?? ''), '{auth_number}' => $v($t['auth_number'] ?? ''),
        '{authorized_hours}' => isset($t['authorized_hours']) ? (string)(float)$t['authorized_hours'] : $blank,
        '{hours_used}' => isset($t['id']) ? (string)round((float)track_rollup((int)$t['id'])['hours_worked'], 2) : $blank,
        '{expiration}' => !empty($t['expiration_date']) ? fmt_date($t['expiration_date']) : $blank,
        // In the client's own time zone, named when it is not the office's (4.1.0).
        '{next_session}' => !empty($next['start_at']) ? zone_date(client_zone($c ?: []), 'l, F j, Y \a\t g:i A' . (client_zone($c ?: []) ? ' T' : ''), strtotime($next['start_at'])) : $blank,
        '{last_missed}' => !empty($missed['start_at']) ? date('l, F j, Y', strtotime($missed['start_at'])) : $blank,
        '{item}' => $v($loan['name'] ?? ''), '{loan_due}' => !empty($loan['due_date']) ? fmt_date($loan['due_date']) : $blank,
        '{org}' => brand('org'),
    ];
}

/** A letter for a client, merged. */
function letter_render(string $key, int $clientId): string {
    return strtr(letter_template($key), letter_values($clientId, $key));
}
