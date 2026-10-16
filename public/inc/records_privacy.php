<?php
declare(strict_types=1);
// Record keeping and privacy (3.0.36). Owner decisions of 2026-10-16:
// - A case note locks 30 days after it was written. A later change is an Addendum, a new
//   note linked to the original, and every earlier version of an edited note is kept.
// - An adult client's release of information is valid for 1 year.
// The website, the app's API and voice all use these.

/** Days after which a note can no longer be edited (setting note_lock_days; 0 turns the lock off). */
function note_lock_days(): int {
    $v = setting('note_lock_days');
    return $v === null || $v === '' ? 30 : max(0, (int)$v);
}

/** True when the note is past the edit window. */
function note_locked(array $note): bool {
    $days = note_lock_days();
    if ($days === 0 || empty($note['created_at'])) return false;
    return strtotime((string)$note['created_at']) < strtotime('-' . $days . ' days');
}

/** The date a note locks (Y-m-d), or null when the lock is off. */
function note_locks_on(array $note): ?string {
    $days = note_lock_days();
    if ($days === 0 || empty($note['created_at'])) return null;
    return date('Y-m-d', strtotime((string)$note['created_at'] . ' +' . $days . ' days'));
}

/** Whether this user may change the note now: its author or an administrator, and not locked. */
function note_editable(array $note, int $userId): bool {
    return (can('admin') || (int)($note['author_id'] ?? 0) === $userId) && !note_locked($note);
}

/** The message given when an edit is refused because the note is locked. */
function note_locked_message(): string {
    return 'This note is more than ' . note_lock_days() . ' days old, so it is locked and cannot be changed. Add an addendum instead.';
}

/**
 * Inside the caller's transaction: lock the note row, refuse when it is past the edit
 * window, and save its current text as an earlier version. Returns the row as it was,
 * or a string error. The caller then updates the note.
 */
function note_keep_version(PDO $pdo, int $noteId, int $userId) {
    $s = $pdo->prepare('SELECT * FROM client_notes WHERE id = ? FOR UPDATE');
    $s->execute([$noteId]);
    $note = $s->fetch();
    if (!$note) return 'Note not found.';
    if (note_locked($note)) return note_locked_message();
    $pdo->prepare('INSERT INTO client_note_revisions (note_id, client_id, track_id, note_type, note_format, subject, body,
            birp_behavior, birp_intervention, birp_response, birp_plan, version_written_at, version_written_by, replaced_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$note['id'], (int)$note['client_id'], $note['track_id'], $note['note_type'], $note['note_format'],
            $note['subject'], $note['body'], $note['birp_behavior'], $note['birp_intervention'], $note['birp_response'],
            $note['birp_plan'], $note['updated_at'] ?? $note['created_at'],
            $note['updated_by'] ?? $note['author_id'], $userId]);
    return $note;
}

/**
 * The note an addendum is attached to: a note on the same client. Returns its id, or
 * null when none was asked for. A string is an error for the person.
 */
function note_addendum_parent($raw, int $clientId) {
    $pid = (int)(is_scalar($raw) ? $raw : 0);
    if ($pid <= 0) return null;
    $q = db()->prepare('SELECT id FROM client_notes WHERE id = ? AND client_id = ?');
    $q->execute([$pid, $clientId]);
    return $q->fetchColumn() ? $pid : 'The note this addendum belongs to is not on this client.';
}

