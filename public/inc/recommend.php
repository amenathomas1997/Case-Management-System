<?php
declare(strict_types=1);
// The AT recommendation engine's context and prompt (4.37.0: moved from recommend.php, shared with
// the apps' ai_recommend), so both send the AI the same facts and get the same kind of answer.

/** The default context sent to the AI, from what the system knows (staff can edit it before sending):
 *  the disability and latest assessment, technology tried and used, a screen reader, up to 8 active
 *  goals, and the latest worksite's job title and essential tasks. */
function recommend_context(array $client): string {
    $cid = (int)$client['id'];
    $ctx = '';
    $a = db()->prepare("SELECT primary_disability, primary_tech, hardware_details, software_version, summary FROM tech_assessments WHERE client_id = ? ORDER BY assessment_date DESC LIMIT 1");
    $a->execute([$cid]); $as = $a->fetch();
    $ctx .= 'Primary disability / functional limitations: ' . ($as['primary_disability'] ?? '[not recorded]') . "\n";
    if (!empty($as['summary'])) $ctx .= 'Assessment summary: ' . mb_strimwidth((string)$as['summary'], 0, 600, '…') . "\n";
    if (!empty($as['primary_tech'])) $ctx .= 'Technology already tried/used: ' . $as['primary_tech'] . "\n";
    if (!empty($client['a11y_primary_tech'])) $ctx .= 'Current assistive technology: ' . $client['a11y_primary_tech'] . "\n";
    if (!empty($client['a11y_screen_reader'])) $ctx .= "Uses a screen reader.\n";
    // Active goals
    $g = db()->prepare("SELECT sg.specific_goal FROM smart_goals sg JOIN training_tracks t ON t.id = sg.track_id WHERE t.client_id = ? AND sg.status <> 'Achieved' LIMIT 8");
    $g->execute([$cid]); $goals = $g->fetchAll(PDO::FETCH_COLUMN);
    if ($goals) $ctx .= "Goals:\n - " . implode("\n - ", $goals) . "\n";
    // Latest worksite job tasks, if any
    $w = db()->prepare('SELECT job_title, job_tasks FROM worksite_assessments WHERE client_id = ? ORDER BY updated_at DESC LIMIT 1');
    $w->execute([$cid]); $ws = $w->fetch();
    if ($ws) { if (!empty($ws['job_title'])) $ctx .= 'Job title: ' . $ws['job_title'] . "\n"; if (!empty($ws['job_tasks'])) $ctx .= 'Essential job tasks: ' . mb_strimwidth((string)$ws['job_tasks'], 0, 500, '…') . "\n"; }
    return trim($ctx);
}

/** The recommendations for a client from a context (recommend_context() or as staff edited it).
 *  Returns ai_run()'s ['ok', 'text', 'error', 'truncated']. */
function recommend_ai(array $client, string $context, int $uid): array {
    $res = ai_run('recommend',
        ai_system_base() . ' You are an assistive technology (AT) specialist supporting a vocational rehabilitation provider. '
            . 'From the disability, functional limitations, goals and any job tasks provided, recommend specific assistive technologies. '
            . 'For each recommendation give: the AT category and one or two concrete example products; a one-line rationale tied to the '
            . 'functional need; and whether a hands-on trial or formal evaluation is advisable. Order by priority. Be concrete and realistic, '
            . 'never invent facts about the person, and where key information is missing say what to assess. Output a short numbered list, '
            . 'one recommendation per number.' . ai_plain_text_rule(),
        "Recommend assistive technology for this client:\n\n" . $context, 1600, $client, $uid);
    return ['ok' => (bool)$res['ok'], 'text' => $res['ok'] ? (string)$res['text'] : '', 'error' => $res['ok'] ? null : (string)$res['error'], 'truncated' => !empty($res['truncated'])];
}
