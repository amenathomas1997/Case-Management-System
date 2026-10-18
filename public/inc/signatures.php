<?php
declare(strict_types=1);
// E-signature for client documents (4.23.0): consent to services, an equipment loan agreement, or any
// document staff write. A request is emailed to the client (or guardian) with a private link to
// sign.php, where they read it and sign by typing their name, as on the release of information. The
// words are kept with the request as sent, so what was signed can always be shown again; a signed loan
// agreement also records the borrower's acceptance on the loan.

/** What can be sent to sign. */
const SIGN_KINDS = ['services' => 'Consent to services', 'loan' => 'Equipment loan agreement', 'document' => 'Another document'];
/** Their titles, and the built-in wording, for a client who reads Spanish. */
const SIGN_KINDS_ES = ['services' => 'Consentimiento para servicios', 'loan' => 'Acuerdo de préstamo de equipo'];
const SIGN_WORDING_ES = [
    'sign_services' => "Podemos darle la capacitación y los servicios planeados con usted y con la agencia que los paga.\nGuardamos registros de sus servicios y los compartimos solo para planificar servicios, para cobrar a la agencia que los paga y para enviar los informes que exige la ley.\nPodemos comunicarnos con usted sobre las citas y el progreso.\nPuede dejar los servicios en cualquier momento avisándonos.",
    'sign_loan' => "El dispositivo sigue siendo propiedad de quien lo presta y se presta para el fin acordado.\nLo cuidaré de manera razonable y les avisaré enseguida si se pierde, lo roban o se daña.\nLo devolveré, con lo que vino con él, en la fecha acordada o cuando me lo pidan.\nNo lo venderé, prestaré ni regalaré a nadie.",
];

/** The wording kept with a request, and its language: the organisation's own Spanish, else its own English, else the built-in text in the client's language. */
function signature_wording(string $key, string $lang): array {
    if ($lang === 'es') {
        if (($es = wording_stored($key, 'es')) !== '') return [$es, 'es'];
        if (($en = wording_stored($key, 'en')) !== '') return [$en, 'en'];
        return [SIGN_WORDING_ES[$key] ?? (string)(PUBLIC_WORDING[$key][1] ?? ''), isset(SIGN_WORDING_ES[$key]) ? 'es' : 'en'];
    }
    return [wording_text($key, 'en'), 'en'];
}

/** A request's state today. */
function signature_status(array $s): string {
    if (!empty($s['withdrawn_at'])) return 'Withdrawn';
    if (!empty($s['signed_at'])) return 'Signed';
    if (($s['method'] ?? 'Email') === 'In person') return 'Waiting to be signed here';
    return strtotime((string)$s['sent_at']) > time() - 86400 * cms_number('sign_link_days') ? 'Awaiting signature' : 'Link expired';
}

/** A client's requests, newest first. */
function client_signatures(int $clientId): array {
    try {
        $q = db()->prepare('SELECT s.*, u.name AS created_by_name FROM client_signatures s LEFT JOIN users u ON u.id = s.created_by WHERE s.client_id = ? ORDER BY s.sent_at DESC, s.id DESC');
        $q->execute([$clientId]);
        return $q->fetchAll();
    } catch (PDOException $e) {
        return []; // mig_4.23.0 not run yet
    }
}

/** A client's loans still out, for a loan agreement: id => "Device (serial), lent date". */
function signature_open_loans(int $clientId): array {
    $q = db()->prepare('SELECT l.id, l.loaned_at, l.due_date, e.name, e.serial FROM equipment_loans l JOIN equipment e ON e.id = l.equipment_id WHERE l.client_id = ? AND l.returned_at IS NULL ORDER BY l.loaned_at DESC');
    $q->execute([$clientId]);
    $out = [];
    foreach ($q->fetchAll() as $l) $out[(int)$l['id']] = $l;
    return $out;
}

/**
 * Check a request from posted fields. Returns [errors, values]. $loans are the client's open loans.
 */