/** Earlier versions per note id, newest first, for the given note ids. */
function note_versions(array $noteIds): array {
    $noteIds = array_values(array_unique(array_map('intval', $noteIds)));
    if (!$noteIds) return [];
    $q = db()->prepare('SELECT r.*, w.name AS written_by_name, b.name AS replaced_by_name FROM client_note_revisions r
        LEFT JOIN users w ON w.id = r.version_written_by LEFT JOIN users b ON b.id = r.replaced_by
        WHERE r.note_id IN (' . implode(',', array_fill(0, count($noteIds), '?')) . ') ORDER BY r.replaced_at DESC, r.id DESC');
    $q->execute($noteIds);
    $out = [];
    foreach ($q->fetchAll() as $r) $out[(int)$r['note_id']][] = $r;
    return $out;
}

/** Addenda per original note id, oldest first: [note_id => [[id, subject, created_at], ...]]. */
function note_addenda(int $clientId): array {
    $q = db()->prepare('SELECT id, addendum_to, subject, created_at FROM client_notes WHERE client_id = ? AND addendum_to IS NOT NULL ORDER BY created_at, id');
    $q->execute([$clientId]);
    $out = [];
    foreach ($q->fetchAll() as $r) $out[(int)$r['addendum_to']][] = $r;
    return $out;
}

/* ---------- Releases of information (adult clients) ---------- */

/** Who a release lets us share with. */
const RELEASE_RECIPIENT_TYPES = ['VR counselor and agency', 'Employer', 'Doctor or clinic', 'School', 'Family member', 'Other'];

/** What a release can cover (ticked on the request). */
const RELEASE_INFO_ITEMS = ['Progress reports and attendance', 'Assessments and recommendations',
    'Equipment and device information', 'Vision and health information', 'Employment information'];

/** Days a signed release stays valid (owner decision: 1 year). */
function release_validity_days(): int {
    $v = (int)(setting('release_validity_days') ?: 365);
    return $v > 0 ? $v : 365;
}

/** A typed name compared the way a person hears and types it: case, accents, spaces and
 *  punctuation are ignored, so only the letters must match (shared by consent.php and
 *  release.php). */
function typed_name_key(string $s): string {
    if (class_exists('Normalizer')) {
        $decomposed = Normalizer::normalize($s, Normalizer::FORM_D);
        if (is_string($decomposed)) $s = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $s;
    }
    return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_convert_case($s, MB_CASE_FOLD, 'UTF-8')) ?? '';
}

/** A release's state today: Current, Expired, Withdrawn, Awaiting signature or Link expired. */
function release_status(array $r): string {
    if (!empty($r['withdrawn_at'])) return 'Withdrawn';
    if (!empty($r['signed_at'])) return ($r['expires_on'] !== null && $r['expires_on'] < date('Y-m-d')) ? 'Expired' : 'Current';
    return strtotime((string)$r['sent_at']) < strtotime('-60 days') ? 'Link expired' : 'Awaiting signature';
}

