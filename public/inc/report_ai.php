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
    return ['ok' => $res['ok'], 'text' => $res['ok'] ? trim((string)$res['text']) : '', 'error' => $res['ok'] ? null : $res['error']];
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
