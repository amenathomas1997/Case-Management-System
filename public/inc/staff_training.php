<?php
declare(strict_types=1);
// Staff training (4.16.0, owner request 2026-10-17): a reading and a short quiz an administrator
// writes (Administration, Staff training); staff take it themselves (My training, or the phone
// apps) and pass it once a year or when it changes. The one marked as the privacy training sets
// users.privacy_training_on, which Compliance and the yearly reminders follow (3.0.55).
//
// Also here: holding back client records until the required agreements and training are done
// (setting access_hold: off, new staff only, or everyone after access_hold_days).

/**
 * The quiz as the administrator wrote it: "? " starts a question, "* " is the right answer and
 * "- " a wrong one. Returns [questions, null] (each ['q' => text, 'options' => [...], 'correct' => index])
 * or [[], what is wrong].
 */
function training_parse_questions(string $text): array {
    $qs = [];
    foreach (preg_split('/\R/', trim($text)) as $n => $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('/^\?\s*(.+)$/u', $line, $m)) { $qs[] = ['q' => $m[1], 'options' => [], 'correct' => -1]; continue; }
        if (preg_match('/^([*-])\s*(.+)$/u', $line, $m)) {
            if (!$qs) return [[], 'Line ' . ($n + 1) . ': start each question with "? " before its answers.'];
            $i = count($qs) - 1;
            if ($m[1] === '*') {
                if ($qs[$i]['correct'] >= 0) return [[], 'Question ' . ($i + 1) . ' has more than one right answer (marked *). Mark only one.'];
                $qs[$i]['correct'] = count($qs[$i]['options']);
            }
            $qs[$i]['options'][] = $m[2];
            continue;
        }
        return [[], 'Line ' . ($n + 1) . ': start it with "? " for a question, "* " for the right answer or "- " for a wrong one.'];
    }
    if (!$qs) return [[], 'Write at least one question.'];
    foreach ($qs as $i => $q) {
        if (count($q['options']) < 2 || count($q['options']) > 6) return [[], 'Question ' . ($i + 1) . ' needs 2 to 6 answers.'];
        if ($q['correct'] < 0) return [[], 'Question ' . ($i + 1) . ' has no right answer: mark it with "* ".'];
    }
    return [$qs, null];
}

/** The fingerprint of a module's reading and quiz, sent back with the answers. */
function training_hash(array $m): string {
    return hash('sha256', $m['title'] . "\n" . $m['body'] . "\n" . $m['questions']);
}

function training_modules_active(): array {
    try {
        return db()->query('SELECT * FROM training_modules WHERE active = 1 ORDER BY counts_as_privacy DESC, title')->fetchAll();
    } catch (PDOException $e) {
        return []; // before migration 4.16.0
    }
}