function signature_input(array $in, array $loans, bool $inPerson = false): array {
    $s = fn(string $k): string => is_string($in[$k] ?? null) ? trim(str_replace("\r", '', $in[$k])) : '';
    $e = [];
    $v = ['kind' => $s('kind'), 'signer_role' => $s('signer_role') === 'Guardian' ? 'Guardian' : 'Client', 'signer_name' => $s('signer_name'),
        'signer_email' => $s('signer_email'), 'title' => $s('title'), 'body' => $s('body'), 'loan_id' => null];
    if (!isset(SIGN_KINDS[$v['kind']])) $e['kind'] = 'Choose what to send.';
    if ($v['signer_name'] === '' || mb_strlen($v['signer_name']) > 120) $e['signer_name'] = 'Who signs: enter their full name (120 characters or fewer).';
    // Signing in person (4.25.0) needs no email; one given is kept, and must be valid.
    if ($inPerson ? ($v['signer_email'] !== '' && (!filter_var($v['signer_email'], FILTER_VALIDATE_EMAIL) || mb_strlen($v['signer_email']) > 190))
        : (!filter_var($v['signer_email'], FILTER_VALIDATE_EMAIL) || mb_strlen($v['signer_email']) > 190)) $e['signer_email'] = 'Their email: enter one valid address.';
    if ($v['kind'] === 'loan') {
        $lid = ctype_digit($s('loan_id')) ? (int)$s('loan_id') : 0;
        if (!isset($loans[$lid])) $e['loan_id'] = 'Choose the device on loan.';
        else $v['loan_id'] = $lid;
    }
    if ($v['kind'] === 'document') {
        if ($v['title'] === '' || mb_strlen($v['title']) > 190) $e['title'] = 'Title: name the document (190 characters or fewer).';
        if ($v['body'] === '' || mb_strlen($v['body']) > 20000) $e['body'] = 'What they agree to: write the document (20,000 characters or fewer).';
    }
    return [$e, $v];
}

/**
 * The title, words and language kept with a request, in the client's language where the wording has it.
 * A document staff write is kept as written, its language unknown (null).
 */
function signature_document(array $v, array $client, array $loans): array {
    $lang = client_lang($client);
    if ($v['kind'] === 'services') {
        [$text, $tl] = signature_wording('sign_services', $lang);
        return [$tl === 'es' ? SIGN_KINDS_ES['services'] : SIGN_KINDS['services'], $text, $tl];
    }
    if ($v['kind'] === 'loan') {
        [$text, $tl] = signature_wording('sign_loan', $lang);
        $l = $loans[(int)$v['loan_id']];
        $es = $tl === 'es';
        $item = trim($l['name'] . ($l['serial'] ? ' (' . ($es ? 'número de serie ' : 'serial number ') . $l['serial'] . ')' : ''));
        $date = fn(string $d) => $es ? date('d/m/Y', strtotime($d)) : fmt_date($d);
        return [($es ? SIGN_KINDS_ES['loan'] : SIGN_KINDS['loan']) . ': ' . $item,
            ($es ? 'Dispositivo: ' : 'Device: ') . $item . "\n" . ($es ? 'Prestado el: ' : 'Lent on: ') . $date($l['loaned_at'])
            . ($l['due_date'] ? "\n" . ($es ? 'Fecha de devolución: ' : 'Due back: ') . $date($l['due_date']) : '') . "\n\n" . $text, $tl];
    }
    return [$v['title'], $v['body'], null];
}

