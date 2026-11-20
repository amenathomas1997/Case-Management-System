<?php
declare(strict_types=1);
// AI drafting help for every report staff write (owner, 2026-10-16). Each draft goes into
// the form for staff to read and edit; nothing is sent by the AI. Identifiers are masked
// before the text leaves (the report's ai_text placeholders, plus ai_run()'s redaction of
// the client's own details), and aggregate reports send only counts.

/**
 * Barriers and recommendations for a progress or final report, from the report's masked
 * text. Returns ['ok', 'barriers', 'recommendations', 'error'].
 */
function report_ai_sections(string $aiText, array $clientRow, int $actor, bool $final): array {
    $res = ai_run('draft',
        ai_system_base() . ' You help a vocational rehabilitation assistive technology instructor write the '
            . ($final ? 'final report at the close of training' : 'progress report') . ' to the VR counselor. From the report data, write two short sections. '
            . 'Barriers: what is slowing progress or could put the outcome at risk (attendance, skills, equipment, health, transport), one per line, '
            . 'only what the data supports. Recommendations: concrete next steps' . ($final ? ' after closure (further services, follow-up, equipment, supports)' : ' for the next period')
            . ', one per line. Keep placeholders in square brackets as they are and do not invent names, dates or numbers. '
            . 'Answer in exactly this form:' . "\nBARRIERS:\n- ...\nRECOMMENDATIONS:\n- ...",
        "Report data:\n\n" . $aiText, 700, $clientRow, $actor);
    if (!$res['ok']) return ['ok' => false, 'barriers' => '', 'recommendations' => '', 'error' => $res['error']];
    $txt = (string)$res['text'];
    $grab = function (string $label, string $next) use ($txt): string {
        if (!preg_match('/' . $label . ':\s*(.*?)(?=' . $next . ':|\z)/si', $txt, $m)) return '';
        $lines = array_filter(array_map(fn($l) => trim(preg_replace('/^[-*•\d.)\s]+/u', '', $l) ?? ''), preg_split('/\R/', trim($m[1]))), fn($l) => $l !== '');
        return implode("\n", $lines);
    };
    $b = $grab('BARRIERS', 'RECOMMENDATIONS'); $r = $grab('RECOMMENDATIONS', 'BARRIERS');
    if ($b === '' && $r === '') return ['ok' => false, 'barriers' => '', 'recommendations' => '', 'error' => 'The AI answer could not be read as barriers and recommendations. Try again, or write them yourself.'];
    return ['ok' => true, 'barriers' => $b, 'recommendations' => $r, 'error' => null, 'truncated' => !empty($res['truncated'])];
}

/**
 * A short narrative for an aggregate report (outcomes, funder) from its counts only: no
 * client is named in what is sent. Returns ['ok', 'text', 'error'].
 */
function report_ai_narrative(string $reportName, string $facts, int $actor): array {
    $res = ai_run('draft',
        ai_system_base() . ' You help an assistive technology training provider write the narrative summary for its ' . $reportName
            . ' for funders and the board. Write 2 or 3 short plain-language paragraphs from the figures given: what was delivered, the results, and anything notable. '
            . 'Use only the figures given; do not invent numbers, names or trends.',
        $facts, 700, null, $actor);
    return ['ok' => $res['ok'], 'text' => $res['ok'] ? trim((string)$res['text']) : '', 'error' => $res['ok'] ? null : $res['error']];
}

