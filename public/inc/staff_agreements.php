<?php
declare(strict_types=1);
// Staff agreements (4.15.0, owner request 2026-10-17): agreements an administrator writes
// (Administration, Staff agreements) that each staff member reads and signs themselves, by
// typing their name (My agreements, or either phone app). Each signed copy keeps the exact
// text signed. An agreement is asked for again a year after signing, or when its text changes.
// The one marked as the confidentiality agreement also sets users.confidentiality_signed_on,
// which Compliance and the yearly reminders already follow (3.0.55).

/**
 * The agreements staff are asked to sign: those for all staff and, for someone asked to sign it
 * because they are leaving (users.exit_requested_at, 4.16.0), the exit acknowledgement.
 */
function staff_agreements_active(?int $userId = null): array {
    try {
        $leaving = false;
        if ($userId !== null) {
            $q = db()->prepare('SELECT exit_requested_at FROM users WHERE id = ?');
            $q->execute([$userId]);
            $leaving = (string)$q->fetchColumn() !== '';
        }
        return db()->query("SELECT * FROM staff_agreements WHERE active = 1 AND (kind = 'staff'" . ($leaving ? " OR kind = 'exit'" : '') . ') ORDER BY counts_as_confidentiality DESC, kind DESC, title')->fetchAll();
    } catch (PDOException $e) {
        // Before migration 4.16.0 (no kind column) every agreement is for all staff.
        try { return db()->query('SELECT * FROM staff_agreements WHERE active = 1 ORDER BY counts_as_confidentiality DESC, title')->fetchAll(); }
        catch (PDOException $e2) { return []; } // before migration 4.15.0
    }
}

/**
 * Everyone's latest signature of each agreement, in one query: [user id][agreement id] => row
 * (id, version, signed_at). [$userId] limits it to one person.
 */
function staff_agreement_latest(?int $userId = null): array {
    $sql = 'SELECT s.id, s.user_id, s.agreement_id, s.version, s.signed_at FROM staff_agreement_signatures s
        JOIN (SELECT MAX(id) AS id FROM staff_agreement_signatures' . ($userId !== null ? ' WHERE user_id = ?' : '') . ' GROUP BY user_id, agreement_id) m ON m.id = s.id';
    $q = db()->prepare($sql);
    $q->execute($userId !== null ? [$userId] : []);
    $out = [];
    foreach ($q->fetchAll() as $r) $out[(int)$r['user_id']][(int)$r['agreement_id']] = $r;
    return $out;
}

/**
 * Each active agreement with this person's standing: 'state' is signed, due (renewal within 30
 * days), or needed (never signed, a new version, or a year has passed), with 'signed_at',
 * 'renew_by' and 'why'. [$latest] is staff_agreement_latest() when many people are looked at.
 */
function staff_agreement_status(int $userId, ?array $latest = null): array {
    $out = [];
    $mine = ($latest ?? staff_agreement_latest($userId))[$userId] ?? [];
    foreach (staff_agreements_active($userId) as $a) {
        $s = $mine[(int)$a['id']] ?? null;
        $row = $a + ['state' => 'needed', 'signed_at' => null, 'renew_by' => null, 'why' => 'Not signed yet', 'signature_id' => null];
        if ($s) {
            $row['signed_at'] = $s['signed_at'];
            $row['signature_id'] = (int)$s['id'];
            $renew = date('Y-m-d', strtotime(substr((string)$s['signed_at'], 0, 10) . ' +' . STAFF_RENEWAL_DAYS . ' days'));
            $row['renew_by'] = $renew;
            if ((int)$s['version'] !== (int)$a['version']) $row['why'] = 'The text changed since you signed it';
            elseif ($renew < date('Y-m-d')) $row['why'] = 'A year has passed since you signed it';
            else {
                $row['state'] = $renew <= date('Y-m-d', strtotime('+30 days')) ? 'due' : 'signed';
                $row['why'] = $row['state'] === 'due' ? 'Renew by ' . fmt_date($renew) : 'Signed ' . fmt_date((string)$s['signed_at']);
            }
        }
        $out[] = $row;
    }
    return $out;
}

/** How many agreements this person needs to sign now. */
function staff_agreements_needed(int $userId): int {
    try {
        return count(array_filter(staff_agreement_status($userId), fn($r) => $r['state'] === 'needed'));
    } catch (Throwable $e) {
        return 0;
    }
}

/** The fingerprint of an agreement's text, sent with a signature to prove which text was read. */
function staff_agreement_hash(string $title, string $body): string {
    return hash('sha256', $title . "\n" . $body);
}

/**
 * Sign an agreement as this person: the typed name must be their name on their account, and
 * [$shownHash] the fingerprint of the exact title and text they were shown.
 * Returns [signature id, null, false] or [null, what is wrong, whether the text changed].
 */