/** Everyone's latest passed completion of each module, in one query: [user][module] => row. */
function training_latest(?int $userId = null): array {
    $q = db()->prepare('SELECT c.id, c.user_id, c.module_id, c.version, c.completed_at, c.score, c.total FROM training_completions c
        JOIN (SELECT MAX(id) AS id FROM training_completions WHERE passed = 1' . ($userId !== null ? ' AND user_id = ?' : '') . ' GROUP BY user_id, module_id) m ON m.id = c.id');
    $q->execute($userId !== null ? [$userId] : []);
    $out = [];
    foreach ($q->fetchAll() as $r) $out[(int)$r['user_id']][(int)$r['module_id']] = $r;
    return $out;
}

/** Each active module with this person's standing, as staff_agreement_status() does for agreements. */
function training_status(int $userId, ?array $latest = null): array {
    $out = [];
    $mine = ($latest ?? training_latest($userId))[$userId] ?? [];
    foreach (training_modules_active() as $m) {
        $c = $mine[(int)$m['id']] ?? null;
        $row = $m + ['state' => 'needed', 'done_at' => null, 'renew_by' => null, 'why' => 'Not taken yet', 'completion_id' => null];
        if ($c) {
            $row['done_at'] = $c['completed_at'];
            $row['completion_id'] = (int)$c['id'];
            $renew = date('Y-m-d', strtotime(substr((string)$c['completed_at'], 0, 10) . ' +' . STAFF_RENEWAL_DAYS . ' days'));
            $row['renew_by'] = $renew;
            if ((int)$c['version'] !== (int)$m['version']) $row['why'] = 'The training changed since you passed it';
            elseif ($renew < date('Y-m-d')) $row['why'] = 'A year has passed since you passed it';
            else {
                $row['state'] = $renew <= date('Y-m-d', strtotime('+30 days')) ? 'due' : 'done';
                $row['why'] = $row['state'] === 'due' ? 'Take it again by ' . fmt_date($renew) : 'Passed ' . fmt_date((string)$c['completed_at']);
            }
        }
        $out[] = $row;
    }
    return $out;
}

function training_needed(int $userId): int {
    try {
        return count(array_filter(training_status($userId), fn($r) => $r['state'] === 'needed'));
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Mark someone's answers ([question index => answer index]). Every attempt is kept; a pass of
 * the privacy training records the training date. Returns ['id', 'score', 'total', 'passed',
 * 'wrong' => [question numbers], 'error' => null|string, 'changed' => bool].
 */
function training_submit(int $userId, int $moduleId, int $version, string $shownHash, array $answers, string $via): array {
    $fail = fn(string $e, bool $changed = false) => ['id' => null, 'score' => 0, 'total' => 0, 'passed' => false, 'wrong' => [], 'error' => $e, 'changed' => $changed];
    $q = db()->prepare('SELECT * FROM training_modules WHERE id = ? AND active = 1');
    $q->execute([$moduleId]);
    $m = $q->fetch();
    if (!$m) return $fail('That training is no longer asked for.', true);
    if ($version !== (int)$m['version'] || !hash_equals(training_hash($m), $shownHash)) return $fail('This training was changed while you had it open. Read the new version, then answer again.', true);
    [$qs, $err] = training_parse_questions((string)$m['questions']);
    if ($err !== null) return $fail('This training cannot be taken right now: ' . $err);
    $wrong = []; $given = [];
    foreach ($qs as $i => $qq) {
        $a = $answers[$i] ?? $answers[(string)$i] ?? null;
        if ($a === null || $a === '' || !is_numeric($a) || (int)$a < 0 || (int)$a >= count($qq['options'])) return $fail('Answer every question (question ' . ($i + 1) . ' has no answer).');
        $given[] = ['q' => $qq['q'], 'answer' => $qq['options'][(int)$a], 'right' => (int)$a === $qq['correct']];
        if ((int)$a !== $qq['correct']) $wrong[] = $i + 1;
    }
    $score = count($qs) - count($wrong);
    $passed = $score * 100 >= (int)$m['pass_pct'] * count($qs);
    db()->beginTransaction();
    try {
        db()->prepare('INSERT INTO training_completions (module_id, version, user_id, title, score, total, passed, answers, via) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$moduleId, (int)$m['version'], $userId, $m['title'], $score, count($qs), $passed ? 1 : 0, json_encode($given, JSON_UNESCAPED_UNICODE), mb_substr($via, 0, 20)]);
        $id = (int)db()->lastInsertId();
        if ($passed && (int)$m['counts_as_privacy'] === 1) db()->prepare('UPDATE users SET privacy_training_on = CURDATE() WHERE id = ?')->execute([$userId]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    audit($userId, 'training.complete', 'user', $userId, $m['title'] . ': ' . $score . ' of ' . count($qs) . ($passed ? ', passed' : ', not passed') . ' (' . $via . ')');
    return ['id' => $id, 'score' => $score, 'total' => count($qs), 'passed' => $passed, 'wrong' => $wrong, 'error' => null, 'changed' => false];
}

/** Whether a module stands for the privacy training (then its own state replaces the recorded date's). */
function training_has_privacy(): bool {
    return (bool)array_filter(training_modules_active(), fn($m) => (int)$m['counts_as_privacy'] === 1);
}

/** For Compliance: each active module a person still needs to pass, in words. */
function training_notes(int $userId, ?array $latest = null): array {
    $out = [];
    try {
        foreach (training_status($userId, $latest) as $r) {
            if ($r['state'] === 'needed') $out[] = $r['title'] . ': ' . mb_strtolower($r['why']);
            elseif ($r['state'] === 'due') $out[] = $r['title'] . ': take again by ' . fmt_date((string)$r['renew_by']);
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/* ---------- Holding back client records ---------- */

/** Off, new (new staff only, the built-in choice) or all (everyone, after access_hold_days). */
function access_hold_mode(): string {
    $v = (string)(setting('access_hold') ?? '');
    return in_array($v, ['off', 'new', 'all'], true) ? $v : 'new';
}

function access_hold_days(): int {
    $v = (string)(setting('access_hold_days') ?? '');
    return ctype_digit($v) && (int)$v >= 0 && (int)$v <= 90 ? (int)$v : 14;
}

/**
 * What this person must finish before opening client records: the titles of required agreements
 * and training not done. Someone who joined after an item was turned on is held at once; with
 * "everyone", others have access_hold_days after it was turned on, changed, or fell due.
 */
function access_hold_reasons(array $userRow, bool $fresh = false): array {
    static $cache = [];
    $uid = (int)($userRow['id'] ?? 0);
    if ($uid <= 0) return [];
    if (isset($cache[$uid]) && !$fresh) return $cache[$uid];
    $mode = access_hold_mode();
    if ($mode === 'off') return $cache[$uid] = [];
    $out = [];
    try {
        $joined = strtotime((string)($userRow['created_at'] ?? '1970-01-01'));
        $grace = access_hold_days() * 86400;
        $items = [];
        foreach (staff_agreement_status($uid) as $a) if (($a['kind'] ?? 'staff') === 'staff') $items[] = $a + ['done' => $a['signed_at']];
        foreach (training_status($uid) as $t) if ((int)$t['required'] === 1) $items[] = $t + ['done' => $t['done_at']];
        foreach ($items as $it) {
            if ($it['state'] !== 'needed') continue;
            $on = strtotime((string)($it['activated_at'] ?? $it['updated_at'] ?? 'now'));
            $newcomer = $joined >= $on && $it['done'] === null;
            if ($newcomer) { $out[] = $it['title']; continue; }
            if ($mode !== 'all') continue;
            // Never done: from when it was turned on. A new version: from when it changed. Otherwise the year ran out.
            $sinceVersion = ($it['why'] ?? '') === 'The text changed since you signed it' || ($it['why'] ?? '') === 'The training changed since you passed it';
            $since = $it['done'] === null ? $on : ($sinceVersion ? strtotime((string)($it['version_at'] ?? $it['updated_at'] ?? 'now')) : strtotime((string)$it['renew_by']));
            if (time() >= $since + $grace) $out[] = $it['title'];
        }
    } catch (Throwable $e) {
        return $cache[$uid] = [];
    }
    return $cache[$uid] = $out;
}

/** Client permissions are taken away while something is outstanding; administration and templates stay. */
function access_hold_apply(array $userRow, array $resolved): array {
    $reasons = access_hold_reasons($userRow);
    $GLOBALS['ACCESS_HOLD'] = $reasons;
    $GLOBALS['PERMS_BEFORE_HOLD'] = $resolved['perms'];
    if (!$reasons) return $resolved;
    $resolved['perms'] = array_values(array_intersect($resolved['perms'], ['admin', 'templates']));
    return $resolved;
}