/** Recommendations for a worksite (employer) report from the job tasks and barriers. */
function worksite_ai_recommendations(array $wa, array $clientRow, int $actor): array {
    $facts = 'Job title: ' . ($wa['job_title'] ?: 'not given') . "\nJob tasks:\n" . ($wa['job_tasks'] ?: 'not recorded') . "\nBarriers observed:\n" . ($wa['barriers'] ?: 'not recorded');
    $res = ai_run('draft',
        ai_system_base() . ' You help an assistive technology specialist write the recommendations of a worksite accessibility assessment for an employer. '
            . 'From the job tasks and barriers, list specific, reasonable accommodations (assistive technology, software settings, workstation changes, training), '
            . 'one per line with a short reason. Do not name the employee; do not invent facts.',
        $facts, 700, $clientRow, $actor);
    // truncated (4.37.0): the apps and the page say an incomplete draft is incomplete.
    return ['ok' => $res['ok'], 'text' => $res['ok'] ? trim((string)$res['text']) : '', 'error' => $res['ok'] ? null : $res['error'], 'truncated' => !empty($res['truncated'])];
}

/** The employer's recommendation report for one worksite assessment (4.37.0: moved from
 *  accommodations.php, shared with the app's worksite_report). $wa is the assessment with
 *  client_name and assessor; the caller has checked its scope. Returns ['doc', 'title', 'text' (the
 *  same report as plain text), 'file']. */
function worksite_report_doc(array $wa, array $user): array {
    require_once __DIR__ . '/document.php';
    $title = 'Job Accommodation Recommendations — ' . $wa['client_name'];
    $doc = new AccessibleDocument($title);
    $org = brand('org');
    $doc->paragraph($org);
    $props = array_filter([
        'Client' => (string)$wa['client_name'],
        'Employer' => (string)($wa['employer'] ?? ''),
        'Job title' => (string)($wa['job_title'] ?? ''),
        'Worksite' => (string)($wa['worksite_location'] ?? ''),
        'Assessment date' => !empty($wa['assessment_date']) ? fmt_date($wa['assessment_date']) : '',
        'Assessor' => (string)($wa['assessor'] ?? $user['name'] ?? ''),
    ]);
    $doc->properties($props);
    $lines = [$title, '', $org, '', implode("\n", array_map(fn($k, $v) => $k . ': ' . $v, array_keys($props), $props))];
    if (!empty($wa['job_tasks'])) { $doc->heading('Essential job tasks'); $doc->paragraph((string)$wa['job_tasks']); array_push($lines, '', 'Essential job tasks', (string)$wa['job_tasks']); }
    if (!empty($wa['barriers'])) { $doc->heading('Barriers identified'); $doc->paragraph((string)$wa['barriers']); array_push($lines, '', 'Barriers identified', (string)$wa['barriers']); }
    $recs = !empty($wa['recommendations']) ? (string)$wa['recommendations'] : '[Recommendations to be completed]';
    $doc->heading('Recommended accommodations');
    $doc->paragraph($recs);
    $prepared = 'Prepared ' . date('F j, Y') . ' by ' . ($wa['assessor'] ?? $user['name'] ?? '') . ' — ' . cfg('name');
    $doc->paragraph($prepared);
    array_push($lines, '', 'Recommended accommodations', $recs, '', $prepared);
    // Named for the client and the day, so a saved copy is found by name in a file list.
    $base = trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string)$wa['client_name']) ?? '', '_') ?: (string)(int)$wa['id'];
    return ['doc' => $doc, 'title' => $title, 'text' => implode("\n", $lines), 'file' => 'Job_Accommodation_Recommendations_' . $base . '_' . date('Ymd') . '.html'];
}

/** The longest start of $text that fits $max characters and ends at a sentence (or, failing that, a
 *  line or word) end; $text itself when it fits. */
function text_cut_at_sentence(string $text, int $max): string {
    if (mb_strlen($text) <= $max) return $text;
    $cut = mb_substr($text, 0, $max);
    foreach (['/^(.*[.!?])(?=\s|$)/su', '/^(.*)\n/su', '/^(.*)\s/su'] as $re) {
        if (preg_match($re, $cut, $m) && mb_strlen(trim($m[1])) > 0) return rtrim($m[1]);
    }
    return $cut;
}

/**
 * The "Justification of need" for a device funding request (4.37.0, procurement.php, recommend.php and
 * the apps' ai_draft justification). From the client's disability, latest tech assessment, active
 * goals and IPE goal, the chosen device and whatever is already written. [CLIENT] and the other
 * withheld names are put back. Returns ['ok', 'text', 'error', 'truncated'].
 */