/** A client's releases, newest first. */
function client_releases(int $clientId): array {
    try {
        $q = db()->prepare('SELECT r.*, u.name AS created_by_name FROM client_releases r LEFT JOIN users u ON u.id = r.created_by
            WHERE r.client_id = ? ORDER BY COALESCE(r.signed_at, r.sent_at) DESC, r.id DESC');
        $q->execute([$clientId]);
        return $q->fetchAll();
    } catch (PDOException $e) {
        return []; // mig_3.0.36 not run yet
    }
}

/**
 * The current release that lets us send to this recipient, or null. A release covers a
 * recipient of its type, or one whose email it names. For a VR counselor or a school, a
 * guardian consent still in force counts too (its wording covers both).
 */
function release_covering(int $clientId, string $recipientType, ?string $recipientEmail = null): ?array {
    $email = mb_strtolower(trim((string)$recipientEmail));
    foreach (client_releases($clientId) as $r) {
        if (release_status($r) !== 'Current') continue;
        if ($r['recipient_type'] === $recipientType || ($email !== '' && mb_strtolower(trim((string)$r['recipient_email'])) === $email)) return $r;
    }
    if (in_array($recipientType, ['VR counselor and agency', 'School'], true)) {
        $q = db()->prepare('SELECT * FROM consent_forms WHERE client_id = ? AND signed_at IS NOT NULL ORDER BY signed_at DESC, id DESC LIMIT 1');
        $q->execute([$clientId]);
        $c = $q->fetch();
        $days = (int)(setting('consent_validity_days') ?: 365);
        if ($c && empty($c['withdrawn_at']) && strtotime($c['signed_at'] . ' +' . $days . ' days') >= strtotime('today')) {
            return ['recipient_type' => $recipientType, 'recipient_name' => 'Guardian consent', 'signed_name' => $c['signed_name'],
                'expires_on' => date('Y-m-d', strtotime($c['signed_at'] . ' +' . $days . ' days'))];
        }
    }
    return null;
}

/** The warning shown before something is sent to a recipient no current release covers, or null. */
function release_warning(int $clientId, string $recipientType, ?string $recipientEmail = null): ?string {
    if (release_covering($clientId, $recipientType, $recipientEmail) !== null) return null;
    return 'No current release of information on file covers sending this to ' . ($recipientType === 'Other' ? 'this recipient' : 'the ' . mb_strtolower($recipientType)) . '. Check that the client has agreed, or request a release on the client page.';
}

/**
 * Validate a release request or a paper release from posted fields. Returns
 * [errors (field => message), clean values].
 */
function release_input(array $in, string $method): array {
    $str = fn(string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
    $v = [
        'signer_role' => $str('signer_role') === 'Guardian' ? 'Guardian' : 'Client',
        'signer_name' => $str('signer_name'), 'signer_email' => $str('signer_email'),
        'recipient_type' => $str('recipient_type'), 'recipient_name' => $str('recipient_name'),
        'recipient_email' => $str('recipient_email'), 'purpose' => $str('purpose'),
        'signed_on' => $str('signed_on'),
    ];
    $items = is_array($in['info'] ?? null) ? array_values(array_intersect(RELEASE_INFO_ITEMS, array_filter($in['info'], 'is_string'))) : [];
    $other = $str('info_other');
    if ($other !== '') $items[] = $other;
    $v['info_scope'] = implode('; ', $items);
    $e = [];
    if ($v['signer_name'] === '') $e['signer_name'] = 'Enter the name of the person who signs.';
    elseif (mb_strlen($v['signer_name']) > 120) $e['signer_name'] = 'Keep the name to 120 characters or fewer.';
    if ($method === 'Email') {
        if (!filter_var($v['signer_email'], FILTER_VALIDATE_EMAIL) || mb_strlen($v['signer_email']) > 190) $e['signer_email'] = 'Enter a valid email address for the person who signs.';
    } elseif ($v['signer_email'] !== '' && !filter_var($v['signer_email'], FILTER_VALIDATE_EMAIL)) $e['signer_email'] = 'Enter a valid email address, or leave it blank.';
    if (!in_array($v['recipient_type'], RELEASE_RECIPIENT_TYPES, true)) $e['recipient_type'] = 'Choose who the information goes to.';
    if ($v['recipient_name'] === '') $e['recipient_name'] = 'Name the person or organisation that receives the information.';
    elseif (mb_strlen($v['recipient_name']) > 190) $e['recipient_name'] = 'Keep the recipient to 190 characters or fewer.';
    if ($v['recipient_email'] !== '' && (!filter_var($v['recipient_email'], FILTER_VALIDATE_EMAIL) || mb_strlen($v['recipient_email']) > 190)) $e['recipient_email'] = 'Enter a valid email address for the recipient, or leave it blank.';
    if ($v['info_scope'] === '') $e['info'] = 'Tick at least one kind of information, or describe it under Other information.';
    elseif (mb_strlen($v['info_scope']) > 500) $e['info'] = 'The information list is too long (500 characters maximum). Shorten Other information.';
    if ($v['purpose'] === '') $e['purpose'] = 'Say why the information is shared.';
    elseif (mb_strlen($v['purpose']) > 255) $e['purpose'] = 'Keep the purpose to 255 characters or fewer.';
    if ($method === 'Paper') {
        $d = valid_date($v['signed_on']);
        if ($d === null || $d > date('Y-m-d')) $e['signed_on'] = 'Enter the date the paper release was signed (today or earlier).';
        elseif ($d < date('Y-m-d', strtotime('-' . release_validity_days() . ' days'))) $e['signed_on'] = 'A release signed on that date has already expired. Ask for a new one.';
        else $v['signed_on'] = $d;
    }
    return [$e, $v];
}

/**
 * Email a release to sign. Returns ['error' => ?string, 'id' => int]. Nothing is kept
 * when the email cannot be sent.
 */
function release_send(int $clientId, array $v, int $actor): array {
    $c = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $c->execute([$clientId]);
    $client = $c->fetch();
    if (!$client) return ['error' => 'Client not found.', 'id' => 0];
    $token = bin2hex(random_bytes(24));
    db()->prepare('INSERT INTO client_releases (client_id, signer_role, signer_name, signer_email, recipient_type, recipient_name,
            recipient_email, info_scope, purpose, method, token, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$clientId, $v['signer_role'], $v['signer_name'], $v['signer_email'], $v['recipient_type'], $v['recipient_name'],
            $v['recipient_email'] !== '' ? $v['recipient_email'] : null, $v['info_scope'], $v['purpose'], 'Email', $token, $actor]);
    $id = (int)db()->lastInsertId();
    [$subj, $body] = email_template_for_client('release_request', $client, ['name' => $v['signer_name'],
        'link' => cfg('base_url') . '/release.php?t=' . $token . (client_lang($client) === 'es' ? '&lang=es' : '')]);
    if (!send_app_mail($v['signer_email'], $subj, $body)) {
        db()->prepare('DELETE FROM client_releases WHERE id = ?')->execute([$id]);
        return ['error' => 'The release could not be emailed, so nothing was saved. Try again later.', 'id' => 0];
    }
    audit($actor, 'release.request', 'client', $clientId, 'to ' . $v['signer_email'] . ' for ' . $v['recipient_type'] . ': ' . $v['recipient_name']);
    return ['error' => null, 'id' => $id];
}

/** Record a release signed on paper. Returns the new id. */
function release_record_paper(int $clientId, array $v, int $actor): int {
    $expires = date('Y-m-d', strtotime($v['signed_on'] . ' +' . release_validity_days() . ' days'));
    db()->prepare('INSERT INTO client_releases (client_id, signer_role, signer_name, signer_email, recipient_type, recipient_name,
            recipient_email, info_scope, purpose, method, created_by, signed_at, signed_name, expires_on) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$clientId, $v['signer_role'], $v['signer_name'], $v['signer_email'] !== '' ? $v['signer_email'] : null,
            $v['recipient_type'], $v['recipient_name'], $v['recipient_email'] !== '' ? $v['recipient_email'] : null,
            $v['info_scope'], $v['purpose'], 'Paper', $actor, $v['signed_on'] . ' 12:00:00', $v['signer_name'], $expires]);
    $id = (int)db()->lastInsertId();
    db()->prepare('INSERT INTO client_notes (client_id, note_type, subject, body, author_id) VALUES (?,?,?,?,?)')
        ->execute([$clientId, 'Case Note', 'Release of information recorded',
            'Paper release signed by ' . $v['signer_name'] . ' on ' . fmt_date($v['signed_on']) . ' for ' . $v['recipient_name']
            . ' (' . $v['recipient_type'] . '): ' . $v['info_scope'] . '. Purpose: ' . $v['purpose'] . '. Valid until ' . fmt_date($expires) . '.', $actor]);
    audit($actor, 'release.paper', 'client', $clientId, $v['recipient_type'] . ': ' . $v['recipient_name']);
    return $id;
}

