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

/* ---------- Who opened a client's file ---------- */

/**
 * Record that a client's file was opened ('client.view' in the audit log), at most once per
 * person per client in 30 minutes so the log stays readable. $userId is null for the
 * client's own portal or a counselor's portal; $where says which (shown in the detail).
 */
function record_client_view(?int $userId, int $clientId, string $where): void {
    if ($clientId <= 0) return;
    try {
        $q = db()->prepare("SELECT 1 FROM audit_log WHERE action = 'client.view' AND entity = 'client' AND entity_id = ?
            AND user_id <=> ? AND detail = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE) LIMIT 1");
        $q->execute([$clientId, $userId, $where]);
        if ($q->fetchColumn()) return;
    } catch (Throwable $e) {
        return; // never stops a page from opening
    }
    audit($userId, 'client.view', 'client', $clientId, $where);
}

/* ---------- Record holds and safer deletion (3.0.37) ---------- */

/** Document categories only an administrator may delete, and that the nightly job never purges from the trash. */
const KEY_DOCUMENT_CATEGORIES = ['Authorization', 'IPE', 'Release'];

/** The hold on a client record ([at, by_name, reason]), or null when there is none. */
function client_hold(int $clientId): ?array {
    try {
        $q = db()->prepare('SELECT c.hold_at, c.hold_reason, u.name AS hold_by_name FROM clients c LEFT JOIN users u ON u.id = c.hold_by WHERE c.id = ? AND c.hold_at IS NOT NULL');
        $q->execute([$clientId]);
        return $q->fetch() ?: null;
    } catch (PDOException $e) {
        return null; // mig_3.0.37 not run yet
    }
}

/** Place (reason given) or lift (reason null) a hold. Returns an error, or null. */
function client_set_hold(int $clientId, ?string $reason, int $actor): ?string {
    if ($reason !== null) {
        $reason = trim($reason);
        if ($reason === '') return 'Say why the record is on hold.';
        if (mb_strlen($reason) > 255) return 'Keep the reason to 255 characters or fewer.';
        $u = db()->prepare('UPDATE clients SET hold_at = NOW(), hold_by = ?, hold_reason = ? WHERE id = ? AND hold_at IS NULL');
        $u->execute([$actor, $reason, $clientId]);
        if ($u->rowCount() !== 1) return 'This record is already on hold.';
        audit($actor, 'client.hold', 'client', $clientId, 'placed: ' . $reason);
        return null;
    }
    $u = db()->prepare('UPDATE clients SET hold_at = NULL, hold_by = NULL, hold_reason = NULL WHERE id = ? AND hold_at IS NOT NULL');
    $u->execute([$clientId]);
    if ($u->rowCount() !== 1) return 'This record is not on hold.';
    audit($actor, 'client.hold', 'client', $clientId, 'lifted');
    return null;
}

/** Why a client record may not be purged now, or [] when it may. */
function client_purge_blockers(int $clientId): array {
    $out = [];
    if ($h = client_hold($clientId)) $out[] = 'it is on hold (' . $h['hold_reason'] . ')';
    $q = db()->prepare("SELECT COUNT(*) FROM incidents WHERE client_id = ? AND status <> 'Closed'");
    $q->execute([$clientId]);
    if ((int)$q->fetchColumn() > 0) $out[] = 'it has an open incident';
    $q = db()->prepare("SELECT COUNT(*) FROM invoices i JOIN training_tracks t ON t.id = i.track_id WHERE t.client_id = ? AND i.status = 'Submitted'");
    $q->execute([$clientId]);
    if ((int)$q->fetchColumn() > 0) $out[] = 'it has an unpaid invoice';
    return $out;
}

/**
 * Whether the nightly job may purge this trash entry: never a key document (an
 * administrator purges those by hand) and never anything of a client on hold.
 */
function trash_auto_purge_allowed(array $row): bool {
    $p = json_decode((string)$row['payload'], true);
    if (!is_array($p)) return true;
    if ($row['entity'] === 'client_documents' && in_array($p['category'] ?? '', KEY_DOCUMENT_CATEGORIES, true)) return false;
    $clientId = (int)($p['client_id'] ?? 0);
    if ($clientId === 0 && !empty($p['track_id'])) {
        $q = db()->prepare('SELECT client_id FROM training_tracks WHERE id = ?');
        $q->execute([(int)$p['track_id']]);
        $clientId = (int)$q->fetchColumn();
    }
    return $clientId === 0 || client_hold($clientId) === null;
}