function device_justification_ai(array $client, string $device, string $category, string $typed, int $uid): array {
    $cid = (int)$client['id'];
    $a = db()->prepare('SELECT primary_disability, primary_tech, summary FROM tech_assessments WHERE client_id = ? ORDER BY assessment_date DESC, id DESC LIMIT 1');
    $a->execute([$cid]);
    $as = $a->fetch() ?: [];
    $g = db()->prepare("SELECT sg.specific_goal FROM smart_goals sg JOIN training_tracks t ON t.id = sg.track_id WHERE t.client_id = ? AND sg.status <> 'Achieved' ORDER BY sg.id LIMIT 8");
    $g->execute([$cid]);
    $goals = array_values(array_filter(array_map('strval', $g->fetchAll(PDO::FETCH_COLUMN)), fn($x) => trim($x) !== ''));
    $facts = 'Primary disability / functional limitations: ' . (trim((string)($as['primary_disability'] ?? '')) !== '' ? $as['primary_disability'] : '[not recorded]') . "\n";
    if (!empty($as['summary'])) $facts .= 'Assessment summary: ' . mb_strimwidth((string)$as['summary'], 0, 600, '…') . "\n";
    if ($goals) $facts .= "Active goals:\n - " . implode("\n - ", $goals) . "\n";
    if (trim((string)($client['ipe_goal'] ?? '')) !== '') $facts .= 'Vocational goal (IPE): ' . trim((string)$client['ipe_goal']) . "\n";
    $facts .= 'Device / technology requested: ' . $device . ($category !== '' ? ' (' . $category . ')' : '') . "\n";
    if (trim($typed) !== '') $facts .= "Already written (do not repeat it):\n" . trim($typed) . "\n";
    $res = ai_run('draft',
        ai_system_base() . ' You help an assistive technology specialist write the "Justification of need" for a funding request to a vocational rehabilitation agency. In one or two short paragraphs, explain why the named device is necessary for the client to reach their vocational goal, tied to the disability, the assessment and the goals given. Do not invent facts, prices or names. Bracketed tags such as [CLIENT] stand for names that were withheld: keep them exactly as written.' . ai_plain_text_rule(),
        trim($facts), 600, $client, $uid);
    // asked: the client's facts reached the provider (ai_run() logged the call), so the use is audited.
    $asked = array_key_exists('usage_id', $res);
    if (!$res['ok']) return ['ok' => false, 'text' => '', 'error' => $res['error'], 'truncated' => false, 'asked' => $asked];
    $restore = report_ai_restore_map(['client_name' => (string)($client['name'] ?? ''), 'counselor_name' => (string)($client['counselor_name'] ?? ''), 'cid' => $cid]);
    return ['ok' => true, 'text' => strtr(trim((string)$res['text']), $restore), 'error' => null, 'truncated' => !empty($res['truncated']), 'asked' => $asked];
}

/** A draft added to what was typed, as every AI button does: an empty field gets the draft, else it
 *  goes below the typed text after one line break. */
function ai_merge_draft(string $typed, string $draft): string {
    $typed = rtrim($typed);
    return $typed === '' ? $draft : $typed . "\n" . $draft;
}

/** device_justification_ai()'s draft merged into the typed text and fitted to the field's 4,000
 *  characters, cut at a sentence end when it would not fit. Returns ['merged', 'shortened']. */
function device_justification_fit(array $res, string $typed): array {
    if (empty($res['ok'])) return ['merged' => $typed, 'shortened' => false];
    $merged = ai_merge_draft($typed, (string)$res['text']);
    $fit = text_cut_at_sentence($merged, 4000);
    return ['merged' => $fit, 'shortened' => $fit !== $merged];
}