function staff_agreement_sign(int $userId, int $agreementId, string $typed, int $version, string $via, string $shownHash = ''): array {
    // Only an agreement asked of this person (the exit acknowledgement only of someone leaving).
    $agreement = null;
    foreach (staff_agreements_active($userId) as $x) if ((int)$x['id'] === $agreementId) $agreement = $x;
    if (!$agreement) return [null, 'That agreement is no longer asked for.', true];
    // The text read is the text signed: any change while it was open, even a small correction,
    // is said and nothing is signed.
    if ($version !== (int)$agreement['version'] || !hash_equals(staff_agreement_hash((string)$agreement['title'], (string)$agreement['body']), $shownHash)) {
        return [null, 'This agreement was changed while you had it open. Read the new text, then sign again.', true];
    }
    $u = db()->prepare('SELECT name FROM users WHERE id = ? AND is_active = 1');
    $u->execute([$userId]);
    $name = (string)$u->fetchColumn();
    $typed = trim($typed);
    if ($typed === '') return [null, 'Type your full name to sign.', false];
    if (!mb_check_encoding($typed, 'UTF-8') || mb_strlen($typed) > 120) return [null, 'Type your full name as it is on your account (120 characters at most).', false];
    if (typed_name_key($typed) === '' || typed_name_key($typed) !== typed_name_key($name)) return [null, 'The name typed does not match the name on your account, ' . $name . '.', false];
    db()->beginTransaction();
    try {
        $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        db()->prepare('INSERT INTO staff_agreement_signatures (agreement_id, version, user_id, title, body, signed_name, sign_ip, via, user_agent) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$agreementId, (int)$agreement['version'], $userId, $agreement['title'], $agreement['body'], $typed, mb_substr(client_ip(), 0, 45), mb_substr($via, 0, 20), $ua !== '' ? $ua : null]);
        $id = (int)db()->lastInsertId();
        if ((int)$agreement['counts_as_confidentiality'] === 1) db()->prepare('UPDATE users SET confidentiality_signed_on = CURDATE() WHERE id = ?')->execute([$userId]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    audit($userId, 'agreement.sign', 'user', $userId, $agreement['title'] . ' (version ' . (int)$agreement['version'] . ', ' . $via . ')');
    return [$id, null, false];
}

/**
 * Whether a change is small enough to be a correction that nobody signs again: the same lines,
 * headings and list items, and at most 40 characters different. Anything more is a new version.
 */
function staff_agreement_small_change(string $old, string $new): bool {
    $lines = fn(string $s) => array_map('trim', preg_split('/\R/', trim($s)));
    $shape = fn(array $l) => array_map(fn($x) => preg_match('/^#/u', $x) ? 'h' : (preg_match('/^[-*•]\s/u', $x) ? 'i' : ($x === '' ? 'b' : 'p')), $l);
    if ($shape($lines($old)) !== $shape($lines($new))) return false;
    // What differs once the shared start and end are set aside.
    $a = mb_str_split($old); $b = mb_str_split($new);
    $p = 0; while ($p < count($a) && $p < count($b) && $a[$p] === $b[$p]) $p++;
    $s = 0; while ($s < count($a) - $p && $s < count($b) - $p && $a[count($a) - 1 - $s] === $b[count($b) - 1 - $s]) $s++;
    return max(count($a) - $p - $s, count($b) - $p - $s) <= 40;
}

/** Small corrections made to an agreement's text after a given time, for a signed copy. */
function staff_agreement_corrections_after(int $agreementId, int $version, string $since): array {
    try {
        $q = db()->prepare('SELECT r.changed_at, u.name FROM staff_agreement_revisions r LEFT JOIN users u ON u.id = r.changed_by
            WHERE r.agreement_id = ? AND r.version = ? AND r.small_correction = 1 AND r.changed_at > ? ORDER BY r.changed_at');
        $q->execute([$agreementId, $version, $since]);
        return $q->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/** Agreement text as HTML: "# " lines are headings (at [level]), "- " lines list items, the rest paragraphs. */
function staff_agreement_html(string $body, int $level = 3): string {
    $out = ''; $inList = false;
    foreach (preg_split('/\R/', $body) as $line) {
        $line = trim($line);
        if (preg_match('/^[-*•]\s+(.*)$/u', $line, $m)) {
            if (!$inList) { $out .= '<ul>'; $inList = true; }
            $out .= '<li>' . h($m[1]) . '</li>';
            continue;
        }
        if ($inList) { $out .= '</ul>'; $inList = false; }
        if ($line === '') continue;
        if (preg_match('/^#{1,6}\s+(.*)$/u', $line, $m)) $out .= '<h' . $level . '>' . h($m[1]) . '</h' . $level . '>';
        else $out .= '<p>' . h($line) . '</p>';
    }
    return $out . ($inList ? '</ul>' : '');
}

/** For Compliance: each active agreement a person still needs to sign, in words. */
function staff_agreement_notes(int $userId, ?array $latest = null): array {
    $out = [];
    try {
        foreach (staff_agreement_status($userId, $latest) as $r) {
            if ($r['state'] === 'needed') $out[] = $r['title'] . ': ' . mb_strtolower($r['why']);
            elseif ($r['state'] === 'due') $out[] = $r['title'] . ': renew by ' . fmt_date((string)$r['renew_by']);
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/** Whether an active agreement stands for the confidentiality agreement (then its own notes are used). */
function staff_agreements_has_confidentiality(): bool {
    return (bool)array_filter(staff_agreements_active(), fn($a) => (int)$a['counts_as_confidentiality'] === 1 && ($a['kind'] ?? 'staff') === 'staff');
}
