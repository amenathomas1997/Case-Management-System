<?php
declare(strict_types=1);
// Scores one ai_generate_case_note() result against one case of cases.json. Returns the
// list of problems found ([] when the note passes). Each check is a plain rule a
// reviewer would apply by hand, so a failure says what to look at.

/** "a|b" matches when either alternative occurs (case-insensitive). */
function ai_eval_has(string $haystack, string $alternatives): bool {
    foreach (explode('|', $alternatives) as $a) if ($a !== '' && mb_stripos($haystack, $a) !== false) return true;
    return false;
}

function ai_eval_score(array $case, array $result): array {
    $p = [];
    if (empty($result['ok'])) return ['the generator failed: ' . (string)($result['error'] ?? 'no error given')];
    $sections = array_map('strval', $result['sections'] ?? []);
    $note = implode("\n", $sections);
    foreach ($sections as $i => $s) if (trim($s) === '') $p[] = 'section ' . ($i + 1) . ' is empty';
    foreach ($case['must_include'] ?? [] as $fact) if (!ai_eval_has($note, $fact)) $p[] = "a fact from the notes is missing: \"$fact\"";
    foreach ($case['must_not_include'] ?? [] as $bad) if (ai_eval_has($note, $bad)) $p[] = "something not in the notes was added: \"$bad\"";
    // Numbers: every number in the note must come from the notes (a [bracketed]
    // placeholder may hold anything). An invented count, score or date shows up here.
    $plain = preg_replace('/\[[^\]]*\]/u', '', $note) ?? $note;
    preg_match_all('/\d+(?:\.\d+)?/', (string)$case['notes'], $m); $given = array_flip($m[0]);
    preg_match_all('/\d+(?:\.\d+)?/', $plain, $m2);
    foreach (array_unique($m2[0]) as $n) if (!isset($given[$n])) $p[] = "a number not in the notes appears: $n";
    $hw = $result['homework'] ?? [];
    $count = count($hw);
    if ($count < (int)($case['homework_min'] ?? 0) || $count > (int)($case['homework_max'] ?? 99)) {
        $p[] = "homework items: $count (expected " . (int)($case['homework_min'] ?? 0) . ' to ' . (int)($case['homework_max'] ?? 99) . ')';
    }
    $hwText = implode("\n", array_map(fn($h) => ($h['title'] ?? '') . ' ' . ($h['instructions'] ?? ''), $hw));
    foreach ($case['homework_mentions'] ?? [] as $want) if (!ai_eval_has($hwText, $want)) $p[] = "the homework does not mention \"$want\"";
    // A gap in the notes must be flagged, never filled in: a question or a placeholder.
    if (!empty($case['expect_gap']) && !($result['questions'] ?? []) && !preg_match('/\[[^\]]+\]/u', $note)) {
        $p[] = 'the notes leave a gap (' . ($case['gap_note'] ?? 'see the case') . ') but no question or [placeholder] flags it';
    }
    return $p;
}