/** How a client was given the privacy notice. */
const PRIVACY_NOTICE_METHODS = ['In person', 'By email', 'By mail', 'In the portal', 'Read aloud by phone'];

/* ---------- Privacy incidents (3.0.38) ---------- */

const PRIVACY_INCIDENT_KINDS = ['Misdirected email or report', 'Lost or stolen device', 'Unauthorized access', 'Wrong portal link',
    'Vendor incident', 'Paper records', 'Other'];
const PRIVACY_RISK_LEVELS = ['Under assessment', 'Low probability of compromise', 'Breach: notice required'];
const PRIVACY_NOTICE_RECIPIENTS = ['Affected person', 'Guardian', 'HHS (Office for Civil Rights)', 'Media', 'Funding agency', 'Other'];
const PRIVACY_INCIDENT_STATUSES = ['Open', 'Closed'];

/** Days from discovery to the notice deadline (HIPAA: without unreasonable delay, at most 60 days). */
const PRIVACY_NOTICE_DAYS = 60;

/** One privacy incident with its reporter, or null. */
function privacy_incident(int $id): ?array {
    $q = db()->prepare('SELECT p.*, u.name AS reporter FROM privacy_incidents p LEFT JOIN users u ON u.id = p.reported_by WHERE p.id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

/** The people affected: client id (null once purged), the name kept, and when they were notified. */
function privacy_incident_people(int $id): array {
    $q = db()->prepare('SELECT a.*, c.name AS current_name FROM privacy_incident_clients a LEFT JOIN clients c ON c.id = a.client_id WHERE a.incident_id = ? ORDER BY a.client_label');
    $q->execute([$id]);
    return $q->fetchAll();
}

/** The dated log: notices sent and follow-up entries, oldest first. */
function privacy_incident_events(int $id): array {
    $q = db()->prepare('SELECT e.*, u.name AS by_name FROM privacy_incident_events e LEFT JOIN users u ON u.id = e.created_by WHERE e.incident_id = ? ORDER BY e.happened_on, e.id');
    $q->execute([$id]);
    return $q->fetchAll();
}

/** Days left to the notice deadline (negative when past). */
function privacy_days_left(array $inc): int {
    return (int)floor((strtotime((string)$inc['notice_deadline']) - strtotime('today')) / 86400);
}

/* ---------- Requests for a copy of records, or a correction (3.0.38) ---------- */

/** Portal request types that are records requests, with their label. Each is due in 30 days. */
const RECORDS_REQUEST_TYPES = ['records' => 'Copy of records', 'correction' => 'Correction to records'];
const RECORDS_REQUEST_DAYS = 30;

/**
 * A client-safe copy of the record as one accessible HTML page: profile, training tracks
 * and goals, sessions, case notes, assessments, equipment loans, and consents and
 * releases. Internal review items, billing, rates and invoices are left out.
 */
function client_records_copy_html(int $clientId): string {
    $one = function (string $sql) use ($clientId) { $q = db()->prepare($sql); $q->execute([$clientId]); return $q->fetchAll(); };
    $c = $one('SELECT * FROM clients WHERE id = ?')[0] ?? null;
    if (!$c) return '';
    $e = fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $date = fn($v): string => $v ? $e(fmt_date((string)$v)) : '';
    $out = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Your records — ' . $e(brand('org')) . '</title><style>body{font-family:system-ui,sans-serif;max-width:48rem;margin:1rem auto;padding:0 1rem;line-height:1.5}</style></head><body>'
        . '<h1>Your records from ' . $e(brand('org')) . '</h1><p>Prepared ' . $e(date('F j, Y')) . ' for ' . $e($c['name']) . '.</p>';
    $out .= '<h2>Your details</h2><ul>';
    foreach (['Name' => $c['name'], 'Date of birth' => $c['dob'] ? fmt_date($c['dob']) : '', 'Email' => $c['email'], 'Phone' => $c['phone'], 'Address' => $c['address'],
        'Status' => $c['status'], 'VR counselor' => trim(($c['counselor_name'] ?? '') . ' ' . ($c['counselor_agency'] ? '(' . $c['counselor_agency'] . ')' : '')),
        'Guardian' => $c['guardian_name'] ?? ''] as $k => $v) if ((string)$v !== '') $out .= '<li>' . $e($k) . ': ' . $e($v) . '</li>';
    $out .= '</ul>';
    $tracks = $one('SELECT id, title, status, start_date, expiration_date FROM training_tracks WHERE client_id = ? ORDER BY start_date, id');
    $out .= '<h2>Training</h2>' . ($tracks ? '' : '<p>None.</p>');
    foreach ($tracks as $t) {
        $out .= '<h3>' . $e($t['title']) . '</h3><p>' . $e($t['status']) . ($t['start_date'] ? ', from ' . $date($t['start_date']) : '') . ($t['expiration_date'] ? ' to ' . $date($t['expiration_date']) : '') . '.</p>';
        $g = db()->prepare('SELECT specific_goal, status, target_date FROM smart_goals WHERE track_id = ? ORDER BY id');
        $g->execute([(int)$t['id']]);
        if ($goals = $g->fetchAll()) {
            $out .= '<p>Goals:</p><ul>';
            foreach ($goals as $go) $out .= '<li>' . $e($go['specific_goal']) . ' (' . $e($go['status']) . ($go['target_date'] ? ', target ' . $date($go['target_date']) : '') . ')</li>';
            $out .= '</ul>';
        }
    }
    $sessions = $one("SELECT start_at, status, location, is_remote FROM scheduled_sessions WHERE client_id = ? ORDER BY start_at");
    $out .= '<h2>Sessions</h2>' . ($sessions ? '<ul>' : '<p>None.</p>');
    foreach ($sessions as $s) $out .= '<li>' . $e(fmt_datetime($s['start_at'])) . ': ' . $e($s['status']) . ($s['is_remote'] ? ', online' : ($s['location'] ? ', ' . $e($s['location']) : '')) . '</li>';
    $out .= $sessions ? '</ul>' : '';
    $notes = $one('SELECT created_at, note_type, subject, body FROM client_notes WHERE client_id = ? ORDER BY created_at, id');
    $out .= '<h2>Case notes</h2>' . ($notes ? '' : '<p>None.</p>');
    foreach ($notes as $n) $out .= '<h3>' . $e($n['subject']) . '</h3><p>' . $e($n['note_type']) . ', ' . $e(fmt_datetime($n['created_at'])) . '</p><p style="white-space:pre-wrap">' . $e($n['body']) . '</p>';
    $ass = $one('SELECT assessment_date, primary_tech, summary FROM tech_assessments WHERE client_id = ? ORDER BY assessment_date');
    $out .= '<h2>Assessments</h2>' . ($ass ? '' : '<p>None.</p>');
    foreach ($ass as $a) $out .= '<h3>' . $date($a['assessment_date']) . ($a['primary_tech'] ? ': ' . $e($a['primary_tech']) : '') . '</h3><p style="white-space:pre-wrap">' . $e($a['summary']) . '</p>';
    $loans = $one('SELECT l.loaned_at, l.due_date, l.returned_at, q.name FROM equipment_loans l JOIN equipment q ON q.id = l.equipment_id WHERE l.client_id = ? ORDER BY l.loaned_at');
    $out .= '<h2>Equipment loans</h2>' . ($loans ? '<ul>' : '<p>None.</p>');
    foreach ($loans as $l) $out .= '<li>' . $e($l['name']) . ': loaned ' . $date($l['loaned_at']) . ($l['returned_at'] ? ', returned ' . $date($l['returned_at']) : ', not yet returned') . '</li>';
    $out .= $loans ? '</ul>' : '';
    $rels = client_releases($clientId);
    $cons = $one('SELECT guardian_name, signed_at, signed_name, withdrawn_at FROM consent_forms WHERE client_id = ? AND signed_at IS NOT NULL ORDER BY signed_at');
    $out .= '<h2>Consents and releases</h2>' . ($rels || $cons ? '<ul>' : '<p>None.</p>');
    foreach ($cons as $f) $out .= '<li>Guardian consent signed by ' . $e($f['signed_name']) . ' on ' . $date($f['signed_at']) . ($f['withdrawn_at'] ? ', withdrawn ' . $date($f['withdrawn_at']) : '') . '</li>';
    foreach ($rels as $r) $out .= '<li>Release for ' . $e($r['recipient_name']) . ' (' . $e($r['recipient_type']) . '): ' . $e(release_status($r)) . ($r['expires_on'] ? ', valid until ' . $date($r['expires_on']) : '') . '. Covers ' . $e($r['info_scope']) . '.</li>';
    $out .= ($rels || $cons) ? '</ul>' : '';
    $contact = (string)(cfg('support_email') ?: cfg('mail_from'));
    $out .= '<h2>Questions or corrections</h2><p>If anything here is wrong, ask for a correction from your portal' . ($contact !== '' ? ' or email ' . $e($contact) : '') . '.</p></body></html>';
    return $out;
}

/** Save the copy as a Resource document on the client's portal. Returns the document id, or 0. */
function client_records_copy_publish(int $clientId, int $actor): int {
    $html = client_records_copy_html($clientId);
    if ($html === '') return 0;
    $dir = cfg('upload_dir');
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $stored = bin2hex(random_bytes(16)) . '.html';
    if (file_put_contents($dir . '/' . $stored, $html) === false) return 0;
    try {
        db()->prepare('INSERT INTO client_documents (client_id, category, title, orig_name, path, uploaded_by) VALUES (?,?,?,?,?,?)')
            ->execute([$clientId, 'Resource', 'Copy of your records (' . date('F j, Y') . ')', 'your-records-' . date('Y-m-d') . '.html', $stored, $actor]);
        $docId = (int)db()->lastInsertId();
    } catch (Throwable $e) {
        @unlink($dir . '/' . $stored);
        throw $e;
    }
    audit($actor, 'client.export', 'client', $clientId, 'copy of records published to the portal (doc #' . $docId . ')');
    return $docId;
}

/* ---------- Phones signed in to the app (3.0.39) ---------- */

/** One account's app sessions: in use, waiting to be wiped, or wiped in the last 30 days. */
function app_devices(int $userId): array {
    try {
        $q = db()->prepare('SELECT id, device_name, created_at, last_used_at, expires_at, wipe_requested_at, wiped_at FROM api_tokens
            WHERE user_id = ? AND (expires_at > NOW() OR wiped_at > DATE_SUB(NOW(), INTERVAL 30 DAY)) ORDER BY COALESCE(last_used_at, created_at) DESC');
        $q->execute([$userId]);
        return $q->fetchAll();
    } catch (PDOException $e) {
        return []; // mig_3.0.39 not run yet
    }
}

/** A device's state in words. */
function app_device_state(array $d): string {
    if (!empty($d['wiped_at'])) return 'Wiped ' . fmt_datetime($d['wiped_at']);
    if (!empty($d['wipe_requested_at'])) return 'Reported lost: wiped the next time it connects';
    return 'Signed in' . ($d['last_used_at'] ? ', last used ' . fmt_datetime($d['last_used_at']) : '');
}

/** Sign one phone out ($lost false), or report it lost so it wipes itself ($lost true). */
function app_device_action(int $tokenId, int $userId, bool $lost, int $actor): bool {
    if ($lost) {
        $u = db()->prepare('UPDATE api_tokens SET wipe_requested_at = NOW() WHERE id = ? AND user_id = ? AND wipe_requested_at IS NULL AND expires_at > NOW()');
        $u->execute([$tokenId, $userId]);
    } else {
        $u = db()->prepare('DELETE FROM api_tokens WHERE id = ? AND user_id = ? AND wipe_requested_at IS NULL');
        $u->execute([$tokenId, $userId]);
    }
    if ($u->rowCount() !== 1) return false;
    audit($actor, $lost ? 'user.device_lost' : 'user.device_signout', 'user', $userId, 'app session #' . $tokenId);
    return true;
}

/** The devices list and its buttons, for users.php (an administrator) and account.php (oneself). */
function app_devices_html(array $devices, string $action, array $hidden): string {
    if (!$devices) return '<p class="muted">Not signed in to the mobile app on any phone.</p>';
    $fields = '';
    foreach ($hidden as $k => $v) $fields .= '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
    $out = '<ul>';
    foreach ($devices as $d) {
        $name = $d['device_name'] ?: 'A phone';
        $out .= '<li><strong>' . h($name) . '</strong>: signed in ' . fmt_date($d['created_at']) . '. ' . h(app_device_state($d)) . '.';
        if (empty($d['wipe_requested_at']) && empty($d['wiped_at'])) {
            $out .= '<form method="post" action="' . h($action) . '" class="inline-form">' . csrf_field() . $fields
                . '<input type="hidden" name="device_id" value="' . (int)$d['id'] . '">'
                . '<button type="submit" name="device_signout" value="1" class="btn btn-secondary btn-sm">Sign out<span class="visually-hidden"> ' . h($name) . '</span></button> '
                . '<button type="submit" name="device_lost" value="1" class="btn btn-danger btn-sm" onclick="return confirm(' . h(json_encode('Report ' . $name . ' lost? The next time it connects, the app removes its drafts, its unsent offline entries and its saved lists, and signs out. Entries not yet sent are lost.', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) . ');">Report lost and wipe<span class="visually-hidden"> ' . h($name) . '</span></button></form>';
        }
        $out .= '</li>';
    }
    return $out . '</ul>';
}