/** Sign an emailed release with the typed name. True when this call signed it. */
function release_sign(array $r, string $typed): bool {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('UPDATE client_releases SET signed_at = NOW(), signed_name = ?, sign_ip = ?, expires_on = DATE_ADD(CURDATE(), INTERVAL ? DAY)
            WHERE id = ? AND signed_at IS NULL AND withdrawn_at IS NULL');
        $s->execute([$typed, $_SERVER['REMOTE_ADDR'] ?? '', release_validity_days(), (int)$r['id']]);
        $done = $s->rowCount() === 1;
        if ($done) {
            $pdo->prepare('INSERT INTO client_notes (client_id, note_type, subject, body, author_id) VALUES (?,?,?,?,NULL)')
                ->execute([(int)$r['client_id'], 'Case Note', 'Release of information signed',
                    'Release electronically signed by ' . $typed . ' (' . $r['signer_email'] . ') on ' . date('F j, Y g:i A') . ' for '
                    . $r['recipient_name'] . ' (' . $r['recipient_type'] . '): ' . $r['info_scope'] . '. Purpose: ' . $r['purpose'] . '.']);
        }
        $pdo->commit();
        return $done;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Record that a release was withdrawn (signed or not). False when there was nothing to withdraw. */
function release_withdraw(int $id, int $clientId, int $actor): bool {
    $w = db()->prepare('UPDATE client_releases SET withdrawn_at = NOW(), withdrawn_by = ? WHERE id = ? AND client_id = ? AND withdrawn_at IS NULL');
    $w->execute([$actor, $id, $clientId]);
    if ($w->rowCount() !== 1) return false;
    audit($actor, 'release.withdraw', 'client', $clientId, 'release #' . $id);
    return true;
}