/** Email a request to sign. Returns ['error' => ?string, 'id' => int]; nothing is kept when the email cannot be sent. */
function signature_send(array $client, array $v, array $loans, int $actor): array {
    [$title, $body, $lang] = signature_document($v, $client, $loans);
    // The same request again within two minutes (a retry after a lost reply) is not emailed twice.
    $again = db()->prepare("SELECT id FROM client_signatures WHERE client_id = ? AND method = 'Email' AND kind = ? AND title = ? AND signer_email = ? AND created_by = ? AND withdrawn_at IS NULL AND sent_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE) LIMIT 1");
    $again->execute([(int)$client['id'], $v['kind'], mb_substr($title, 0, 190), $v['signer_email'], $actor]);
    if ($id = (int)$again->fetchColumn()) return ['error' => null, 'id' => $id];
    $token = bin2hex(random_bytes(24));
    db()->prepare('INSERT INTO client_signatures (client_id, kind, title, body, lang, loan_id, signer_role, signer_name, signer_email, token, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$client['id'], $v['kind'], mb_substr($title, 0, 190), $body, $lang, $v['loan_id'], $v['signer_role'], $v['signer_name'], $v['signer_email'], $token, $actor]);
    $id = (int)db()->lastInsertId();
    [$subj, $mail] = email_template_for_client('sign_request', $client, ['name' => $v['signer_name'], 'document' => $title,
        'link' => cfg('base_url') . '/sign.php?t=' . $token . (client_lang($client) === 'es' ? '&lang=es' : '')]);
    if (!send_app_mail($v['signer_email'], $subj, $mail)) {
        db()->prepare('DELETE FROM client_signatures WHERE id = ?')->execute([$id]);
        return ['error' => 'The document could not be emailed, so nothing was saved. Try again later.', 'id' => 0];
    }
    audit($actor, 'signature.request', 'client', (int)$client['id'], $title . ' to ' . $v['signer_email']);
    return ['error' => null, 'id' => $id];
}

/**
 * Start a document to sign in person (4.25.0): nothing is emailed; the staff member hands over their device
 * and the signer reads it there. Returns the new request's id.
 */
function signature_start_in_person(array $client, array $v, array $loans, int $actor): int {
    [$title, $body, $lang] = signature_document($v, $client, $loans);
    // The same document started again today (a second press, or Start after a cancelled hand-over) is reused.
    $same = db()->prepare("SELECT id FROM client_signatures WHERE client_id = ? AND method = 'In person' AND signed_at IS NULL AND withdrawn_at IS NULL
        AND kind = ? AND title = ? AND signer_name = ? AND body = ? AND sent_at > DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY id DESC LIMIT 1");
    $same->execute([(int)$client['id'], $v['kind'], mb_substr($title, 0, 190), $v['signer_name'], $body]);
    if ($id = (int)$same->fetchColumn()) return $id;
    db()->prepare("INSERT INTO client_signatures (client_id, kind, title, body, lang, loan_id, signer_role, signer_name, signer_email, token, created_by, method) VALUES (?,?,?,?,?,?,?,?,?,?,?,'In person')")
        ->execute([(int)$client['id'], $v['kind'], mb_substr($title, 0, 190), $body, $lang, $v['loan_id'], $v['signer_role'], $v['signer_name'], $v['signer_email'], bin2hex(random_bytes(24)), $actor]);
    $id = (int)db()->lastInsertId();
    audit($actor, 'signature.in_person', 'client', (int)$client['id'], $title);
    return $id;
}

/** Whether a typed name matches the signer's name (capitals, accents and punctuation aside). */
function signature_name_matches(string $want, string $typed): bool {
    if ($typed === '' || !mb_check_encoding($typed, 'UTF-8') || mb_strlen($typed) > 120) return false;
    $key = typed_name_key($want);
    return $key !== '' ? typed_name_key($typed) === $key : mb_convert_case($typed, MB_CASE_FOLD, 'UTF-8') === mb_convert_case(trim($want), MB_CASE_FOLD, 'UTF-8');
}

/**
 * Sign in person: the typed name, and a drawn signature (PNG bytes) when the device took one. Returns null when
 * signed, else the reason in plain words.
 */
function signature_sign_in_person(array $r, string $typed, ?string $png, ?int $staff = null): ?string {
    if (!empty($r['withdrawn_at'])) return 'This document was withdrawn.';
    if (!empty($r['signed_at'])) return 'This document is already signed.';
    // Only a document started for signing in person, today: one sent by email is signed by its own link.
    if (($r['method'] ?? '') !== 'In person') return 'This document was sent by email: it is signed from the link in that email.';
    if (strtotime((string)$r['sent_at']) < time() - 86400) return 'This document was started more than a day ago. Start it again to sign it.';
    if (!signature_name_matches((string)$r['signer_name'], $typed)) return 'The name typed does not match the name on the document: ' . $r['signer_name'] . '.';
    $stored = null;
    if ($png !== null && $png !== '') {
        if (strlen($png) > 2 * 1024 * 1024 || !str_starts_with($png, "\x89PNG\r\n\x1a\n")) return 'The drawn signature could not be read. Clear it and sign again, or sign by typed name only.';
        $dir = cfg('upload_dir');
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $stored = 'sig-' . bin2hex(random_bytes(16)) . '.png';
        if (file_put_contents($dir . '/' . $stored, $png) === false) return 'The signature could not be saved. Please try again.';
    }
    $_SERVER['REMOTE_ADDR'] ??= '';
    $who = null;
    if ($staff) { $u = db()->prepare('SELECT name FROM users WHERE id = ?'); $u->execute([$staff]); $who = (string)($u->fetchColumn() ?: 'staff'); }
    if (!signature_sign($r, $typed, ' Signed in person' . ($who ? ' on the device of ' . $who : '') . ($stored ? ', with a drawn signature' : '') . '.')) { if ($stored) @unlink(cfg('upload_dir') . '/' . $stored); return 'This document is already signed.'; }
    db()->prepare('UPDATE client_signatures SET signature_path = ? WHERE id = ?')->execute([$stored, (int)$r['id']]);
    if ($staff) audit($staff, 'signature.signed_in_person', 'client', (int)$r['client_id'], (string)$r['title'] . ' signed by ' . $typed);
    return null;
}

/** One of a client's documents to sign, or null. */
function client_signature(int $id, int $clientId): ?array {
    $q = db()->prepare('SELECT * FROM client_signatures WHERE id = ? AND client_id = ?');
    $q->execute([$id, $clientId]);
    return $q->fetch() ?: null;
}

/** Sign a request with the typed name. True when this call signed it. */
function signature_sign(array $r, string $typed, string $how = ''): bool {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('UPDATE client_signatures SET signed_at = NOW(), signed_name = ?, sign_ip = ? WHERE id = ? AND signed_at IS NULL AND withdrawn_at IS NULL');
        $s->execute([$typed, $_SERVER['REMOTE_ADDR'] ?? '', (int)$r['id']]);
        $done = $s->rowCount() === 1;
        if ($done) {
            $pdo->prepare('INSERT INTO client_notes (client_id, note_type, subject, body, author_id) VALUES (?,?,?,?,NULL)')
                ->execute([(int)$r['client_id'], 'Case Note', 'Signed: ' . mb_substr((string)$r['title'], 0, 150),
                    $r['title'] . ' electronically signed by ' . $typed . (trim((string)$r['signer_email']) !== '' ? ' (' . $r['signer_email'] . ')' : '') . ' on ' . date('F j, Y g:i A') . '.' . $how]);
            // A loan agreement is also the borrower's acceptance of the loan, unless one was recorded at hand-off.
            if (!empty($r['loan_id'])) $pdo->prepare('UPDATE equipment_loans SET accepted_name = COALESCE(accepted_name, ?), accepted_at = COALESCE(accepted_at, NOW()) WHERE id = ? AND returned_at IS NULL')->execute([$typed, (int)$r['loan_id']]);
        }
        $pdo->commit();
        return $done;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Withdraw a request (signed or not). False when there was nothing to withdraw. */
function signature_withdraw(int $id, int $clientId, int $actor): bool {
    $w = db()->prepare('UPDATE client_signatures SET withdrawn_at = NOW(), withdrawn_by = ? WHERE id = ? AND client_id = ? AND withdrawn_at IS NULL');
    $w->execute([$actor, $id, $clientId]);
    if ($w->rowCount() !== 1) return false;
    audit($actor, 'signature.withdraw', 'client', $clientId, 'signature #' . $id);
    return true;
}
