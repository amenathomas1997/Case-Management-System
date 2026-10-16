<?php
declare(strict_types=1);
// Builds the full training-track summary report as self-contained HTML (the on-screen
// preview; inline styles on its own light background, so it reads the same in every
// app theme) and as plain text (the emailed report). 'ai_text' is the plain text with
// identifiers replaced by placeholders, for the AI cover-note draft.
// $headingLevel is the level of the report's title heading; its sections sit one level
// below. A page that embeds the HTML under its own headings passes a deeper level.

function build_track_report(int $trackId, string $preparedBy, string $intro = '', int $headingLevel = 1): ?array {
    $stmt = db()->prepare('SELECT t.*, c.id AS cid, c.name AS client_name, c.dob, c.email AS client_email, c.phone AS client_phone,
            c.status AS client_status, c.counselor_name, c.counselor_email, c.counselor_phone, c.counselor_agency
        FROM training_tracks t JOIN clients c ON c.id = t.client_id WHERE t.id = ?');
    $stmt->execute([$trackId]);
    $t = $stmt->fetch();
    if (!$t) return null;

    $goals = db()->prepare('SELECT g.*, COALESCE(gh.hrs, 0) AS goal_hours FROM smart_goals g
        LEFT JOIN (SELECT goal_id, SUM(hours) hrs FROM work_logs WHERE goal_id IS NOT NULL GROUP BY goal_id) gh ON gh.goal_id = g.id
        WHERE g.track_id = ? ORDER BY g.target_date IS NULL, g.target_date');
    $goals->execute([$trackId]);
    $goals = $goals->fetchAll();

    $logs = db()->prepare('SELECT * FROM work_logs WHERE track_id = ? ORDER BY work_date');
    $logs->execute([$trackId]);
    $logs = $logs->fetchAll();

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
    $html .= '<h' . $hTop . ' style="font-size:1.45em;margin:0 0 2px;color:#1e3a8a;">Training Progress Report</h' . $hTop . '>';
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
        ['Training track', $t['title'] . ' (' . $t['status'] . ')'],
        ['Authorization #', $t['auth_number'] . ' — ' . $t['funding_agency']],
        ['Authorization period', fmt_date($t['start_date']) . ' to ' . fmt_date($t['expiration_date'])],
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
        $html .= '<tr><th scope="col" style="' . $th . '">Specific objective</th><th scope="col" style="' . $th . '">Measurable criteria</th><th scope="col" style="' . $th . '">Focus area</th><th scope="col" style="' . $th . '">Hours</th><th scope="col" style="' . $th . '">Target date</th><th scope="col" style="' . $th . '">Status</th></tr>';
        foreach ($goals as $g) {
            $html .= '<tr><th scope="row" style="' . $td . 'font-weight:400;">' . h($g['specific_goal']) . '</th>'
                . '<td style="' . $td . '">' . h($g['measurable_metric']) . '</td>'
                . '<td style="' . $td . '">' . h($g['focus_area'] ?? '—') . '</td>'
                . '<td style="' . $tdR . '">' . ((float)$g['goal_hours'] > 0 ? h(number_format((float)$g['goal_hours'], 2)) : '—') . '</td>'
                . '<td style="' . $td . '">' . h(fmt_date($g['target_date'])) . '</td>'
                . '<td style="' . $td . '">' . h($g['status']) . '</td></tr>';
        }
        $html .= $tableEnd;
    }

    $html .= $section('log', 'Session Log');
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
    $head = ['TRAINING PROGRESS REPORT', brand('org'), 'Prepared ' . date('F j, Y') . ' by ' . $preparedBy, ''];
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
            . ((float)$g['goal_hours'] > 0 ? '  |  Hours toward this goal: ' . number_format((float)$g['goal_hours'], 2) : '');
    }
    $L[] = '';
    $L[] = '=== SESSION LOG ===';
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
        'Authorization #' => '[AUTH] — ' . $t['funding_agency']];
    $aiRows = array_map(fn($row) => array_key_exists($row[0], $aiMask) ? [$row[0], $aiMask[$row[0]]] : $row, $rows);

    return [
        'html' => $html,
        'text' => $plain($rows),
        'ai_text' => $plain($aiRows),
        // No client name in the subject (3.0.36): the authorization number identifies the case.
        'subject' => 'Training Progress Report — Authorization ' . $t['auth_number'],
        'track' => $t,
    ];
}
