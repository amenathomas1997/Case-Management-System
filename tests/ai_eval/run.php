<?php
declare(strict_types=1);
// AI evaluation of the case-note generator.
//
//   php tests/ai_eval/run.php
//       Offline (the default, part of tests/run_all.sh): checks cases.json and checks the
//       scorer against the sample replies in fixtures/ (a good reply must pass, a bad one
//       must fail for the reasons listed in its "expect_problems"). No AI call is made.
//
//   php tests/ai_eval/run.php --live --inc=/path/to/docroot/inc --actor=<staff user id>
//       Live: runs every case through ai_generate_case_note() on that installation (a
//       test or staging copy with AI turned on: each case is a paid provider call, and
//       the notes are synthetic) and prints each case's problems. Use it before changing
//       the prompt or the model, and compare the pass count with the last run.
require __DIR__ . '/score.php';
$opts = getopt('', ['live', 'inc:', 'actor:']);
$data = json_decode((string)file_get_contents(__DIR__ . '/cases.json'), true);
$fail = 0;
$check = function (bool $ok, string $label) use (&$fail): void { if ($ok) echo "PASS: $label\n"; else { echo "FAIL: $label\n"; $fail++; } };

$cases = is_array($data['cases'] ?? null) ? $data['cases'] : [];
$check(count($cases) >= 8, 'cases.json holds at least 8 cases');
$ids = [];
foreach ($cases as $c) {
    $ok = is_string($c['id'] ?? null) && in_array($c['format'] ?? '', ['BIRP', 'PAIP', 'SOAP'], true) && is_string($c['notes'] ?? null)
        && is_array($c['must_include'] ?? null) && is_array($c['must_not_include'] ?? null) && isset($c['homework_min'], $c['homework_max']);
    $check($ok && !isset($ids[$c['id']]), 'case ' . ($c['id'] ?? '?') . ' is complete and its id unique');
    // Every expected fact must be in the notes themselves, or the case tests nothing.
    foreach ($c['must_include'] ?? [] as $fact) $check(ai_eval_has((string)$c['notes'], $fact), "case {$c['id']}: expected fact \"$fact\" is in its notes");
    $ids[$c['id'] ?? ''] = $c;
}

if (!isset($opts['live'])) {
    foreach (glob(__DIR__ . '/fixtures/*.json') ?: [] as $f) {
        $fx = json_decode((string)file_get_contents($f), true);
        $case = $ids[$fx['case'] ?? ''] ?? null;
        if (!$case) { $check(false, basename($f) . ' names a case in cases.json'); continue; }
        $problems = ai_eval_score($case, $fx['result']);
        $expected = $fx['expect_problems'] ?? [];
        if (!$expected) { $check($problems === [], basename($f) . ' (a good reply) passes' . ($problems ? ': ' . implode('; ', $problems) : '')); continue; }
        foreach ($expected as $want) {
            $hit = (bool)array_filter($problems, fn($p) => str_contains($p, $want));
            $check($hit, basename($f) . " (a bad reply) is caught: $want");
        }
    }
    echo $fail ? "$fail AI evaluation check(s) failed.\n" : "All AI evaluation checks passed (offline).\n";
    exit($fail ? 1 : 0);
}

// Live run against an installation.
$inc = rtrim((string)($opts['inc'] ?? ''), '/');
$actor = (int)($opts['actor'] ?? 0);
if ($inc === '' || !is_file($inc . '/core.php') || $actor <= 0) { fwrite(STDERR, "Usage: php tests/ai_eval/run.php --live --inc=<docroot>/inc --actor=<staff user id>\n"); exit(2); }
require $inc . '/core.php';
require_once $inc . '/ai.php';
if (!ai_enabled()) { fwrite(STDERR, "AI is not configured on this installation.\n"); exit(2); }
$passed = 0;
foreach ($cases as $c) {
    $r = ai_generate_case_note((string)$c['notes'], (string)$c['format'], null, $actor);
    $problems = ai_eval_score($c, $r);
    if (!$problems) { $passed++; echo "PASS: {$c['id']}\n"; }
    else { echo "FAIL: {$c['id']}\n"; foreach ($problems as $p) echo "  - $p\n"; }
}
echo "$passed of " . count($cases) . " cases passed (" . date('Y-m-d H:i') . ").\n";
exit($passed === count($cases) ? 0 : 1);