/** An aggregate report's figures as plain lines for report_ai_narrative() (counts and labels only). */
function report_ai_facts(array $m, string $prefix = ''): string {
    $out = [];
    foreach ($m as $k => $v) {
        $label = $prefix . (is_int($k) ? '' : str_replace('_', ' ', (string)$k));
        if (is_array($v)) {
            $scalar = array_filter($v, fn($x) => !is_array($x));
            if ($scalar && count($scalar) === count($v) && !array_is_list($v)) {
                $out[] = ($label !== '' ? $label . ': ' : '') . implode(', ', array_map(fn($a, $b) => $a . ' ' . $b, array_keys($v), $v));
            } else {
                $sub = report_ai_facts($v, $label !== '' ? $label . ' / ' : '');
                if ($sub !== '') $out[] = $sub;
            }
        } elseif ($v !== null && $v !== '') {
            $out[] = $label . ': ' . (is_float($v) ? round($v, 2) : $v);
        }
    }
    return implode("\n", $out);
}

/** The narrative card shown on an aggregate report page: the draft (if any) and the button. */
function report_ai_narrative_card(string $action, ?string $draft, string $what): string {
    if (!ai_feature_enabled('draft')) return '';
    return '<section class="card" aria-labelledby="ai-narr-h"><h2 id="ai-narr-h">Narrative summary (AI is sent the figures only, never names)</h2>'
        . ($draft !== null ? '<label for="ai-narr">Draft narrative (edit and copy it into your report)</label><textarea id="ai-narr" rows="8" style="width:100%">' . h($draft) . '</textarea>' : '')
        . '<form method="post" action="' . h($action) . '" class="inline-form">' . csrf_field()
        . '<button type="submit" name="ai_narrative" value="1" class="btn btn-secondary btn-sm">Draft a narrative summary of ' . h($what) . ' with AI</button></form>'
        . '</section>';
}

/** The cover note at the top of a progress or final report (4.34.0: moved from report.php, shared
 *  with the app's ai_draft report_intro). The prompt is the report's masked text; the full client
 *  row lets ai_run() also mask the client's own details wherever else they appear. */
function report_ai_cover_note(string $aiText, array $clientRow, int $actor, bool $final): array {
    return ai_run('draft',
        ai_system_base() . ' You are drafting a brief cover note (2–3 short paragraphs) that a VR instructor will put at '
            . 'the top of a ' . ($final ? 'final report at the close of training, summarizing what the client achieved and the outcome' : 'training progress report') . ' to the counselor. Summarize progress and next steps warmly and professionally. '
            . 'Do not restate the tables of hours — those follow below your note. Details in square brackets, such as [CLIENT] '
            . 'or [COUNSELOR], were withheld for privacy: keep them as placeholders and do not invent names, dates or numbers.',
        "Write the cover note for this training progress report.\n\n" . $aiText,
        900, $clientRow, $actor);
}

/** The details withheld from the AI, to put back in its draft (as amendment_request.php does).
 *  $t is build_track_report()'s track row. Empty values are left out, so a placeholder with no
 *  detail on file stays for staff to fill in. */
function report_ai_restore_map(array $t): array {
    $restore = ['[CLIENT]' => (string)($t['client_name'] ?? ''), '[COUNSELOR]' => trim((string)($t['counselor_name'] ?? '')), '[AUTH]' => trim((string)($t['auth_number'] ?? '')),
        '[EMPLOYER]' => (string)(client_last_closure((int)$t['cid'])['employer'] ?? '')];
    return array_filter($restore, fn($v) => $v !== '');
}

/** The first unfilled [PLACEHOLDER] in a cover note (the same test as eval_report.php), or null
 *  (4.34.0: moved from report.php). A screen reader at its default punctuation level does not
 *  voice the brackets, so "[CLIENT]" would sound like a finished word. */
function report_placeholder(string $s): ?string {
    return preg_match('/\[(?=[^\[\]\n]*\p{L})[^\[\]\n]{1,80}\]/u', $s, $m) ? $m[0] : null;
}
