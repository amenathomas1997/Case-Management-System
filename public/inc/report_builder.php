<?php
declare(strict_types=1);
// Builds the full training-track summary report as self-contained HTML (the on-screen
// preview; inline styles on its own light background, so it reads the same in every
// app theme) and as plain text (the emailed report). 'ai_text' is the plain text with
// identifiers replaced by placeholders, for the AI cover-note draft.
// $headingLevel is the level of the report's title heading; its sections sit one level
// below. A page that embeds the HTML under its own headings passes a deeper level.

/* $opts (3.0.47): 'since' (Y-m-d) limits the session log, attendance and goal progress notes to
   the reporting period (totals stay all-time); 'final' makes it the Final Report at closure,
   with skill levels, devices and the closure; 'barriers' and 'recommendations' add those
   sections. */
function build_track_report(int $trackId, string $preparedBy, string $intro = '', int $headingLevel = 1, array $opts = []): ?array {
    $since = isset($opts['since']) && is_string($opts['since']) ? valid_date($opts['since']) : null;
    $final = !empty($opts['final']);
    $barriers = trim((string)($opts['barriers'] ?? ''));
    $recommend = trim((string)($opts['recommendations'] ?? ''));
    $title = $final ? 'Final Report' : 'Training Progress Report';
    $stmt = db()->prepare('SELECT t.*, c.id AS cid, c.name AS client_name, c.dob, c.email AS client_email, c.phone AS client_phone,
            c.status AS client_status, c.counselor_name, c.counselor_email, c.counselor_phone, c.counselor_agency, c.vr_case_number, c.ipe_goal, c.ipe_date, c.ipe_amended_on
        FROM training_tracks t JOIN clients c ON c.id = t.client_id WHERE t.id = ?');
    $stmt->execute([$trackId]);
    $t = $stmt->fetch();
    if (!$t) return null;

    $goals = db()->prepare('SELECT g.*, COALESCE(gh.hrs, 0) AS goal_hours FROM smart_goals g
        LEFT JOIN (SELECT goal_id, SUM(hours) hrs FROM work_logs WHERE goal_id IS NOT NULL GROUP BY goal_id) gh ON gh.goal_id = g.id
        WHERE g.track_id = ? ORDER BY g.target_date IS NULL, g.target_date');
    $goals->execute([$trackId]);
    $goals = $goals->fetchAll();

    $logs = db()->prepare('SELECT * FROM work_logs WHERE track_id = ?' . ($since ? ' AND work_date >= ?' : '') . ' ORDER BY work_date');
    $logs->execute($since ? [$trackId, $since] : [$trackId]);
    $logs = $logs->fetchAll();
    // Goal progress notes and attendance in the reporting period (3.0.47).
    $gp = db()->prepare('SELECT p.*, g.specific_goal FROM goal_progress p JOIN smart_goals g ON g.id = p.goal_id WHERE g.track_id = ?'
        . ($since ? ' AND p.created_at >= ?' : '') . ' ORDER BY p.created_at');
    $gp->execute($since ? [$trackId, $since . ' 00:00:00'] : [$trackId]);
    $progressNotes = $gp->fetchAll();
    $at = db()->prepare("SELECT start_at, status FROM scheduled_sessions WHERE track_id = ? AND start_at < NOW() AND status IN ('Completed','No Show','Cancelled')"
        . ($since ? ' AND start_at >= ?' : '') . ' ORDER BY start_at');
    $at->execute($since ? [$trackId, $since . ' 00:00:00'] : [$trackId]);
    $attendance = $at->fetchAll();

    $r = track_rollup($trackId);
    $achieved = count(array_filter($goals, fn($g) => $g['status'] === 'Achieved'));

    // Colour AND background are set on the wrapper, every table and every cell: the
    // report is a light document, and the app stylesheet's dark theme would otherwise
    // put its dark card/table background behind the report's dark text. (The vision
    // palettes override all of it from the page; see report.php.)
    // Words wrap whole (the page's overflow-wrap:anywhere would break them into
    // fragments in a narrow column): a table wider than the screen scrolls inside its
    // own labelled region instead (.table-scroll, made a tab stop only while it overflows).
    $td = 'padding:8px 10px;border:1px solid #c7c7d1;text-align:left;vertical-align:top;color:#1a1a2e;background:#ffffff;overflow-wrap:normal;word-break:normal;';
    $th = $td . 'background:#eef1f6;font-weight:700;';
    $tdR = $td . 'text-align:right;';
    $hTop = max(1, min(5, $headingLevel));
    $hSec = 'h' . ($hTop + 1);
    // Sizes are relative, so the app's Text size and More text spacing settings apply to
    // the preview too; paragraphs stay under about 80 characters a line.
    $h2 = 'font-size:1.2em;margin:22px 0 8px;color:#1e3a8a;';
    $pW = 'max-width:70ch;';
    // Each table is named by its section heading (ids unique per track on the page).
    $sid = 'rpt' . $trackId . '-';
    $section = fn(string $key, string $labelHtml): string => '<' . $hSec . ' id="' . $sid . $key . '" style="' . $h2 . '">' . $labelHtml . '</' . $hSec . '>';
    $table = fn(string $key): string => '<div class="table-scroll" role="region" tabindex="0" aria-labelledby="' . $sid . $key . '">'
        . '<table aria-labelledby="' . $sid . $key . '" style="border-collapse:collapse;width:100%;background:#ffffff;">';
    $tableEnd = '</table></div>';

    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#1a1a2e;background:#ffffff;padding:16px;border-radius:4px;max-width:52rem;font-size:1rem;">';
    $html .= '<h' . $hTop . ' style="font-size:1.45em;margin:0 0 2px;color:#1e3a8a;">' . h($title) . '</h' . $hTop . '>';
    $html .= '<p style="margin:0 0 16px;color:#3d3d56;' . $pW . '">' . h(brand('org')) . ' &middot; Prepared ' . h(date('F j, Y')) . ' by ' . h($preparedBy) . '</p>';

    if ($intro !== '') {
        $html .= '<p style="margin:0 0 16px;white-space:pre-wrap;' . $pW . '">' . h($intro) . '</p>';
    }

    // These are data tables, not layout: the cell that names a row or column is a
    // header cell with a scope, so a screen reader reads each value with its label.
    $html .= $section('client', 'Client &amp; Authorization');
    $html .= $table('client');
    // Name and agency joined without trim()'s byte mask, which would cut the last byte
    // off a name or agency ending in a multibyte character (e.g. "À", "Д").
    $counselor = implode(' — ', array_filter([trim((string)($t['counselor_name'] ?? '')), trim((string)($t['counselor_agency'] ?? ''))], fn($s) => $s !== ''));
    $rows = [
        ['Client', $t['client_name'] . ($t['dob'] ? ' (date of birth ' . fmt_date($t['dob']) . ')' : '')],
        ['Case status', $t['client_status']],
        ['Vocational Rehabilitation (VR) counselor', $counselor],
        // The plan's goal and the VR case number (3.0.45), when on file.
        ...(trim((string)($t['vr_case_number'] ?? '')) !== '' ? [['VR case number', (string)$t['vr_case_number']]] : []),
        ...(trim((string)($t['ipe_goal'] ?? '')) !== '' ? [['IPE employment goal', $t['ipe_goal'] . ($t['ipe_date'] ? ' (IPE ' . fmt_date($t['ipe_date']) . ($t['ipe_amended_on'] ? ', amended ' . fmt_date($t['ipe_amended_on']) : '') . ')' : '')]] : []),
        ['Training track', $t['title'] . ' (' . $t['status'] . ')'],
        ['Authorization #', $t['auth_number'] . ' — ' . $t['funding_agency']],
        ['Authorization period', fmt_date($t['start_date']) . ' to ' . fmt_date($t['expiration_date'])],
        ...($since ? [['Reporting period', fmt_date($since) . ' to ' . fmt_date(date('Y-m-d'))]] : []),
        ['Authorized', fmt_hours($r['authorized_hours']) . ' at ' . fmt_money($r['hourly_rate']) . ' per hour (' . fmt_money($r['authorized_funds']) . ')'
            . ($r['mileage_rate'] > 0 ? ' plus mileage at ' . fmt_mileage_rate((float)$r['mileage_rate']) . ' per mile' : '')],
    ];
    foreach ($rows as [$k, $v]) {
        $html .= '<tr><th scope="row" style="' . $th . 'width:32%;">' . h($k) . '</th><td style="' . $td . '">' . h($v) . '</td></tr>';
    }
    $html .= $tableEnd;

    // total_billed is the value of ALL logged work (hours and mileage), invoiced or not,
    // so it is labelled that way. Funds remaining is net of that work AND of expenses
    // (billed against the same authorization), so any expenses are shown beside it.
    $expNote = $r['expenses_amount'] > 0 ? ' plus ' . h(fmt_money($r['expenses_amount'])) . ' in expenses' : '';
    $html .= $section('usage', 'Usage Summary');
    $html .= $table('usage');
    $html .= '<tr>'
        . '<th scope="col" style="' . $th . '">Hours used</th><th scope="col" style="' . $th . '">Hours remaining</th>'
        . '<th scope="col" style="' . $th . '">Value of work logged</th><th scope="col" style="' . $th . '">Funds remaining</th><th scope="col" style="' . $th . '">Goals achieved</th></tr>';
    $html .= '<tr>'
        . '<td style="' . $td . '">' . h(fmt_hours($r['hours_worked'])) . ' (' . h((string)$r['pct_used']) . '%)</td>'
        . '<td style="' . $td . '">' . h(fmt_hours($r['hours_remaining'])) . '</td>'
        . '<td style="' . $td . '">' . h(fmt_money($r['total_billed'])) . $expNote . '</td>'
        . '<td style="' . $td . '">' . h(fmt_money($r['funds_remaining'])) . '</td>'
        . '<td style="' . $td . '">' . $achieved . ' of ' . count($goals) . '</td></tr>';
    $html .= $tableEnd;

    $html .= $section('goals', 'SMART Goals');
    $html .= '<p style="margin:0 0 8px;' . $pW . '">SMART goals are Specific, Measurable, Achievable, Relevant and Time-bound.</p>';
    if (!$goals) {
        $html .= '<p>No goals recorded for this track.</p>';
    } else {
        $html .= $table('goals');
        $html .= '<tr><th scope="col" style="' . $th . '">Specific objective</th><th scope="col" style="' . $th . '">Measurable criteria</th><th scope="col" style="' . $th . '">Focus area</th><th scope="col" style="' . $th . '">Hours</th><th scope="col" style="' . $th . '">Target date</th><th scope="col" style="' . $th . '">Status</th><th scope="col" style="' . $th . '">Progress</th></tr>';
        foreach ($goals as $g) {
            $html .= '<tr><th scope="row" style="' . $td . 'font-weight:400;">' . h($g['specific_goal']) . '</th>'
                . '<td style="' . $td . '">' . h($g['measurable_metric']) . '</td>'
                . '<td style="' . $td . '">' . h($g['focus_area'] ?? '—') . '</td>'
                . '<td style="' . $tdR . '">' . ((float)$g['goal_hours'] > 0 ? h(number_format((float)$g['goal_hours'], 2)) : '—') . '</td>'
                . '<td style="' . $td . '">' . h(fmt_date($g['target_date'])) . '</td>'
                . '<td style="' . $td . '">' . h($g['status']) . '</td>'
                . '<td style="' . $tdR . '">' . (int)($g['progress_pct'] ?? 0) . '%</td></tr>';
        }
        $html .= $tableEnd;
    }
    // Progress recorded on the goals in the period, and attendance (3.0.47).
    $extra = report_extra_sections($t, $progressNotes, $attendance, $since, $final, $barriers, $recommend);
    foreach ($extra as [$key, $label, $lines]) {
        $html .= $section($key, h($label));
        $html .= '<ul style="margin:0 0 8px;padding-left:1.4em;' . $pW . '">';
        foreach ($lines as $ln) $html .= '<li>' . h($ln) . '</li>';
        $html .= '</ul>';
    }

    $html .= $section('log', $since ? 'Session Log for the Period' : 'Session Log');
    if (!$logs) {
        $html .= '<p>No sessions logged for this track.</p>';
    } else {
        $html .= $table('log');
        $html .= '<tr><th scope="col" style="' . $th . '">Date</th><th scope="col" style="' . $th . 'text-align:right;">Hours</th><th scope="col" style="' . $th . 'text-align:right;">Mileage</th><th scope="col" style="' . $th . '">Activity</th><th scope="col" style="' . $th . 'text-align:right;">Amount</th></tr>';
        foreach ($logs as $l) {
            // Each line is rounded to the cent as track_rollup() and invoices round it,
            // so the lines add up to the total below.
            $lineAmt = log_line_amounts($l, $r['hourly_rate'], $r['mileage_rate'])['total'];
            $html .= '<tr><th scope="row" style="' . $td . 'font-weight:400;">' . h(fmt_date($l['work_date'])) . '</th>'
                . '<td style="' . $tdR . '">' . h(number_format((float)$l['hours'], 2)) . '</td>'
                . '<td style="' . $tdR . '">' . ((float)$l['mileage'] > 0 ? h(number_format((float)$l['mileage'], 1)) : '—') . '</td>'
                . '<td style="' . $td . '">' . h($l['description']) . '</td>'
                . '<td style="' . $tdR . '">' . h(fmt_money($lineAmt)) . '</td></tr>';
        }
        $html .= '<tr><th scope="row" style="' . $th . '">Total</th>'
            . '<td style="' . $th . 'text-align:right;">' . h(number_format($r['hours_worked'], 2)) . '</td>'
            . '<td style="' . $th . 'text-align:right;">' . h(number_format($r['mileage'], 1)) . '</td>'
            . '<td style="' . $th . '"></td>'
            . '<td style="' . $th . 'text-align:right;">' . h(fmt_money($r['total_billed'])) . '</td></tr>';
        $html .= $tableEnd;
    }

    $html .= '<p style="margin-top:20px;color:#3d3d56;font-size:.875em;' . $pW . '">This report was generated by ' . h(cfg('name')) . '. Questions? Reply to this email to reach our office.</p>';
    $html .= '</div>';

    // Plain-text rendering — sent via the same mail path as OTP codes for reliable delivery.
    // Built as the lines before the client & authorization rows ($head), those rows,
    // and the lines after them ($L), so the AI version can swap in masked rows.
    $head = [strtoupper($title), brand('org'), 'Prepared ' . date('F j, Y') . ' by ' . $preparedBy, ''];
    if ($intro !== '') { $head[] = $intro; $head[] = ''; }
    $head[] = '=== CLIENT & AUTHORIZATION ===';
    $L = [];
    $L[] = '';
    $L[] = '=== USAGE SUMMARY ===';
    $L[] = 'Hours used: ' . fmt_hours($r['hours_worked']) . ' of ' . fmt_hours($r['authorized_hours']) . ' (' . $r['pct_used'] . '%)';
    $L[] = 'Hours remaining: ' . fmt_hours($r['hours_remaining']);
    $L[] = 'Value of work logged: ' . fmt_money($r['total_billed'])
        . ($r['mileage_amount'] > 0 ? ' (includes ' . fmt_money($r['mileage_amount']) . ' mileage)' : '');
    if ($r['expenses_amount'] > 0) $L[] = 'Expenses: ' . fmt_money($r['expenses_amount']);
    $L[] = 'Funds remaining: ' . fmt_money($r['funds_remaining']);
    $L[] = 'Goals achieved: ' . $achieved . ' of ' . count($goals);
    $L[] = '';
    $L[] = '=== SMART GOALS (Specific, Measurable, Achievable, Relevant, Time-bound) ===';
    if (!$goals) $L[] = 'No goals recorded for this track.';
    foreach ($goals as $i => $g) {
        $L[] = ($i + 1) . '. [' . $g['status'] . '] ' . $g['specific_goal'];
        $L[] = '   Measure: ' . $g['measurable_metric'];
        $L[] = '   Focus: ' . ($g['focus_area'] ?? '—') . '  |  Target: ' . fmt_date($g['target_date'])
            . ((float)$g['goal_hours'] > 0 ? '  |  Hours toward this goal: ' . number_format((float)$g['goal_hours'], 2) : '')
            . '  |  Progress: ' . (int)($g['progress_pct'] ?? 0) . '%';
    }
    foreach ($extra as [, $label, $lines]) {
        $L[] = '';
        $L[] = '=== ' . strtoupper($label) . ' ===';
        foreach ($lines as $ln) $L[] = '- ' . $ln;
    }
    $L[] = '';
    $L[] = $since ? '=== SESSION LOG FOR THE PERIOD ===' : '=== SESSION LOG ===';
    if (!$logs) $L[] = 'No sessions logged for this track.';
    foreach ($logs as $l) {
        $L[] = '- ' . fmt_date($l['work_date']) . ' | ' . number_format((float)$l['hours'], 2) . ' hours'
            . ((float)$l['mileage'] > 0 ? ' | ' . number_format((float)$l['mileage'], 1) . ' miles' : '')
            . ' | ' . fmt_money(log_line_amounts($l, $r['hourly_rate'], $r['mileage_rate'])['total'])
            . ' | ' . $l['description'];
    }
    $L[] = '';
    $L[] = 'TOTALS: ' . number_format($r['hours_worked'], 2) . ' hours | '
        . number_format($r['mileage'], 1) . ' miles | ' . fmt_money($r['total_billed']) . ' value of work logged';
    $L[] = '';
    $L[] = 'This report was generated by ' . cfg('name') . '. Reply to this email to reach our office.';
    $plain = fn(array $kv): string => implode("\n", array_merge($head, array_map(fn($row) => $row[0] . ': ' . $row[1], $kv), $L));

    // For the AI cover-note draft: the client's name and DOB, the counselor's name and
    // agency, and the authorization number become placeholders before the text leaves
    // (ai_run()'s redaction masks the client's own fields, not these).
    $aiMask = ['Client' => '[CLIENT]', 'Vocational Rehabilitation (VR) counselor' => $counselor !== '' ? '[COUNSELOR]' : '',
        'Authorization #' => '[AUTH] — ' . $t['funding_agency'], 'VR case number' => '[CASE NUMBER]'];
    $aiRows = array_map(fn($row) => array_key_exists($row[0], $aiMask) ? [$row[0], $aiMask[$row[0]]] : $row, $rows);

    return [
        'html' => $html,
        'text' => $plain($rows),
        'ai_text' => strtr($plain($aiRows), array_merge(...array_map(fn($x) => $x[3] ?? [], $extra ?: [[]]))),
        // No client name in the subject (3.0.36): the authorization number identifies the case.
        'subject' => $title . ' — Authorization ' . $t['auth_number'],
        'track' => $t,
    ];
}

/**
 * The report's added sections (3.0.47), each [key, label, lines]: progress recorded on the
 * goals and attendance (for the period when there is one), barriers and recommendations
 * when given, and for a final report the closure, skill levels and devices.
 */
function report_extra_sections(array $t, array $progressNotes, array $attendance, ?string $since, bool $final, string $barriers, string $recommend): array {
    $out = [];
    if ($progressNotes) {
        $out[] = ['progress', 'Goal progress' . ($since ? ' this period' : ''), array_map(fn($p) => fmt_date(substr((string)$p['created_at'], 0, 10)) . ': '
            . $p['specific_goal'] . ' — ' . $p['status'] . ', ' . (int)$p['progress_pct'] . '%' . ($p['note'] ? '. ' . $p['note'] : ''), $progressNotes)];
    }
    if ($attendance) {
        $n = fn(string $st) => count(array_filter($attendance, fn($a) => $a['status'] === $st));
        $lines = [$n('Completed') . ' attended, ' . $n('No Show') . ' missed, ' . $n('Cancelled') . ' cancelled.'];
        foreach ($attendance as $a) if ($a['status'] !== 'Completed') $lines[] = fmt_date(substr((string)$a['start_at'], 0, 10)) . ': ' . ($a['status'] === 'No Show' ? 'missed' : 'cancelled');
        $out[] = ['attendance', 'Attendance' . ($since ? ' this period' : ''), $lines];
    }
    if ($barriers !== '') $out[] = ['barriers', 'Barriers', preg_split('/\R+/', $barriers)];
    if ($recommend !== '') $out[] = ['recommend', 'Recommendations', preg_split('/\R+/', $recommend)];
    if ($final) {
        $cid = (int)$t['cid'];
        // Only this episode's closure: a final report written before the case closes has none yet.
        $cl = function_exists('client_last_closure') && str_starts_with((string)$t['client_status'], 'Closed') ? client_last_closure($cid) : null;
        if ($cl) {
            $o = ['Reason: ' . $cl['reason'] . ($cl['note'] ? ' (' . $cl['note'] . ')' : '') . ', ' . fmt_date(substr((string)$cl['closed_at'], 0, 10)) . '.'];
            if ($cl['employed'] !== null) $o[] = (int)$cl['employed'] === 1 ? 'Employed' . ($cl['employer'] ? ' at ' . $cl['employer'] : '') . ($cl['job_title'] ? ' as ' . $cl['job_title'] : '')
                . ($cl['hours_per_week'] !== null ? ', ' . (float)$cl['hours_per_week'] . ' hours a week' : '') . ($cl['hourly_wage'] !== null ? ' at ' . fmt_money((float)$cl['hourly_wage']) . ' an hour' : '') . '.' : 'Not employed at closure.';
            if ($cl['independence_outcome']) $o[] = 'Independence outcome: ' . $cl['independence_outcome'] . '.';
            // For the AI draft, the employer and the closure note become placeholders (they can
            // identify the client); the wage and hours stay as plain figures.
            $mask = array_filter([(string)$cl['employer'] => '[EMPLOYER]', (string)$cl['note'] => '[CLOSURE NOTE]'], fn($v, $k) => trim((string)$k) !== '', ARRAY_FILTER_USE_BOTH);
            $out[] = ['closure', 'Closure and outcome', $o, $mask];
        }
        // Skill levels: the first and the latest rating of each skill.
        $q = db()->prepare('SELECT s.skill, c.level, c.assessed_at FROM client_competency c JOIN curriculum_skills s ON s.id = c.skill_id WHERE c.client_id = ? ORDER BY c.assessed_at, c.id');
        $q->execute([$cid]);
        $first = []; $last = [];
        foreach ($q->fetchAll() as $c) { $first[$c['skill']] ??= (int)$c['level']; $last[$c['skill']] = (int)$c['level']; }
        if ($first) $out[] = ['skills', 'Skill levels, first and latest', array_map(fn($k) => $k . ': ' . $first[$k] . ' to ' . $last[$k], array_keys($first))];
        $q = db()->prepare('SELECT i.name, a.assessed_on, a.pct FROM client_scored_assessments a JOIN assessment_instruments i ON i.id = a.instrument_id WHERE a.client_id = ? ORDER BY a.assessed_on, a.id');
        $q->execute([$cid]);
        $sc = [];
        foreach ($q->fetchAll() as $a) $sc[$a['name']][] = $a;
        $lines = [];
        foreach ($sc as $name => $rows) $lines[] = $name . ': ' . (float)$rows[0]['pct'] . '% (' . fmt_date($rows[0]['assessed_on']) . ')' . (count($rows) > 1 ? ' to ' . (float)end($rows)['pct'] . '% (' . fmt_date(end($rows)['assessed_on']) . ')' : '');
        if ($lines) $out[] = ['scores', 'Scored assessments, first and latest', $lines];
        $q = db()->prepare('SELECT e.name, l.loaned_at, l.returned_at FROM equipment_loans l JOIN equipment e ON e.id = l.equipment_id WHERE l.client_id = ? ORDER BY l.loaned_at');
        $q->execute([$cid]);
        $dev = array_map(fn($l) => $l['name'] . ', loaned ' . fmt_date($l['loaned_at']) . ($l['returned_at'] ? ', returned ' . fmt_date($l['returned_at']) : ', still with the client'), $q->fetchAll());
        if ($dev) $out[] = ['devices', 'Devices provided', $dev];
    }
    return $out;
}
