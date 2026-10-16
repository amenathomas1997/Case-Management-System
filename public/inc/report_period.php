<?php
declare(strict_types=1);
/*
 * Shared report period picker (outcomes, funder report, agency summary, RSA-911,
 * utilization, capacity, risk flags). One GET form — a preset (This month, Last
 * month, This quarter, Fiscal year to date, Last 12 months) or Custom from–to —
 * and the last choice is remembered per user (settings: report_period_<uid>), so
 * every report opens on the same period until the user changes it.
 *
 * Each page keeps its own parameter names: ?from=…&to=… on a link or bookmark
 * still works (and is not remembered — only a submitted form, which carries
 * `period`, is). When nothing was chosen or remembered, the page's own default
 * period applies, so reports open as they always did.
 */

const REPORT_PERIOD_PRESETS = [
    'this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter',
    'fytd' => 'Fiscal year to date', 'last_fy' => 'Last fiscal year', 'last_12' => 'Last 12 months', 'custom' => 'Custom dates',
];

/** [from, to] for a dated preset by today's date; null for 'custom' or an unknown key.
 *  The fiscal year starts in the month set under Administration (October, the federal year, by default). */
function report_period_dates(string $preset): ?array {
    $y = (int)date('Y'); $m = (int)date('n');
    switch ($preset) {
        case 'this_month': return [date('Y-m-01'), date('Y-m-t')];
        case 'last_month': $t = strtotime('first day of last month'); return [date('Y-m-01', $t), date('Y-m-t', $t)];
        case 'this_quarter':
            $qm = (int)(floor(($m - 1) / 3) * 3 + 1);
            return [sprintf('%04d-%02d-01', $y, $qm), date('Y-m-t', mktime(0, 0, 0, $qm + 2, 1, $y))];
        // The organisation's fiscal year (3.0.49: Administration sets the month it starts).
        case 'fytd': return [fiscal_year_of(date('Y-m-d'))[0], date('Y-m-d')];
        case 'last_fy': return fiscal_year_of(date('Y-m-d', strtotime(fiscal_year_of(date('Y-m-d'))[0] . ' -1 day')));
        case 'last_12': return [date('Y-m-01', strtotime('-11 months', strtotime(date('Y-m-01')))), date('Y-m-d')];
    }
    return null;
}

/**
 * The period a report shows: ['from', 'to', 'preset', 'notes'].
 * $defaultFrom / $defaultTo are the page's own default dates (used when nothing was
 * chosen on this request and nothing is remembered). 'notes' says in words when a
 * date could not be read or the two were swapped (as outcomes.php said them).
 */
function report_period(array $user, string $defaultFrom, string $defaultTo): array {
    $uid = (int)($user['id'] ?? 0);
    $key = 'report_period_' . $uid;
    $notes = [];
    $rawPreset = is_string($_GET['period'] ?? null) ? $_GET['period'] : null;
    $preset = $rawPreset !== null && isset(REPORT_PERIOD_PRESETS[$rawPreset]) ? $rawPreset : null;
    $rawFrom = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : '';
    $rawTo = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : '';
    // A repeated parameter (from[]=…) arrives as an array: unreadable, so it is said.
    foreach (['from' => 'From', 'to' => 'To'] as $k => $label) {
        $raw = $_GET[$k] ?? '';
        if (!is_string($raw) || (trim($raw) !== '' && valid_date($raw) === null)) $notes[] = 'The ' . $label . ' date could not be read.';
    }
    $gotFrom = valid_date($rawFrom); $gotTo = valid_date($rawTo);
    $remember = null;
    if ($preset !== null && $preset !== 'custom') {
        [$from, $to] = report_period_dates($preset);
        $remember = ['preset' => $preset];
    } elseif ($gotFrom !== null || $gotTo !== null) {
        // Custom dates, from the form or from a link; one missing date takes the
        // remembered custom date for that end, else the page's default.
        $saved = report_period_saved($key);
        $from = $gotFrom ?? (($saved['preset'] ?? '') === 'custom' ? valid_date($saved['from'] ?? '') : null) ?? $defaultFrom;
        $to = $gotTo ?? (($saved['preset'] ?? '') === 'custom' ? valid_date($saved['to'] ?? '') : null) ?? $defaultTo;
        $preset = 'custom';
        if ($rawPreset !== null) $remember = ['preset' => 'custom', 'from' => $from, 'to' => $to];
    } else {
        $saved = report_period_saved($key);
        $savedPreset = (string)($saved['preset'] ?? '');
        if ($savedPreset === 'custom' && valid_date($saved['from'] ?? '') && valid_date($saved['to'] ?? '')) {
            $from = $saved['from']; $to = $saved['to']; $preset = 'custom';
        } elseif ($savedPreset !== '' && ($d = report_period_dates($savedPreset)) !== null) {
            [$from, $to] = $d; $preset = $savedPreset;
        } else {
            $from = $defaultFrom; $to = $defaultTo; $preset = 'custom';
        }
    }
    if ($to < $from) { [$from, $to] = [$to, $from]; $notes[] = 'The From date was after the To date, so the two were swapped.'; }
    if ($remember !== null) {
        if ($remember['preset'] === 'custom') { $remember['from'] = $from; $remember['to'] = $to; }
        $json = json_encode($remember);
        // Written only when the choice changed, and never a reason for a report to fail.
        try { if ($uid > 0 && setting($key) !== $json) set_setting($key, $json); } catch (Throwable $e) {}
    }
    return ['from' => $from, 'to' => $to, 'preset' => $preset, 'notes' => $notes];
}

/** The remembered choice, or [] when none (or unreadable). */
function report_period_saved(string $key): array {
    $raw = setting($key);
    if ($raw === null) return [];
    $v = json_decode($raw, true);
    return is_array($v) ? $v : [];
}

/**
 * The period form (GET). $before is markup placed before the period controls (a
 * page's own filter, such as the funding agency), $after after the Run button
 * (download buttons of the same form, links). Both are HTML already escaped.
 * Choosing a preset sets the dates; editing a date switches the preset to Custom.
 */
function report_period_form(string $action, array $period, string $before = '', string $after = '', string $runLabel = 'Run'): string {
    $out = '<form method="get" action="' . h($action) . '" class="page-actions" aria-label="Report period">' . $before;
    $out .= '<label for="period">Period</label><select id="period" name="period" aria-describedby="period-hint">';
    foreach (REPORT_PERIOD_PRESETS as $k => $label) {
        $out .= '<option value="' . $k . '"' . ($period['preset'] === $k ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    $out .= '</select>';
    $out .= '<label for="from">From</label><input type="date" id="from" name="from" value="' . h($period['from']) . '">';
    $out .= '<label for="to">To</label><input type="date" id="to" name="to" value="' . h($period['to']) . '">';
    $out .= '<button type="submit" class="btn btn-secondary btn-sm">' . h($runLabel) . '<span class="visually-hidden"> the report for this period</span></button>' . $after;
    $out .= '<span class="hint" id="period-hint">A preset sets the dates itself; to use the From and To dates, choose Custom dates. The period you choose is remembered on every report.</span>';
    $out .= '<script>document.addEventListener("DOMContentLoaded",function(){var p=document.getElementById("period");["from","to"].forEach(function(id){var e=document.getElementById(id);if(e&&p)e.addEventListener("change",function(){p.value="custom";});});});</script>';
    return $out . '</form>';
}
