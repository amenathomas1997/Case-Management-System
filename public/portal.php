<?php
// Read-only client portal — accessed via a private tokenized link emailed to the client.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__.'/inc/workflows.php';
$homeworkExplanation=null;
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/at_services.php';
enable_public_translation();
// Copy used only on this page (its Spanish lives here, beside the English).
register_translations([
    'Send your instructor a request for a new time for this session?' => '¿Enviar a su instructor una solicitud de otro horario para esta sesión?',
    'Choose OK to send the request, or Cancel to send nothing.' => 'Elija Aceptar para enviar la solicitud, o Cancelar para no enviar nada.',
    'Send your instructor a request to cancel this session?' => '¿Enviar a su instructor una solicitud para cancelar esta sesión?',
    'Choose OK to send the request. Choose Cancel to keep the session and send nothing.' => 'Elija Aceptar para enviar la solicitud. Elija Cancelar para mantener la sesión y no enviar nada.',
    'Tell your instructor you finished this homework?' => '¿Avisar a su instructor que terminó esta tarea?',
    'Tell your instructor you practiced this item?' => '¿Avisar a su instructor que practicó este tema?',
    'Choose OK to tell your instructor, or Cancel to send nothing.' => 'Elija Aceptar para avisar a su instructor, o Cancelar para no enviar nada.',
    '(required)' => '(obligatorio)',
    '(optional)' => '(opcional)',
    'Whole minutes, up to 600.' => 'Minutos enteros, hasta 600.',
    'Enter the minutes as a whole number from 0 to 600, or leave it blank.' => 'Escriba los minutos como un número entero de 0 a 600, o déjelo en blanco.',
    'Upload a document for your instructor — a form, an ID, or anything they asked for. PDF, Office, text or image files up to 15 megabytes (MB).' => 'Suba un documento para su instructor — un formulario, una identificación o cualquier cosa que le hayan pedido. Archivos PDF, de Office, de texto o de imagen de hasta 15 megabytes (MB).',
    'No guides match. Clear the search to see all guides.' => 'Ninguna guía coincide. Borre la búsqueda para ver todas las guías.',
    // Comma grouping, as in the matching field errors and the rest of the US Spanish copy.
    'Up to 2,000 characters.' => 'Hasta 2,000 caracteres.',
    'Up to 5,000 characters.' => 'Hasta 5,000 caracteres.',
    'Up to 16,000 characters.' => 'Hasta 16,000 caracteres.',
    'Email on file:' => 'Correo electrónico registrado:',
    'No email on file.' => 'No hay correo electrónico registrado.',
    'Ask your instructor to change it.' => 'Pida a su instructor que lo cambie.',
    'Your instructor updated your information while this page was open, so nothing was saved. The form now shows the latest details, with the changes you typed kept. Check it, then choose Save my information again.' => 'Su instructor actualizó su información mientras esta página estaba abierta, así que no se guardó nada. El formulario ahora muestra los datos más recientes, con los cambios que usted escribió. Revíselo y elija Guardar mi información otra vez.',
    'You have reached today\'s limit for this. Please try again tomorrow, or contact your instructor.' => 'Llegó al límite de hoy para esto. Inténtelo de nuevo mañana o comuníquese con su instructor.',
    'Your training has ended. You can still read this page, but it can no longer send messages, requests, updates or files. To start training again, contact your instructor.' => 'Su capacitación terminó. Todavía puede leer esta página, pero ya no puede enviar mensajes, solicitudes, cambios ni archivos. Para empezar de nuevo, comuníquese con su instructor.',
    'Your instructor already knows about this homework, or it is no longer assigned to you.' => 'Su instructor ya sabe de esta tarea, o ya no está asignada a usted.',
    'Your message was already sent, so it was not sent again.' => 'Su mensaje ya se había enviado, así que no se envió otra vez.',
    'Your request was already sent, so it was not sent again. Your instructor will follow up.' => 'Su solicitud ya se había enviado, así que no se envió otra vez. Su instructor le dará seguimiento.',
    'That document was already uploaded, so it was not uploaded again.' => 'Ese documento ya se había subido, así que no se subió otra vez.',
    'Your practice was already logged, so it was not logged again.' => 'Su práctica ya estaba registrada, así que no se registró otra vez.',
    'That file is no longer available. Your instructor may have removed it.' => 'Ese archivo ya no está disponible. Es posible que su instructor lo haya quitado.',
    'The explanation is not available right now. Ask your instructor.' => 'La explicación no está disponible en este momento. Pregunte a su instructor.',
    'Your explanation is ready below.' => 'Su explicación está lista abajo.',
    'Your explanation is ready below. It was cut off, so ask your instructor before using it.' => 'Su explicación está lista abajo. Quedó incompleta, así que pregunte a su instructor antes de usarla.',
    'AI explanation — check with your instructor' => 'Explicación de IA — consulte con su instructor',
    'This explanation was cut off. Ask your instructor before using it.' => 'Esta explicación quedó incompleta. Pregunte a su instructor antes de usarla.',
    'Your question:' => 'Su pregunta:',
    'Ask us something' => 'Pregúntenos algo',
    'What is it about?' => '¿De qué se trata?',
    'An appointment' => 'Una cita',
    'Something else' => 'Otra cosa',
    'Preferred days and times, what you need, or your question.' => 'Los días y horas que prefiere, qué necesita o su pregunta.',
    'Practiced on your own? Log it here so your instructor can see your progress between sessions.' => '¿Practicó por su cuenta? Regístrelo aquí para que su instructor vea su progreso entre sesiones.',
    // "Your progress": one line per training plan, built from these pieces and numbers.
    'Your progress' => 'Su progreso',
    'goal met.' => 'meta lograda.',
    'goals met.' => 'metas logradas.',
    'No goals set yet.' => 'Todavía no hay metas.',
    'hour used.' => 'hora usada.',
    'hours used.' => 'horas usadas.',
    'Next session:' => 'Próxima sesión:',
    'No session scheduled yet.' => 'Todavía no hay una sesión programada.',
    'No homework to do.' => 'No tiene tarea pendiente.',
    'homework item to do.' => 'tarea pendiente.',
    'homework items to do.' => 'tareas pendientes.',
]);

/** A number of hours without its unit, as fmt_hours() shows it ("3.5", "1,000"). */
function portal_hours_num(float $n): string {
    return rtrim(rtrim(number_format($n, 2), '0'), '.');
}
/** The "Your progress" line for one active track: goals met, hours used (of the
 *  hours authorized, when an authorization is entered — the same numbers as the
 *  staff track page), the next scheduled session and the homework still assigned.
 *  Plain sentences in the page's language; no percentages. $sessions are the
 *  client's upcoming sessions in start order. Returns HTML. */
function portal_progress_line(array $track, array $sessions): string {
    $tid = (int)$track['id'];
    $g = db()->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(status = 'Achieved'), 0) AS met FROM smart_goals WHERE track_id = ?");
    $g->execute([$tid]);
    $goals = $g->fetch();
    $goalTotal = (int)$goals['total']; $goalMet = (int)$goals['met'];
    $r = track_rollup($tid);
    $used = (float)$r['hours_worked']; $auth = (float)$r['authorized_hours'];
    $hw = db()->prepare("SELECT COUNT(*) FROM homework WHERE track_id = ? AND status = 'Assigned'");
    $hw->execute([$tid]);
    $hwOpen = (int)$hw->fetchColumn();
    $next = null;
    foreach ($sessions as $s) if ((int)($s['track_id'] ?? 0) === $tid) { $next = $s; break; }

    $parts = [];
    $parts[] = $goalTotal === 0 ? t('No goals set yet.')
        : $goalMet . ' ' . h(__('of')) . ' ' . $goalTotal . ' ' . ($goalTotal === 1 ? t('goal met.') : t('goals met.'));
    $parts[] = $auth > 0 ? portal_hours_num($used) . ' ' . h(__('of')) . ' ' . portal_hours_num($auth) . ' ' . t('hours used.')
        : portal_hours_num($used) . ' ' . ($used === 1.0 ? t('hour used.') : t('hours used.'));
    if ($next) {
        $ts = strtotime($next['start_at']);
        // A Spanish page gets a Spanish date; an English date is read in English (en()).
        $parts[] = t('Next session:') . ' ' . (page_lang() === 'es' ? h(portal_when_es($ts)) : en(portal_date('l, F j \a\t g:i A T', $ts))) . '.';
    } else {
        $parts[] = t('No session scheduled yet.');
    }
    $parts[] = $hwOpen === 0 ? t('No homework to do.') : $hwOpen . ' ' . ($hwOpen === 1 ? t('homework item to do.') : t('homework items to do.'));
    return '<strong>' . en((string)$track['title']) . ':</strong> ' . implode(' ', $parts);
}

/** The contact details and access needs the portal form edits, as on file, in the
 *  form they are compared in. (The email address is not edited here: a portal
 *  link holder could otherwise redirect the next replacement link to themselves.) */
function portal_profile_values(array $client): array {
    return [
        'phone' => trim((string)$client['phone']),
        'address' => trim((string)$client['address']),
        'a11y_screen_reader' => !empty($client['a11y_screen_reader']) ? '1' : '0',
        'a11y_format' => trim((string)$client['a11y_format']),
        'a11y_primary_tech' => trim((string)$client['a11y_primary_tech']),
        'a11y_contact' => $client['a11y_contact'] === 'No preference' ? '' : trim((string)$client['a11y_contact']),
        'a11y_notes' => str_replace("\r\n", "\n", trim((string)$client['a11y_notes'])),
    ];
}
/** The form field that edits each profile value. */
const PORTAL_PROFILE_FIELDS = ['phone' => 'p_phone', 'address' => 'p_address', 'a11y_screen_reader' => 'p_sr', 'a11y_format' => 'p_format',
    'a11y_primary_tech' => 'p_tech', 'a11y_contact' => 'p_contact', 'a11y_notes' => 'p_notes'];

/** Bytes in a php.ini size such as "8M" (0 when unset or unlimited). */
function portal_ini_bytes(string $v): int {
    $v = trim($v);
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
}
/** The largest file this server accepts, in whole MB, never above the 15 MB the page offers. */
function portal_upload_max_mb(): int {
    $mb = [15];
    foreach (['upload_max_filesize', 'post_max_size'] as $k) {
        $b = portal_ini_bytes((string)ini_get($k));
        if ($b > 0) $mb[] = max(1, intdiv($b, 1048576));
    }
    return min($mb);
}
/** A session's day and time in Spanish, for the Spanish confirm dialogs: a native
 *  dialog cannot mark an English date lang="en", so the voice would misread it. */
function portal_when_es(int $ts): string {
    return zone_when_es(portal_tz(), $ts); // shared with the Spanish emails (inc/core.php), in the client's zone
}
/** The client's time zone when it is not the site's (null: show times as they are). */
function portal_tz(): ?string {
    return $GLOBALS['PORTAL_TZ'] ?? null;
}
/** date() in the client's time zone. */
function portal_date(string $format, int $ts): string {
    return zone_date(portal_tz(), $format, $ts);
}
/** Tell the client's instructor about portal activity, or every administrator when
 *  nobody is assigned or the assigned account has been deactivated: an alert to a
 *  deactivated account is never seen. $client carries active_owner from the lookup. */
function portal_notify_owner(array $client, string $ownerMsg, string $link, ?string $adminMsg = null, bool $urgent = false): void {
    $owner = (int)($client['active_owner'] ?? 0);
    // A message or a request from the client is urgent: the alert can also be emailed (4.0.0).
    if ($owner) notify_user($owner, $ownerMsg, $link, $urgent);
    else notify_admin_users($adminMsg ?? $ownerMsg, $link, $urgent);
}

$rawToken = $_GET['t'] ?? '';
$token = is_string($rawToken) ? preg_replace('/[^a-f0-9]/', '', $rawToken) : '';
$client = null;
if (strlen($token) === 48) {
    // Expired links stop working; staff resend to issue a fresh one. So does every
    // link of a retention-archived client: the record is out of day-to-day use.
    // active_owner: the assigned instructor only while their account is active.
    $stmt = db()->prepare('SELECT c.*, au.id AS active_owner FROM portal_tokens p JOIN clients c ON c.id = p.client_id
        LEFT JOIN users au ON au.id = c.assigned_to AND au.is_active = 1
        WHERE p.token = ? AND (p.expires_at IS NULL OR p.expires_at > NOW()) AND c.archived_at IS NULL');
    $stmt->execute([$token]);
    $client = $stmt->fetch();
    // Each use keeps the link open for another six months (3.0.40).
    if ($client) portal_link_touch('portal_tokens', 'token', $token);
    // A client who prefers Spanish gets the portal in Spanish from the first visit;
    // the Español/English toggle (which sets the session language) still decides after that.
    if ($client && !isset($_SESSION['lang']) && client_lang($client) === 'es') $_SESSION['lang'] = 'es';
    // Times on the portal are the client's own (4.1.0).
    if ($client) $GLOBALS['PORTAL_TZ'] = client_zone($client);
}
// A closed case keeps its portal readable (history, messages, resources) but sends
// nothing more: no messages, requests, updates, files or AI explanations.
$caseClosed = $client && str_starts_with((string)$client['status'], 'Closed');
$closedMsg = 'Your training has ended. You can still read this page, but it can no longer send messages, requests, updates or files. To start training again, contact your instructor.';
$hwError = null;

// Client interactions (homework self-report, message to the team). Token-scoped;
// each action is verified against this client's own records. Every form and every
// redirect carries the page language (lang=), so a lapsed session keeps it.
if ($client && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // A body over post_max_size reaches PHP with $_POST and $_FILES emptied, the
    // CSRF token included: say the file was too big, beside the file field, instead
    // of the general notice csrf_check() would give. Nothing is changed.
    $postMax = portal_ini_bytes((string)ini_get('post_max_size'));
    if (!$_POST && !$_FILES && $postMax > 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $postMax) {
        keep_field_errors(['document' => 'That file is too large (' . portal_upload_max_mb() . ' MB maximum).']);
        redirect('portal.php?t=' . $token . '&lang=' . page_lang());
    }
    // A stale form token (the session lapsed while the page sat open) brings the
    // client back to this page with what was typed refilled: csrf_check() handles
    // the public pages that way and never sends them to the staff sign-in page.
    csrf_check();
    if ($caseClosed) {
        flash_ui($closedMsg, 'error');
        redirect('portal.php?t=' . $token . '&lang=' . page_lang());
    }
    if ((isset($_POST['hw_practice']) || isset($_POST['hw_explain'])) && workflows_ready()) {
        $hwId = (int)($_POST['homework_id'] ?? 0);
        try {
            foreach ($_POST as $value) if (!is_scalar($value)) throw new DomainException('Invalid homework request.');
            if (isset($_POST['hw_practice'])) {
                workflow_practice($_POST,$client);
                flash_ui('Your practice update was recorded for your instructor.');
                redirect('portal.php?t='.$token.'&lang='.page_lang());
            }
            $question = (string)($_POST['homework_question'] ?? '');
            $res = workflow_homework_explain($hwId, $client, $question, page_lang());
            // The AI service's own error (a rejected key, a used-up quota) is for staff:
            // the client is told plainly that the explanation is not available.
            if (!$res['ok']) throw new DomainException('The explanation is not available right now. Ask your instructor.');
            // Post/redirect/get: a refresh or Back never sends the question again (each
            // one is a paid request and uses one of the day's ten). The answer is shown
            // once, inside the homework it answers, and the message says it is there.
            $_SESSION['hw_explanation'] = ['token' => $token, 'homework_id' => $hwId, 'question' => trim($question),
                'text' => (string)$res['text'], 'truncated' => !empty($res['truncated'])];
            flash_ui(!empty($res['truncated']) ? 'Your explanation is ready below. It was cut off, so ask your instructor before using it.' : 'Your explanation is ready below.');
            redirect('portal.php?t=' . $token . '&lang=' . page_lang() . '#hw-' . $hwId);
        } catch (DomainException $e) { $hwError = $e->getMessage(); }
        catch (Throwable $e) { error_log('Homework support failed: '.get_class($e)); $hwError = 'Could not complete the request. Please try again.'; }
        if ($hwError !== null) {
            // Said inside the homework it is about, beside its form (filled in again),
            // unless that homework is no longer listed: then at the top of the page.
            $hq = db()->prepare("SELECT 1 FROM homework h JOIN training_tracks t ON t.id = h.track_id WHERE h.id = ? AND t.client_id = ? AND h.status = 'Assigned'");
            $hq->execute([$hwId, (int)$client['id']]);
            // A feature that is switched off or not working is not something the client
            // got wrong: it is said plainly, without "Please fix the following".
            $hwSystem = in_array($hwError, ['AI explanations are not enabled. Ask your instructor for help.', 'The explanation is not available right now. Ask your instructor.'], true);
            if ($hwSystem) $hwError = "This feature isn't available right now. Your instructor can help.";
            if (!$hq->fetchColumn()) { flash_ui($hwError, $hwSystem ? 'info' : 'error'); redirect('portal.php?t=' . $token . '&lang=' . page_lang()); }
            $hwError = ['id' => $hwId, 'msg' => $hwError, 'field' => (isset($_POST['hw_practice']) ? 'hw-min-' : 'hw-question-') . $hwId, 'system' => $hwSystem];
            // Only a problem with what was typed marks the field as needing a fix.
            if (!$hwSystem) set_form_errors(['hw_error' => $hwError['msg']]);
        }
    }

    /* A 24-hour ceiling per client on each kind of write, beside the short windows
       below: one leaked link cannot fill the record with case notes, tasks and
       alerts. Administrators are told once a day per action when a ceiling is hit. */
    $overDailyCap = function (string $key, int $max, string $what) use ($client): bool {
        $q = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $q->execute([$key]);
        if ((int)$q->fetchColumn() < $max) return false;
        $q->execute(['portal-cap-' . $key]);
        if ((int)$q->fetchColumn() === 0) {
            db()->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?,?,1)")->execute([$_SERVER['REMOTE_ADDR'] ?? '', 'portal-cap-' . $key]);
            notify_admin_users('Portal daily limit reached (' . $what . ') for ' . $client['name'] . '. If this was not the client, revoke the portal link.',
                'client.php?id=' . (int)$client['id']);
        }
        return true;
    };
    $capMsg = 'You have reached today\'s limit for this. Please try again tomorrow, or contact your instructor.';
    // A device check-in the client answered (3.0.42): saved as client-reported.
    if (isset($_POST['device_checkin'])) {
        $use = $_POST['in_use'] ?? '';
        $satIn = (int)($_POST['satisfaction'] ?? 0);
        $done = portal_device_checkin_save((int)($_POST['followup_id'] ?? 0), $client,
            $use === 'yes' ? 1 : ($use === 'no' ? 0 : null), $satIn >= 1 && $satIn <= 5 ? $satIn : null,
            is_string($_POST['barriers'] ?? null) ? $_POST['barriers'] : '');
        if ($done) {
            portal_notify_owner($client, $client['name'] . ' answered a device check-in', 'followups.php');
            flash_ui('Thank you. Your answers were sent to your instructor.');
        } else {
            flash_ui('That check-in was already answered.', 'info');
        }
        redirect('portal.php?t=' . $token . '&lang=' . page_lang() . '#dev-h');
    }
    if (isset($_POST['at_learning_done']) && at_ready()) {
        // Up to 5 updates a minute per client: enough to report several exercises in a row.
        $rl = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)");
        $rl->execute(['portal-learn-' . (int)$client['id']]);
        if ((int)$rl->fetchColumn() >= 5) {
            flash_ui('Please wait a moment, then press the button again.', 'error');
        } else {
            db()->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?,?,1)")->execute([$_SERVER['REMOTE_ADDR'] ?? '', 'portal-learn-' . (int)$client['id']]);
            // Only the first transition changes a row; notify the instructor then (parity with hw_done).
            if (at_portal_report((int)($_POST['at_learning_id'] ?? 0), (int)$client['id']) > 0) {
                $fn = explode(' ', $client['name'])[0];
                portal_notify_owner($client, $fn . ' reported practice on assigned learning', 'client.php?id=' . (int)$client['id'],
                    $client['name'] . ' reported practice on assigned learning');
                flash_ui('Your practice update was recorded for your instructor.');
            } else {
                flash_ui('Your instructor already has your practice update for this item.');
            }
        }
        redirect('portal.php?t=' . $token . '&lang=' . page_lang());
    }
    if (isset($_POST['hw_done'])) {
        $hid = (int)($_POST['homework_id'] ?? 0);
        // Only homework on this client's own tracks can be marked.
        // client_reported_at IS NULL so a replayed POST can't re-notify staff for an
        // already-reported item (status stays 'Assigned' until staff verify it).
        $hq = db()->prepare("SELECT h.id, h.title FROM homework h
            JOIN training_tracks t ON t.id = h.track_id
            WHERE h.id = ? AND t.client_id = ? AND h.status = 'Assigned' AND h.client_reported_at IS NULL");
        $hq->execute([$hid, (int)$client['id']]);
        if ($hw = $hq->fetch()) {
            db()->prepare('UPDATE homework SET client_reported_at = NOW() WHERE id = ?')->execute([$hid]);
            $fn = explode(' ', $client['name'])[0];
            portal_notify_owner($client, $fn . ' marked homework done: ' . $hw['title'], 'client.php?id=' . (int)$client['id'],
                $client['name'] . ' marked homework done: ' . $hw['title']);
            flash_ui('Thanks — we let your instructor know.');
        } else {
            // Already reported, or staff completed or removed it meanwhile: said, never silent.
            flash_ui('Your instructor already knows about this homework, or it is no longer assigned to you.', 'info');
        }
        redirect('portal.php?t=' . $token . '&lang=' . page_lang());
    }
    if (isset($_POST['send_message'])) {
        // Line breaks counted as the browser counts them (one character each).
        $msg = str_replace("\r\n", "\n", trim(is_string($_POST['message'] ?? '') ? $_POST['message'] : ''));
        // One message per minute per client (server-side, survives cookie clears).
        $rl = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)");
        $rl->execute(['portal-msg-' . (int)$client['id']]);
        // The same message again within the minute (a double press of Send) was already
        // sent: it is not sent twice, refilled, or reported as an error.
        $dupMsg = db()->prepare("SELECT 1 FROM messages WHERE client_id = ? AND channel = 'client' AND sender = 'client' AND body = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) LIMIT 1");
        $dupMsg->execute([(int)$client['id'], $msg]);
        if ($msg === '') {
            keep_field_errors(['message' => 'Please type a message first.']);
        } elseif (mb_strlen($msg) > 5000) {
            // Refused, never cut: the whole message comes back to shorten or split.
            keep_input();
            keep_field_errors(['message' => 'Your message is too long (5,000 characters maximum). Shorten it, or send it in two parts.']);
        } elseif ($dupMsg->fetchColumn()) {
            flash_ui('Your message was already sent, so it was not sent again.', 'info');
        } elseif ((int)$rl->fetchColumn() > 0) {
            keep_input(); // the message is refilled, not lost
            flash_ui('Please wait a moment before sending another message.', 'error');
        } elseif ($overDailyCap('portal-msg-' . (int)$client['id'], 50, 'messages')) {
            keep_input();
            flash_ui($capMsg, 'error');
        } else {
            db()->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?,?,1)")->execute([$_SERVER['REMOTE_ADDR'] ?? '', 'portal-msg-' . (int)$client['id']]);
            post_message((int)$client['id'], 'client', 'client', null, $msg);
            portal_notify_owner($client, 'Message from ' . $client['name'] . ' (portal)', 'messages.php?client=' . (int)$client['id'] . '&channel=client', null, true);
            flash_ui('Message sent — your instructor will get back to you.');
        }
        redirect('portal.php?t=' . $token . '&lang=' . page_lang());
    }
    // The client confirms one of the times offered on their request (3.0.52).
    if (isset($_POST['confirm_offer'])) {
        $offer = (int)($_POST['offer_id'] ?? 0);
        if ($offer <= 0) flash_ui('Choose one of the times first.', 'error');
        else {
            $res = offer_confirm($offer, (int)$client['id']);
            if ($res['error'] === null) flash_ui('Your session is booked. It is listed under Upcoming sessions.');
            else flash_ui($res['error'], 'error');
        }
        redirect('portal.php?t=' . $token . '&lang=' . page_lang() . '#req-h');
    }
    if (isset($_POST['new_request'])) {
        $type = in_array($_POST['req_type'] ?? '', ['appointment', 'reschedule', 'cancel', 'device', 'other', 'records', 'correction'], true) ? $_POST['req_type'] : 'other';
        $sessionId = (int)($_POST['session_id'] ?? 0) ?: null;
        $details = str_replace("\r\n", "\n", trim(is_string($_POST['details'] ?? null) ? $_POST['details'] : ''));
        // Throttle: 5 requests / 5 minutes / client (server-side).
        $rl = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
        $rl->execute(['portal-req-' . (int)$client['id']]);
        $detailsField = 'req_details'; // one form for every topic (appt_details was its own form before 3.0.27)
        // A referenced session must be this client's own.
        if ($sessionId) {
            $sc = db()->prepare('SELECT id FROM scheduled_sessions WHERE id = ? AND client_id = ?');
            $sc->execute([$sessionId, (int)$client['id']]);
            if (!$sc->fetchColumn()) $sessionId = null;
        }
        // The same request again (Send pressed twice) is not added twice: it would make a
        // second task and alert for staff.
        $dupReq = db()->prepare("SELECT 1 FROM portal_requests WHERE client_id = ? AND type = ? AND session_id <=> ? AND details <=> ? AND status = 'New'
            AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE) LIMIT 1");
        $dupReq->execute([(int)$client['id'], $type, $sessionId, $details !== '' ? $details : null]);
        if ($details === '' && !in_array($type, ['cancel', 'reschedule'], true)) {
            keep_input();
            keep_field_errors([$detailsField => 'Please add a little detail.']);
        } elseif (mb_strlen($details) > 2000) {
            keep_input(); // refused, never cut
            keep_field_errors([$detailsField => 'That is too long (2,000 characters maximum).']);
        } elseif ($dupReq->fetchColumn()) {
            flash_ui('Your request was already sent, so it was not sent again. Your instructor will follow up.', 'info');
        } elseif ((int)$rl->fetchColumn() >= 5) {
            keep_input(); // what was typed is refilled, not lost
            flash_ui('Please wait a moment before sending more requests.', 'error');
        } elseif ($overDailyCap('portal-req-' . (int)$client['id'], 30, 'requests')) {
            keep_input();
            flash_ui($capMsg, 'error');
        } else {
            db()->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?,?,1)")->execute([$_SERVER['REMOTE_ADDR'] ?? '', 'portal-req-' . (int)$client['id']]);
            // A request for a copy of the record or a correction is due in 30 days (3.0.38).
            $dueOn = isset(RECORDS_REQUEST_TYPES[$type]) ? date('Y-m-d', strtotime('+' . RECORDS_REQUEST_DAYS . ' days')) : null;
            db()->prepare('INSERT INTO portal_requests (client_id, type, session_id, details, due_on) VALUES (?,?,?,?,?)')
                ->execute([(int)$client['id'], $type, $sessionId, $details !== '' ? $details : null, $dueOn]);
            $reqId = (int)db()->lastInsertId();
            $labels = ['appointment' => 'appointment request', 'reschedule' => 'reschedule request', 'cancel' => 'cancellation request', 'device' => 'device help request', 'other' => 'request',
                'records' => 'request for a copy of their records (due ' . ($dueOn ? date('F j', strtotime($dueOn)) : '') . ')', 'correction' => 'request to correct their records (due ' . ($dueOn ? date('F j', strtotime($dueOn)) : '') . ')'];
            $label = $labels[$type] ?? 'request';
            // The follow-up task goes to an active instructor (unassigned otherwise, where
            // staff with client access see it); its key lets Client requests close it
            // when the request is set to Done or Declined.
            $aid = (int)($client['active_owner'] ?? 0);
            add_task('Client ' . $label . ': ' . $client['name'], mb_substr($details, 0, 400), (int)$client['id'], null, $dueOn ? date('Y-m-d', strtotime($dueOn . ' -5 days')) : business_days_from(2), $aid ?: null, null, 'preq-' . $reqId);
            portal_notify_owner($client, $client['name'] . ' sent a ' . $label, 'portal_requests.php?client=' . (int)$client['id'], null, true);
            flash_ui('Request sent — your instructor will follow up.');
        }
        redirect('portal.php?t=' . $token . '&lang=' . page_lang());
    }
    if (isset($_POST['upload_doc'])) {
        // Anyone holding the link can upload, so legacy .doc/.xls (which can carry
        // macros) are not accepted here; .docx/.xlsx/PDF cover the same documents.
        $allowed = ['pdf', 'docx', 'xlsx', 'txt', 'rtf', 'png', 'jpg', 'jpeg'];
        $rl = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE)");
        $rl->execute(['portal-up-' . (int)$client['id']]);
        $rlDay = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $rlDay->execute(['portal-up-' . (int)$client['id']]);
        keep_input(); // keeps the description; a file always has to be chosen again
        $upErr = $_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE;
        $docTitle = is_string($_POST['doc_title'] ?? null) ? trim($_POST['doc_title']) : '';
        // Each upload failure gets its own message, not "choose a file".
        if (empty($_FILES['document']['name']) || $upErr === UPLOAD_ERR_NO_FILE) {
            keep_field_errors(['document' => 'Please choose a file to upload.']);
        } elseif ($upErr === UPLOAD_ERR_INI_SIZE || $upErr === UPLOAD_ERR_FORM_SIZE) {
            keep_field_errors(['document' => 'That file is too large (' . portal_upload_max_mb() . ' MB maximum).']);
        } elseif ($upErr === UPLOAD_ERR_PARTIAL) {
            keep_field_errors(['document' => 'The upload was interrupted. Please try again.']);
        } elseif ($upErr !== UPLOAD_ERR_OK) {
            flash_ui('The file could not be saved. Please try again.', 'error');
        } elseif (mb_strlen($docTitle) > 190) {
            keep_field_errors(['doc_title' => 'That description is too long (190 characters maximum).']);
        } elseif ((int)$rl->fetchColumn() >= 10) {
            flash_ui('Please wait a moment before uploading more.', 'error');
        } elseif ((int)$rlDay->fetchColumn() >= 30) {
            flash_ui('You have shared a lot of documents today. Please try again tomorrow, or contact your instructor.', 'error');
        } else {
            $orig = (string)$_FILES['document']['name'];
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            // Storage used by this client's own uploads: today, and in all. Caps keep
            // a leaked link from filling the shared disk.
            $used = db()->prepare("SELECT path, created_at >= ? AS today FROM client_documents WHERE client_id = ? AND category = 'Client Upload'");
            $used->execute([date('Y-m-d 00:00:00'), (int)$client['id']]);
            $dayBytes = 0;
            $allBytes = 0;
            foreach ($used->fetchAll() as $u) {
                $f = cfg('upload_dir') . '/' . basename($u['path']);
                $b = is_file($f) ? (int)filesize($f) : 0;
                $allBytes += $b;
                if ($u['today']) $dayBytes += $b;
            }
            $size = (int)$_FILES['document']['size'];
            // The kept file name fits its column (190) and keeps its extension.
            $safeName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $orig);
            if (strlen($safeName) > 190) $safeName = substr($safeName, 0, 189 - strlen($ext)) . '.' . $ext;
            // The same file again within a few minutes (Upload pressed twice) is not
            // stored twice: same name and size as one this client just shared.
            $dupDoc = db()->prepare("SELECT path FROM client_documents WHERE client_id = ? AND category = 'Client Upload' AND orig_name = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
            $dupDoc->execute([(int)$client['id'], $safeName]);
            $isDupDoc = false;
            foreach ($dupDoc->fetchAll(PDO::FETCH_COLUMN) as $dp) {
                $df = cfg('upload_dir') . '/' . basename((string)$dp);
                if (is_file($df) && (int)filesize($df) === $size) { $isDupDoc = true; break; }
            }
            if ($ext === 'doc' || $ext === 'xls') {
                keep_field_errors(['document' => 'Older Word (.doc) and Excel (.xls) files cannot be accepted. Save the file as .docx, .xlsx or PDF, then upload it again.']);
            } elseif (!in_array($ext, $allowed, true)) {
                keep_field_errors(['document' => 'That file type is not allowed. Use PDF, Office, text or image files.']);
            } elseif ($size > 15 * 1024 * 1024) {
                keep_field_errors(['document' => 'That file is too large (15 MB maximum).']);
            } elseif ($isDupDoc) {
                unset($_SESSION['old_input']); // already saved: nothing to refill
                flash_ui('That document was already uploaded, so it was not uploaded again.', 'info');
            } elseif ($dayBytes + $size > 100 * 1024 * 1024) {
                flash_ui('You have shared a lot of documents today. Please try again tomorrow, or contact your instructor.', 'error');
            } elseif ($allBytes + $size > 500 * 1024 * 1024) {
                flash_ui('You have reached the storage limit for shared documents. Please contact your instructor.', 'error');
            } else {
                $dir = cfg('upload_dir'); if (!is_dir($dir)) mkdir($dir, 0750, true);
                $stored = bin2hex(random_bytes(16)) . '.' . $ext;
                if (move_uploaded_file($_FILES['document']['tmp_name'], $dir . '/' . $stored)) {
                    db()->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?,?,1)")->execute([$_SERVER['REMOTE_ADDR'] ?? '', 'portal-up-' . (int)$client['id']]);
                    try {
                        db()->prepare('INSERT INTO client_documents (client_id, category, title, orig_name, path, uploaded_by) VALUES (?,?,?,?,?,NULL)')
                            ->execute([(int)$client['id'], 'Client Upload', $docTitle !== '' ? $docTitle : mb_substr(mb_scrub($orig, 'UTF-8'), 0, 190),
                                $safeName, $stored]);
                        // A database without the 'Client Upload' category (mig_3.0.8 not run)
                        // under a non-strict SQL mode stores a blank category instead of
                        // refusing: such a row escapes the storage caps and never shows in
                        // "Documents you shared", so it is removed and the upload refused.
                        $docId = (int)db()->lastInsertId();
                        $cat = db()->prepare('SELECT category FROM client_documents WHERE id = ?');
                        $cat->execute([$docId]);
                        if ($cat->fetchColumn() !== 'Client Upload') {
                            db()->prepare('DELETE FROM client_documents WHERE id = ?')->execute([$docId]);
                            throw new UnexpectedValueException('client upload category not stored');
                        }
                    } catch (Throwable $e) {
                        // No file is left on disk for a document that was not recorded. The
                        // log line names the error type only, never the client or the file.
                        @unlink($dir . '/' . $stored);
                        error_log('Portal upload not recorded: ' . get_class($e) . ' (code ' . $e->getCode() . ')');
                        flash_ui('The file could not be saved. Please try again.', 'error');
                        redirect('portal.php?t=' . $token . '&lang=' . page_lang());
                    }
                    portal_notify_owner($client, $client['name'] . ' uploaded a document (portal)', 'client.php?id=' . (int)$client['id'] . '&tab=documents');
                    unset($_SESSION['old_input']); // saved: nothing to refill
                    flash_ui('Thanks — your document was uploaded.');
                } else {
                    flash_ui('The file could not be saved. Please try again.', 'error');
                }
            }
        }
        redirect('portal.php?t=' . $token . '&lang=' . page_lang());
    }
    if (isset($_POST['update_profile'])) {
        $in = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
        $nl = fn(?string $v): string => str_replace("\r\n", "\n", trim((string)$v));
        $fmts = ['Standard print', 'Large print', 'Plain text', 'Braille-ready (BRF)', 'Audio', 'Accessible PDF'];
        $contacts = ['Email', 'Phone', 'Client portal', 'Postal mail'];
        // What is on file, and what was sent, compared field by field: only the
        // fields the client actually changed are written. ("No preference" and a
        // blank contact choice mean the same.) The email address is not edited from
        // the portal (staff change it), so a posted one is ignored.
        $old = portal_profile_values($client);
        $new = [
            'phone' => $in('p_phone'),
            'address' => $in('p_address'),
            'a11y_screen_reader' => !empty($_POST['p_sr']) ? '1' : '0',
            'a11y_format' => $in('p_format'),
            'a11y_primary_tech' => $in('p_tech'),
            'a11y_contact' => $in('p_contact') === 'No preference' ? '' : $in('p_contact'),
            'a11y_notes' => $nl($in('p_notes')),
        ];
        // A choice outside the lists (other than the value already on file) leaves the field as it is.
        if ($new['a11y_format'] !== '' && !in_array($new['a11y_format'], $fmts, true)) $new['a11y_format'] = $old['a11y_format'];
        if ($new['a11y_contact'] !== '' && !in_array($new['a11y_contact'], $contacts, true)) $new['a11y_contact'] = $old['a11y_contact'];
        // The form carries a fingerprint of each value it showed. When staff changed
        // any of them after the page was drawn, nothing is saved: the stale page would
        // otherwise write its old values back over the correction, as the client's change.
        // The fingerprints come as one field (p_shown_json), which a refilled form keeps
        // with the values it refills; a page drawn before 3.0.21 sends p_shown[...].
        $shownJson = is_string($_POST['p_shown_json'] ?? null) ? json_decode($_POST['p_shown_json'], true) : null;
        $shown = is_array($shownJson) ? $shownJson : (is_array($_POST['p_shown'] ?? null) ? $_POST['p_shown'] : []);
        $fp = fn(string $v): string => hash('sha256', $v);
        $staleFields = array_filter(array_keys($old), fn($k) => !is_string($shown[$k] ?? null) || !hash_equals($fp($old[$k]), $shown[$k]));
        // Changed since the page was drawn, but to exactly what this save sends (the same
        // save sent twice, or the same correction made by staff): nothing conflicts.
        if ($staleFields && !array_filter($staleFields, fn($k) => $new[$k] !== $old[$k])) $staleFields = [];
        if ($staleFields) {
            // Only what the client typed differently from what they were shown is
            // refilled; every other field comes back with the details now on file.
            $edited = array_filter(array_keys($old), fn($k) => is_string($shown[$k] ?? null) ? !hash_equals($shown[$k], $fp($new[$k])) : $new[$k] !== $old[$k]);
            keep_input();
            $keep = array_map(fn($k) => PORTAL_PROFILE_FIELDS[$k], $edited);
            $_SESSION['old_input'] = array_intersect_key($_SESSION['old_input'] ?? [], array_flip(array_merge($keep, ['update_profile'])));
            $_SESSION['old_input']['update_profile'] = '1';
            $_SESSION['old_input']['p_kept'] = ',' . implode(',', $keep) . ',';
            flash_ui('Your instructor updated your information while this page was open, so nothing was saved. The form now shows the latest details, with the changes you typed kept. Check it, then choose Save my information again.', 'error');
            redirect('portal.php?t=' . $token . '&lang=' . page_lang() . '#info-h');
        }
        $changed = array_keys(array_filter($new, fn($v, $k) => $v !== $old[$k], ARRAY_FILTER_USE_BOTH));
        // Only what the client changed is checked: a value already on file (staff may
        // keep longer access notes) never blocks a save. Over-long entries are refused
        // on their field (limits match the staff form), never cut.
        $ch = fn(string $k): bool => in_array($k, $changed, true);
        $errors = [];
        if ($ch('phone') && mb_strlen($new['phone']) > 40) $errors['p_phone'] = 'Your phone is too long (40 characters maximum).';
        if ($ch('address') && mb_strlen($new['address']) > 255) $errors['p_address'] = 'Your address is too long (255 characters maximum).';
        if ($ch('a11y_primary_tech') && mb_strlen($new['a11y_primary_tech']) > 120) $errors['p_tech'] = 'That is too long (120 characters maximum).';
        if ($ch('a11y_notes') && mb_strlen($new['a11y_notes']) > 16000) $errors['p_notes'] = 'That is too long (16,000 characters maximum).';
        // Each save notifies the instructor and adds a case note: at most 5 per 10 minutes.
        $rl = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $rl->execute(['portal-prof-' . (int)$client['id']]);
        if ($errors) {
            keep_input();
            keep_field_errors($errors);
        } elseif (!$changed) {
            flash_ui('Your information is already up to date.');
        } elseif ((int)$rl->fetchColumn() >= 5) {
            keep_input(); // what was typed is refilled, not lost
            flash_ui('Please wait a few minutes before saving your information again.', 'error');
        } elseif ($overDailyCap('portal-prof-' . (int)$client['id'], 20, 'profile saves')) {
            keep_input();
            flash_ui($capMsg, 'error');
        } else {
            $labels = ['phone' => 'Phone', 'address' => 'Address', 'a11y_screen_reader' => 'Uses a screen reader',
                'a11y_format' => 'Preferred format', 'a11y_primary_tech' => 'Assistive technology', 'a11y_contact' => 'Contact preference',
                'a11y_notes' => 'Access notes'];
            $show = fn(string $k, string $v): string => $k === 'a11y_screen_reader' ? ($v === '1' ? 'yes' : 'no') : ($v === '' ? '(blank)' : $v);
            $sets = [];
            $vals = [];
            $lines = [];
            foreach ($changed as $k) {
                $sets[] = $k . ' = ?';
                $vals[] = $k === 'a11y_screen_reader' ? (int)$new[$k] : ($new[$k] !== '' ? $new[$k] : null);
                if ($k !== 'a11y_notes') $lines[] = $labels[$k] . ': ' . $show($k, $old[$k]) . ' → ' . $show($k, $new[$k]);
            }
            if (in_array('a11y_notes', $changed, true)) {
                $prev = mb_strcut($old['a11y_notes'], 0, 60000, 'UTF-8');
                $lines[] = "Access notes changed. The previous notes were:\n" . ($prev === '' ? '(blank)' : $prev)
                    . ($prev !== $old['a11y_notes'] ? "\n[…the rest of the previous notes was too long to copy here]" : '');
            }
            $vals[] = (int)$client['id'];
            // The change and the case note that records the old values commit together,
            // so staff can always see what was replaced and put it back.
            db()->beginTransaction();
            try {
                db()->prepare('UPDATE clients SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
                db()->prepare("INSERT INTO client_notes (client_id, note_type, subject, body, author_id) VALUES (?,?,?,?,NULL)")
                    ->execute([(int)$client['id'], 'Case Note', 'Contact details / access needs changed by client (portal)',
                        "The client changed these details on their portal (previous → new):\n\n" . implode("\n", $lines)]);
                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) db()->rollBack();
                throw $e;
            }
            db()->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?,?,1)")->execute([$_SERVER['REMOTE_ADDR'] ?? '', 'portal-prof-' . (int)$client['id']]);
            audit(null, 'portal.profile_update', 'client', (int)$client['id'], 'changed: ' . implode(', ', $changed));
            $what = $client['name'] . ' changed their ' . strtolower(implode(', ', array_map(fn($k) => $labels[$k], $changed))) . ' (portal)';
            portal_notify_owner($client, $what, 'client.php?id=' . (int)$client['id'] . '&tab=notes');
            flash_ui('Your information was updated. Thank you.');
        }
        redirect('portal.php?t=' . $token . '&lang=' . page_lang());
    }
    if (isset($_POST['log_practice'])) {
        $what = str_replace("\r\n", "\n", trim(is_string($_POST['practice'] ?? null) ? $_POST['practice'] : ''));
        // Minutes are optional, but what is entered is checked, never clamped or
        // dropped: whole minutes from 0 to 600. (A number the browser could not
        // read arrives blank with a minutes_bad marker from the page script.)
        $minsIn = trim(is_string($_POST['minutes'] ?? null) ? $_POST['minutes'] : '');
        $minsOk = empty($_POST['minutes_bad']) && ($minsIn === '' || (ctype_digit($minsIn) && (int)$minsIn <= 600));
        $mins = $minsOk && $minsIn !== '' ? (int)$minsIn : 0;
        $rl = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE)");
        $rl->execute(['portal-prac-' . (int)$client['id']]);
        $body = $what . ($mins ? "\n\n(" . $mins . ' minutes)' : '');
        // The same practice again within a few minutes (Log practice pressed twice) is
        // not logged twice.
        $dupPrac = db()->prepare("SELECT 1 FROM client_notes WHERE client_id = ? AND author_id IS NULL AND subject = 'Practice logged by client (portal)' AND body = ?
            AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE) LIMIT 1");
        $dupPrac->execute([(int)$client['id'], $body]);
        $pracErrors = [];
        if ($what === '') $pracErrors['practice'] = 'Tell us what you practiced.';
        elseif (mb_strlen($what) > 2000) $pracErrors['practice'] = 'That is too long (2,000 characters maximum).'; // refused, never cut
        if (!$minsOk) $pracErrors['minutes'] = 'Enter the minutes as a whole number from 0 to 600, or leave it blank.';
        if ($pracErrors) {
            keep_input();
            keep_field_errors($pracErrors);
        } elseif ($dupPrac->fetchColumn()) {
            flash_ui('Your practice was already logged, so it was not logged again.', 'info');
        } elseif ((int)$rl->fetchColumn() >= 12) {
            keep_input(); // what was typed is refilled, not lost
            flash_ui('Please wait a moment before logging more.', 'error');
        } elseif ($overDailyCap('portal-prac-' . (int)$client['id'], 50, 'practice logs')) {
            keep_input();
            flash_ui($capMsg, 'error');
        } else {
            db()->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?,?,1)")->execute([$_SERVER['REMOTE_ADDR'] ?? '', 'portal-prac-' . (int)$client['id']]);
            db()->prepare("INSERT INTO client_notes (client_id, note_type, subject, body, author_id) VALUES (?,?,?,?,NULL)")
                ->execute([(int)$client['id'], 'Case Note', 'Practice logged by client (portal)', $body]);
            flash_ui('Thanks — your practice was logged.');
        }
        redirect('portal.php?t=' . $token . '&lang=' . page_lang());
    }
}

// Resource download (token-scoped, Resource category only) — before any HTML output
if ($client && !empty($_GET['download'])) {
    $d = db()->prepare("SELECT * FROM client_documents WHERE id = ? AND client_id = ? AND category IN ('Resource','Client Upload')");
    $d->execute([(int)$_GET['download'], (int)$client['id']]);
    if ($doc = $d->fetch()) {
        $path = cfg('upload_dir') . '/' . basename($doc['path']);
        if (is_file($path)) {
            // Release the session lock before streaming, so a slow download never
            // holds up other portal pages opened in the same browser.
            session_write_close();
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . filesize($path));
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $doc['orig_name']) . '"');
            header('X-Content-Type-Options: nosniff');
            readfile($path);
            exit;
        }
    }
    // The file was removed (or is not this client's): back to the portal, said in the
    // page's language, rather than a bare English page with no way back.
    flash_ui('That file is no longer available. Your instructor may have removed it.', 'error');
    redirect('portal.php?t=' . $token . '&lang=' . page_lang());
}

// "Add to calendar" for one upcoming session (3.0.41): the client's own, still Scheduled.
// The same calendar ID as the invite emailed at booking, so it is never added twice.
if ($client && isset($_GET['ics'])) {
    $iq = db()->prepare("SELECT * FROM scheduled_sessions WHERE id = ? AND client_id = ? AND status = 'Scheduled'");
    $iq->execute([(int)$_GET['ics'], (int)$client['id']]);
    if ($row = $iq->fetch()) {
        [$icsText] = client_sessions_ics([$row], (string)($client['email'] ?? ''), 'PUBLISH', page_lang() === 'es');
        session_write_close();
        header('Content-Type: text/calendar; charset=UTF-8');
        header('Content-Disposition: attachment; filename="training-session.ics"');
        echo $icsText;
        exit;
    }
    flash_ui('That session is no longer on your schedule.', 'error');
    redirect('portal.php?t=' . $token . '&lang=' . page_lang());
}

// An AI explanation asked for on the previous request, shown once inside its homework.
if ($client && is_array($_SESSION['hw_explanation'] ?? null) && ($_SESSION['hw_explanation']['token'] ?? '') === $token) {
    $homeworkExplanation = $_SESSION['hw_explanation'];
}
unset($_SESSION['hw_explanation']);
// "Email me a new link" from the dead-link page (3.0.40): sent only to the confirmed address
// on file, and the answer is the same whether or not the address is known.
if (!$client && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['new_link'])) {
    csrf_check();
    portal_link_self_service(is_string($_POST['new_link_email'] ?? null) ? $_POST['new_link_email'] : '');
    flash_ui('If that address belongs to a client with a portal, a new link is on its way. Check your email in a few minutes.', 'info');
    redirect('portal.php?lang=' . page_lang());
}
if ($client) record_client_view(null, (int)$client['id'], 'client portal');
page_header($client ? 'My training' : 'Portal');

echo lang_toggle();
if (!$client) {
    // Never a dead end: say who to write to for a new link (the footer's contact).
    $linkContact = (string)(cfg('support_email') ?: cfg('mail_from'));
    $phoneContact = support_contact_html(); // "Call {phone} or email {email}" once a support phone is set
    echo '<div class="card auth-card"><h1>' . t('Link not recognized') . '</h1><p>'
        . ($phoneContact !== ''
            ? t('This link has been replaced or no longer works.') . ' ' . $phoneContact . ' ' . t('and we will send you a new one.')
            : ($linkContact !== ''
            ? t('This link has been replaced or no longer works. Email') . ' <a href="mailto:' . h($linkContact) . '">' . h($linkContact) . '</a> ' . t('and we will send you a new one.')
            : t('This link has been replaced or no longer works. Ask your instructor and we will send you a new one.')))
        . '</p>'
        . '<h2>' . t('Email me a new link') . '</h2>'
        . '<form method="post" action="portal.php?lang=' . h(page_lang()) . '" class="stacked" novalidate>' . csrf_field()
        . '<div class="field"><label for="new_link_email">' . t('Your email address') . '</label>'
        . '<input type="email" id="new_link_email" name="new_link_email" autocomplete="email" maxlength="190"></div>'
        . '<button type="submit" name="new_link" value="1" class="btn btn-primary">' . t('Send me a new link') . '</button></form>'
        . '</div>';
    page_footer();
    exit;
}

$sessions = db()->prepare("SELECT s.*, u.name AS staff_name FROM scheduled_sessions s LEFT JOIN users u ON u.id = s.user_id
    WHERE s.client_id = ? AND s.status = 'Scheduled' AND s.end_at >= NOW() ORDER BY s.start_at LIMIT 20");
$sessions->execute([(int)$client['id']]);
$sessions = $sessions->fetchAll();

$tracks = db()->prepare("SELECT * FROM training_tracks WHERE client_id = ? AND status = 'Active'");
$tracks->execute([(int)$client['id']]);
$tracks = $tracks->fetchAll();

$resources = db()->prepare("SELECT * FROM client_documents WHERE client_id = ? AND category = 'Resource' ORDER BY created_at DESC");
$resources->execute([(int)$client['id']]);
$resources = $resources->fetchAll();

// Public knowledge-base articles, shown to every client on their portal.
$kb = [];
try {
    $kb = db()->query("SELECT title, category, body FROM kb_articles WHERE is_public = 1 ORDER BY category IS NULL, category, title LIMIT 100")->fetchAll();
} catch (Throwable $e) { $kb = []; }

// The client's own self-service requests and their status.
$myRequests = [];
try {
    $mr = db()->prepare('SELECT * FROM portal_requests WHERE client_id = ? ORDER BY created_at DESC LIMIT 20');
    $mr->execute([(int)$client['id']]);
    $myRequests = $mr->fetchAll();
} catch (Throwable $e) { $myRequests = []; }
// Source strings only: they are translated with t() where they are printed.
// (t() returns markup, so its result must never be stored here and escaped again.)
$reqStatusLabel = ['New' => 'Received', 'In Progress' => 'In progress', 'Done' => 'Done', 'Declined' => 'Not possible'];

// Documents the client has uploaded themselves.
$myUploads = db()->prepare("SELECT id, title, created_at FROM client_documents WHERE client_id = ? AND category = 'Client Upload' ORDER BY created_at DESC LIMIT 20");
$myUploads->execute([(int)$client['id']]);
$myUploads = $myUploads->fetchAll();
?>
<h1><?= t('Welcome,') ?> <?= h(explode(' ', $client['name'])[0]) ?></h1>
<p class="muted"><?= t('Your training with') ?> <?= h(brand('org')) ?>.</p>
<?php if ($caseClosed): // Said first: the forms that send things are not offered. ?>
<p class="alert alert-info"><?= t($closedMsg) ?></p>
<?php endif; ?>
<?php /* Download links in lists get a 44px target (2.5.5), also where a date follows the link. */ ?>
<style>.dl-list a{display:inline-flex;align-items:center;min-height:2.75rem;min-width:2.75rem}</style>
<script>
// A number the browser cannot read is sent blank; mark it so the server says so
// on the field instead of treating it as left empty.
document.addEventListener('submit', function (e) {
  var f = e.target;
  f.querySelectorAll('input[data-bad-marker]').forEach(function (m) { m.remove(); });
  f.querySelectorAll('input[type=number],input[type=date]').forEach(function (i) {
    if (i.name && i.validity && i.validity.badInput) {
      var m = document.createElement('input');
      m.type = 'hidden'; m.name = i.name + '_bad'; m.value = '1'; m.setAttribute('data-bad-marker', '');
      f.appendChild(m);
    }
  });
}, true);
</script>

<?php if ($tracks): // One short line per active training plan; nothing when the client has none. ?>
<section class="card" aria-labelledby="prog-h">
  <h2 id="prog-h"><?= t('Your progress') ?></h2>
  <ul>
  <?php foreach ($tracks as $t): ?>
    <li><?= portal_progress_line($t, $sessions) ?></li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="sess-h">
  <h2 id="sess-h"><?= t('Upcoming sessions') ?></h2>
  <?php if (!$sessions): ?><p class="muted"><?= t('No sessions scheduled right now. Your instructor will be in touch.') ?></p>
  <?php else: ?>
  <ul>
  <?php foreach ($sessions as $s):
      // Every session offers the same actions, so each control also names its
      // session (hidden text) — otherwise a list of them is indistinguishable.
      $when = portal_date('l, F j \a\t g:i A T', strtotime($s['start_at']));
      $forSession = '<span class="visually-hidden"> — ' . en($when) . '</span>';
      // Each request is confirmed first, naming the session; the question says what
      // OK and Cancel do, since "Cancel" would otherwise read as cancelling the session.
      $jsq = fn(string $q): string => h(json_encode($q, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP));
      // A native dialog cannot mark an English date lang="en", so in Spanish it gets a Spanish date.
      $askWhen = page_lang() === 'es' ? portal_when_es(strtotime($s['start_at'])) : $when;
      $askNew = __('Send your instructor a request for a new time for this session?') . "\n" . $askWhen . "\n" . __('Choose OK to send the request, or Cancel to send nothing.');
      $askCancel = __('Send your instructor a request to cancel this session?') . "\n" . $askWhen . "\n" . __('Choose OK to send the request. Choose Cancel to keep the session and send nothing.'); ?>
    <li>
      <strong><?= en($when) ?></strong> <?= h(__('to')) ?> <?= en(portal_date('g:i A T', strtotime($s['end_at']))) ?>
      <?= !empty($s['is_remote']) ? ' · <span class="badge badge-active">' . h(__('Remote')) . '</span>' : ($s['location'] ? ' · ' . en($s['location']) : '') ?>
      <?= $s['staff_name'] ? ' · ' . t('with') . ' ' . h($s['staff_name']) : '' ?>
      <?php if (!empty($s['is_remote']) && !empty($s['meeting_url'])): ?>
        <div style="margin-top:.3rem"><a class="btn btn-primary btn-sm" href="<?= h($s['meeting_url']) ?>" target="_blank" rel="noopener"><?= t('Join online') ?><?= $forSession ?><span class="visually-hidden"> <?= h(__('(opens in a new tab)')) ?></span></a></div>
      <?php endif; ?>
      <div style="margin-top:.3rem"><a href="portal.php?t=<?= h($token) ?>&amp;ics=<?= (int)$s['id'] ?>&amp;lang=<?= h(page_lang()) ?>"><?= t('Add to calendar') ?><?= $forSession ?></a></div>
      <?php if (!$caseClosed): ?>
      <div style="margin-top:.3rem">
        <form method="post" action="portal.php?t=<?= h($token) ?>&amp;lang=<?= h(page_lang()) ?>" class="inline-form" onsubmit="return confirm(<?= $jsq($askNew) ?>);"><?= csrf_field() ?>
          <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="req_type" value="reschedule">
          <button type="submit" name="new_request" value="1" class="btn-link"><?= t('Request a new time') ?><?= $forSession ?></button>
        </form>
        ·
        <form method="post" action="portal.php?t=<?= h($token) ?>&amp;lang=<?= h(page_lang()) ?>" class="inline-form" onsubmit="return confirm(<?= $jsq($askCancel) ?>);"><?= csrf_field() ?>
          <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="req_type" value="cancel">
          <button type="submit" name="new_request" value="1" class="btn-link"><?= t('Ask to cancel') ?><?= $forSession ?></button>
        </form>
      </div>
      <?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<?php // Past sessions, newest first (3.0.41): what happened at each.
$pastQ = db()->prepare("SELECT start_at, status FROM scheduled_sessions WHERE client_id = ? AND start_at < NOW() AND status IN ('Completed','No Show','Cancelled')
    ORDER BY start_at DESC LIMIT 30");
$pastQ->execute([(int)$client['id']]);
$past = $pastQ->fetchAll();
$pastWord = ['Completed' => 'Attended', 'No Show' => 'Missed', 'Cancelled' => 'Cancelled']; ?>
<?php if ($past): ?>
<section class="card" aria-labelledby="past-h">
  <h2 id="past-h"><?= t('Past sessions') ?></h2>
  <details><summary><?= t('Show your last sessions') ?> (<?= count($past) ?>)</summary>
  <ul>
  <?php foreach ($past as $ps): $pts = strtotime($ps['start_at']); ?>
    <li><?= page_lang() === 'es' ? h(portal_when_es($pts)) : en(portal_date('l, F j, Y \a\t g:i A' . (portal_tz() ? ' T' : ''), $pts)) ?>: <?= t($pastWord[$ps['status']]) ?></li>
  <?php endforeach; ?>
  </ul>
  </details>
</section>
<?php endif; ?>

<?php foreach ($tracks as $t):
    $goals = db()->prepare('SELECT * FROM smart_goals WHERE track_id = ? ORDER BY status = "Achieved" DESC, target_date IS NULL, target_date');
    $goals->execute([(int)$t['id']]);
    $goals = $goals->fetchAll();
    $achieved = count(array_filter($goals, fn($g) => $g['status'] === 'Achieved'));
?>
<section class="card" aria-labelledby="tr-<?= (int)$t['id'] ?>">
  <?php // Staff write tracks, goals, homework, guides and resources in the English-only
  // staff app, so that text is marked lang="en" on the Spanish page (en()). ?>
  <h2 id="tr-<?= (int)$t['id'] ?>"><?= t('Your training plan:') ?> <?= en((string)$t['title']) ?></h2>
  <?php if ($goals): ?>
  <p><?= $achieved ?> <?= h(__('of')) ?> <?= count($goals) ?> <?= t('goals achieved') ?></p>
  <ul>
  <?php foreach ($goals as $g): $gpct = (int)($g['progress_pct'] ?? 0); ?>
    <li><?= status_badge($g['status']) ?> <?= en((string)$g['specific_goal']) ?>
      <?= $g['target_date'] ? '<span class="muted"> · ' . t('target') . ' ' . en(fmt_date($g['target_date'])) . '</span>' : '' ?>
      <div class="progress-wrap">
        <label class="visually-hidden" for="pgoal-<?= (int)$g['id'] ?>"><?= t('Progress') ?>: <?= en((string)$g['specific_goal']) ?></label>
        <progress id="pgoal-<?= (int)$g['id'] ?>" class="hours" max="100" value="<?= $gpct ?>"><?= $gpct ?>%</progress>
        <span class="muted"><?= $gpct ?>%</span>
      </div>
    </li>
  <?php endforeach; ?>
  </ul>
  <?php else: ?><p class="muted"><?= t('Goals will appear here as your plan takes shape.') ?></p><?php endif; ?>
</section>
<?php endforeach; ?>

<?php
$hwStmt = db()->prepare("SELECT h.*, t.title AS track_title FROM homework h JOIN training_tracks t ON t.id = h.track_id
    WHERE t.client_id = ? AND h.status = 'Assigned' ORDER BY h.due_date IS NULL, h.due_date");
$hwStmt->execute([(int)$client['id']]);
$homework = $hwStmt->fetchAll();
?>
<?php if ($homework || !$caseClosed): ?>
<section class="card" aria-labelledby="hw-h">
  <h2 id="hw-h"><?= t('Practice & homework') ?></h2>
  <?php if ($homework): ?>
  <ul>
  <?php foreach ($homework as $h_): $hwOwnError = $hwError && $hwError['id'] === (int)$h_['id']; ?>
    <?php // The id brings the client back to this homework (with the message focused) after asking for an explanation. ?>
    <li id="hw-<?= (int)$h_['id'] ?>">
      <strong><?= en((string)$h_['title']) ?></strong><?= $h_['due_date'] ? ' — ' . t('due') . ' ' . en(fmt_date($h_['due_date'])) : '' ?>
      <?php // Line breaks kept: instructions are often numbered steps, one to a line. ?>
      <?= $h_['instructions'] ? '<br>' . nl2br(en((string)$h_['instructions'])) : '' ?>
      <?php // A refused practice update or question is said here, in the homework it is about,
      // linked to its field; the form below comes back filled in and opened. ?>
      <?php if ($hwOwnError && !empty($hwError['system'])): // Not the client's mistake: no "Please fix the following". ?>
      <div class="alert alert-info" tabindex="-1" id="form-error-summary"><p><?= t($hwError['msg']) ?></p></div>
      <script>document.addEventListener("DOMContentLoaded",function(){var s=document.getElementById("form-error-summary");if(s)s.focus();});</script>
      <?php elseif ($hwOwnError): ?><?= form_error_summary(['hw_error' => ''], ['hw_error' => $hwError['field']]) ?><?php endif; ?>
      <?php if ($h_['client_reported_at']): ?>
        <br><span class="badge badge-active"><?= t('You marked this done') ?></span>
      <?php elseif (!$caseClosed): ?>
      <?php // Confirmed first, naming the homework: telling the instructor cannot be taken back here. ?>
      <form method="post" action="portal.php?t=<?= h($token) ?>&amp;lang=<?= h(page_lang()) ?>" class="inline-form" style="margin-top:.3rem"
        onsubmit="return confirm(<?= h(json_encode(__('Tell your instructor you finished this homework?') . "\n" . $h_['title'] . "\n" . __('Choose OK to tell your instructor, or Cancel to send nothing.'), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>);">
        <?= csrf_field() ?>
        <input type="hidden" name="homework_id" value="<?= (int)$h_['id'] ?>">
        <button type="submit" name="hw_done" value="1" class="btn btn-secondary btn-sm"><?= t('I\'ve done this') ?><span class="visually-hidden"> — <?= en((string)$h_['title']) ?></span></button>
      </form>
      <?php endif; ?>
      <?php if($h_['status']==='Assigned' && workflows_ready() && !$caseClosed) require __DIR__.'/inc/homework_support_ui.php'; ?>
      <?php if ($hwOwnError): ?>
      <script>document.addEventListener('DOMContentLoaded', function () { var f = document.getElementById(<?= json_encode($hwError['field'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>), d = f && f.closest('details'); if (d) d.open = true; });</script>
      <?php endif; ?>
      <?php // The AI explanation asked for this homework, under its own heading, naming the
      // homework and the question; it is in the language it was asked in. ?>
      <?php if ($homeworkExplanation && (int)$homeworkExplanation['homework_id'] === (int)$h_['id']): ?>
      <section class="card" aria-labelledby="hw-ai-<?= (int)$h_['id'] ?>">
        <h3 id="hw-ai-<?= (int)$h_['id'] ?>"><?= t('AI explanation — check with your instructor') ?>: <?= en((string)$h_['title']) ?></h3>
        <?php if ((string)$homeworkExplanation['question'] !== ''): ?><p><strong><?= t('Your question:') ?></strong> <?= nl2br(h((string)$homeworkExplanation['question'])) ?></p><?php endif; ?>
        <?php if (!empty($homeworkExplanation['truncated'])): ?><p><strong><?= t('This explanation was cut off. Ask your instructor before using it.') ?></strong></p><?php endif; ?>
        <?= plain_text_html((string)$homeworkExplanation['text'], 4) ?>
      </section>
      <?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if (!$caseClosed): ?>
  <?php // Practice on the client's own is logged here, under the homework it goes with. ?>
  <h3 id="prac-h"><?= t('Log practice') ?></h3>
  <p class="muted"><?= t('Practiced on your own? Log it here so your instructor can see your progress between sessions.') ?></p>
  <?php $oldPrac = old_in('log_practice') !== ''; // refill only the form that was sent ?>
  <form method="post" action="portal.php?t=<?= h($token) ?>&amp;lang=<?= h(page_lang()) ?>" class="stacked" novalidate>
    <?= csrf_field() ?>
    <?= form_error_summary(['practice' => 'What did you practice?', 'minutes' => 'How long (minutes)']) ?>
    <div class="grid grid-2">
      <div class="field"><label for="practice"><?= t('What did you practice?') ?> <span class="hint"><?= t('(required)') ?></span></label><p class="hint" id="prac-hint"><?= t('Up to 2,000 characters.') ?></p><textarea id="practice" name="practice" rows="2" required <?= field_aria('practice', 'prac-hint') ?>><?= h($oldPrac ? old_in('practice') : '') ?></textarea><?= field_error('practice') ?></div>
      <div class="field"><label for="minutes"><?= t('How long (minutes)') ?> <span class="hint"><?= t('(optional)') ?></span></label>
        <p class="hint" id="min-hint"><?= t('Whole minutes, up to 600.') ?></p>
        <input type="number" id="minutes" name="minutes" min="0" max="600" step="1" inputmode="numeric" value="<?= h($oldPrac ? old_in('minutes') : '') ?>" <?= field_aria('minutes', 'min-hint') ?>><?= field_error('minutes') ?></div>
    </div>
    <button type="submit" name="log_practice" value="1" class="btn btn-secondary"><?= t('Log practice') ?></button>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php // Practice history with the instructor's feedback, completed homework, and finished
// training plans with a completion summary (3.0.51).
$pracHist = portal_practice_history((int)$client['id']);
$doneHw = portal_completed_homework((int)$client['id']);
$finished = portal_finished_tracks((int)$client['id']);
if ($pracHist || $doneHw): ?>
<section class="card" aria-labelledby="hist-h">
  <h2 id="hist-h"><?= t('Your practice and finished homework') ?></h2>
  <?php if ($pracHist): ?>
  <details><summary><?= t('Your practice updates and your instructor\'s feedback') ?> (<?= count($pracHist) ?>)</summary>
  <ul>
  <?php foreach ($pracHist as $ph): ?>
    <li><?= en(fmt_date(substr((string)$ph['created_at'], 0, 10))) ?>, <?= en((string)$ph['title']) ?>: <?= (int)$ph['minutes'] ?> <?= t('minutes') ?>.<?php if ($ph['staff_feedback']): ?> <strong><?= t('Instructor feedback') ?>:</strong> <?= en((string)$ph['staff_feedback']) ?><?php endif; ?></li>
  <?php endforeach; ?>
  </ul>
  </details>
  <?php endif; ?>
  <?php if ($doneHw): ?>
  <details><summary><?= t('Finished homework') ?> (<?= count($doneHw) ?>)</summary>
  <ul><?php foreach ($doneHw as $dh): ?><li><?= en((string)$dh['title']) ?> (<?= en((string)$dh['track_title']) ?>)</li><?php endforeach; ?></ul>
  </details>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php if ($finished): ?>
<section class="card" aria-labelledby="fin-h">
  <h2 id="fin-h"><?= t('Finished training') ?></h2>
  <?php foreach ($finished as $f): $ft = $f['track']; $met = array_filter($f['goals'], fn($g) => $g['status'] === 'Achieved'); ?>
  <h3><?= en((string)$ft['title']) ?></h3>
  <p><?= t('Completed') ?><?= !empty($ft['completed_at']) ? ' ' . en(fmt_date(substr((string)$ft['completed_at'], 0, 10))) : '' ?>. <?= portal_hours_num($f['hours']) ?> <?= t('hours of training.') ?> <?= count($met) ?> <?= h(__('of')) ?> <?= count($f['goals']) ?> <?= t('goals achieved') ?>.</p>
  <?php if ($met): ?><ul><?php foreach ($met as $g): ?><li><?= en((string)$g['specific_goal']) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php // A closed case shows only what was sent before (each section's history, if any). ?>
<?php // My devices (3.0.42): loans, repairs, and check-ins the client can answer.
$devLoans = portal_device_loans((int)$client['id']);
$devRepairs = portal_device_repairs((int)$client['id']);
$devChecks = $caseClosed ? [] : portal_device_checkins((int)$client['id']);
if ($devLoans || $devRepairs || $devChecks): ?>
<section class="card" aria-labelledby="dev-h">
  <h2 id="dev-h"><?= t('My devices') ?></h2>
  <?php if ($devLoans): ?>
  <h3><?= t('Loaned to you') ?></h3>
  <ul>
  <?php foreach ($devLoans as $l): $over = $l['due_date'] && $l['due_date'] < date('Y-m-d'); ?>
    <li><?= en((string)$l['name']) ?>: <?= t('loaned') ?> <?= en(fmt_date($l['loaned_at'])) ?><?php if ($l['due_date']): ?>, <?= $over ? '<strong>' . t('was due back') . ' ' . en(fmt_date($l['due_date'])) . '</strong>' : t('due back') . ' ' . en(fmt_date($l['due_date'])) ?><?php endif; ?>.</li>
  <?php endforeach; ?>
  </ul>
  <p><?= t('To return an item, give it to your instructor, or send us a request below if you need it longer.') ?></p>
  <?php endif; ?>
  <?php if ($devRepairs): ?>
  <h3><?= t('Repairs') ?></h3>
  <ul><?php foreach ($devRepairs as $rp): ?><li><?= en((string)$rp['title']) ?>: <?= en((string)$rp['status']) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <?php foreach ($devChecks as $fc): $fid = (int)$fc['id']; ?>
  <h3 id="chk-<?= $fid ?>"><?= t('How is it going?') ?> <?= en((string)$fc['name']) ?></h3>
  <form method="post" action="portal.php?t=<?= h($token) ?>&amp;lang=<?= h(page_lang()) ?>" class="stacked"><?= csrf_field() ?>
    <input type="hidden" name="followup_id" value="<?= $fid ?>">
    <fieldset><legend><?= t('Are you still using it?') ?></legend>
      <label><input type="radio" name="in_use" value="yes"> <?= t('Yes') ?></label>
      <label><input type="radio" name="in_use" value="no"> <?= t('No') ?></label>
    </fieldset>
    <div class="field"><label for="sat-<?= $fid ?>"><?= t('How happy are you with it?') ?></label>
      <select id="sat-<?= $fid ?>" name="satisfaction"><option value=""><?= t('Choose') ?></option>
        <?php foreach ([5 => 'Very happy', 4 => 'Happy', 3 => 'Okay', 2 => 'Unhappy', 1 => 'Very unhappy'] as $sv => $sl): ?><option value="<?= $sv ?>"><?= t($sl) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="bar-<?= $fid ?>"><?= t('Is anything getting in the way?') ?></label>
      <input type="text" id="bar-<?= $fid ?>" name="barriers" maxlength="255"></div>
    <button type="submit" name="device_checkin" value="1" class="btn btn-primary"><?= t('Send my answers') ?><span class="visually-hidden"> — <?= en((string)$fc['name']) ?></span></button>
  </form>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if (!$caseClosed || $myRequests): ?>
<section class="card" aria-labelledby="req-h">
  <h2 id="req-h"><?= t('Requests & help') ?></h2>
  <?php $oldReq = old_in('new_request') !== '' ? old_in('req_type') : ''; ?>
  <?php if (!$caseClosed): ?>
  <p class="muted"><?= t('Ask for an appointment, get help with a device, or send another request. Your instructor will follow up.') ?></p>
  <?php // One form for every request; the topic chosen is stored as the request's type
  // (appointment, device help or other), as the two earlier forms stored it. ?>
  <form method="post" action="portal.php?t=<?= h($token) ?>&amp;lang=<?= h(page_lang()) ?>" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['req_details' => 'Tell us what you need'], ['req_details' => 'rdetails']) ?>
    <h3><?= t('Ask us something') ?></h3>
    <fieldset class="field">
      <legend><?= t('What is it about?') ?></legend>
      <?php foreach (['appointment' => 'An appointment', 'device' => 'Help with a device', 'records' => 'A copy of my records', 'correction' => 'A correction to my records', 'other' => 'Something else'] as $rtVal => $rtLabel): ?>
      <div class="field"><label><input type="radio" name="req_type" value="<?= $rtVal ?>" <?= ($oldReq !== '' ? $oldReq : 'appointment') === $rtVal ? 'checked' : '' ?>> <?= t($rtLabel) ?></label></div>
      <?php endforeach; ?>
    </fieldset>
    <div class="field"><label for="rdetails"><?= t('Tell us what you need') ?> <span class="hint"><?= t('(required)') ?></span></label>
      <p class="hint" id="rdetails-hint"><?= t('Preferred days and times, what you need, or your question.') ?> <?= t('Up to 2,000 characters.') ?></p>
      <textarea id="rdetails" name="details" rows="3" required <?= field_aria('req_details', 'rdetails-hint') ?>><?= h($oldReq !== '' ? old_in('details') : '') ?></textarea><?= field_error('req_details') ?></div>
    <button type="submit" name="new_request" value="1" class="btn btn-primary"><?= t('Send request') ?></button>
  </form>
  <?php endif; ?>
  <?php if ($myRequests): ?>
  <h3><?= t('Your requests') ?></h3>
  <ul>
  <?php $typeLabels = ['appointment' => 'Appointment', 'reschedule' => 'Reschedule', 'cancel' => 'Cancellation', 'device' => 'Device help', 'other' => 'Request'] + RECORDS_REQUEST_TYPES; ?>
  <?php foreach ($myRequests as $rq): ?>
    <li><strong><?= t($typeLabels[$rq['type']] ?? 'Request') ?></strong>
      · <span class="badge <?= $rq['status'] === 'Done' ? 'badge-closed' : ($rq['status'] === 'Declined' ? 'badge-hold' : 'badge-pending') ?>"><?= isset($reqStatusLabel[$rq['status']]) ? t($reqStatusLabel[$rq['status']]) : en((string)$rq['status']) ?></span>
      <span class="muted">· <?= en(fmt_date($rq['created_at'])) ?></span><?php if (isset(RECORDS_REQUEST_TYPES[$rq['type']]) && !in_array($rq['status'], ['Done', 'Declined'], true)): ?> <?= t('We answer within 30 days.') ?><?php endif; ?>
      <?php if (!empty($rq['outcome'])): ?><br><?= en((string)$rq['outcome']) ?><?php endif; ?>
      <?php // The whole request, with its line breaks (a list of times stays a list). ?>
      <?= $rq['details'] ? '<br>' . nl2br(h((string)$rq['details'])) : '' ?>
      <?php $offers = !$caseClosed && in_array($rq['type'], OFFER_REQUEST_TYPES, true) && !in_array($rq['status'], ['Done', 'Declined'], true) ? request_offers((int)$rq['id']) : [];
      if ($offers): ?>
      <form method="post" action="portal.php?t=<?= h($token) ?>&amp;lang=<?= h(page_lang()) ?>" class="stacked"><?= csrf_field() ?>
        <fieldset><legend><?= t('Choose a time') ?></legend>
        <?php foreach ($offers as $i => $of): $ots = strtotime($of['start_at']); ?>
          <label><input type="radio" name="offer_id" value="<?= (int)$of['id'] ?>"<?= $i === 0 ? ' checked' : '' ?>> <?= page_lang() === 'es' ? h(portal_when_es($ots)) : en(portal_date('l, F j \a\t g:i A' . (portal_tz() ? ' T' : ''), $ots)) ?>,
            <?= (int)round((strtotime($of['end_at']) - $ots) / 60) ?> <?= t('minutes') ?><?= (int)$of['is_remote'] ? ', ' . h(__('Remote')) : ($of['location'] ? ', ' . en((string)$of['location']) : '') ?><?= $of['staff_name'] ? ', ' . t('with') . ' ' . h((string)$of['staff_name']) : '' ?></label>
        <?php endforeach; ?>
        </fieldset>
        <button type="submit" name="confirm_offer" value="1" class="btn btn-primary btn-sm"><?= t('Confirm this time') ?><span class="visually-hidden"> — <?= t($typeLabels[$rq['type']] ?? 'Request') ?>, <?= en(fmt_date($rq['created_at'])) ?></span></button>
      </form>
      <?php endif; ?></li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if (!$caseClosed || $myUploads): ?>
<section class="card" aria-labelledby="up-h">
  <h2 id="up-h"><?= t('Share a document') ?></h2>
  <?php if (!$caseClosed): ?>
  <p class="muted" id="up-hint"><?= t('Upload a document for your instructor — a form, an ID, or anything they asked for. PDF, Office, text or image files up to 15 megabytes (MB).') ?></p>
  <form method="post" action="portal.php?t=<?= h($token) ?>&amp;lang=<?= h(page_lang()) ?>" class="stacked" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <?= form_error_summary(['doc_title' => 'What is this?', 'document' => 'Choose a file']) ?>
    <div class="field"><label for="doc_title"><?= t('What is this?') ?> <span class="hint"><?= t('(optional)') ?></span></label><input type="text" id="doc_title" name="doc_title" maxlength="190" value="<?= h(old_in('upload_doc') !== '' ? old_in('doc_title') : '') ?>" <?= field_aria('doc_title') ?>><?= field_error('doc_title') ?></div>
    <div class="field"><label for="document"><?= t('Choose a file') ?> <span class="hint"><?= t('(required)') ?></span></label><input type="file" id="document" name="document" accept=".pdf,.docx,.xlsx,.txt,.rtf,.png,.jpg,.jpeg" required <?= field_aria('document', 'up-hint') ?>><?= field_error('document') ?></div>
    <button type="submit" name="upload_doc" value="1" class="btn btn-primary"><?= t('Upload') ?></button>
  </form>
  <?php endif; ?>
  <?php if ($myUploads): ?>
  <h3><?= t('Documents you shared') ?></h3>
  <ul class="dl-list">
  <?php foreach ($myUploads as $u): ?>
    <li><a href="portal.php?t=<?= h($token) ?>&amp;download=<?= (int)$u['id'] ?>"><?= h($u['title']) ?><span class="visually-hidden"> <?= t('(downloads a file)') ?></span></a> <span class="muted">· <?= en(fmt_date($u['created_at'])) ?></span></li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php
$thread = db()->prepare("SELECT sender, body, created_at FROM messages WHERE client_id = ? AND channel = 'client' ORDER BY created_at DESC LIMIT 20");
$thread->execute([(int)$client['id']]);
$thread = array_reverse($thread->fetchAll());
if (!$caseClosed || $thread): ?>
<section class="card" aria-labelledby="msg-h">
  <h2 id="msg-h"><?= t('Message your instructor') ?></h2>
  <?php if (!$caseClosed): ?><p class="muted"><?= t('Have a question or an update? Send a note and your instructor will get back to you.') ?></p><?php endif; ?>
  <?php if ($thread): ?>
  <?php /* A list, with the sender named before each message for screen readers
           (sighted readers get it from the side and colour, and the line below).
           role="list" keeps the list semantics that list-style:none removes in Safari. */ ?>
  <ul class="msg-thread" role="list">
    <?php foreach ($thread as $m): $who = $m['sender'] === 'client' ? __('You') : __('Instructor'); ?>
      <li class="msg <?= $m['sender'] === 'client' ? 'out' : 'in' ?>">
        <span class="visually-hidden"><?= h($who) ?>:</span>
        <div class="msg-body"><?= nl2br(h($m['body'])) ?></div>
        <div class="msg-meta"><span aria-hidden="true"><?= h($who) ?> · </span><?= en(portal_date('M j, g:i A', strtotime($m['created_at']))) ?></div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php /* Sent messages use a fixed navy, not the configurable brand colour: white
           text 10.4:1 and the time line 8.8:1 (AAA). Text lines stay near 70 characters. */ ?>
  <style>.msg-thread{display:flex;flex-direction:column;gap:.5rem;margin:0 0 .8rem;padding:0;list-style:none}.msg{max-width:85%;padding:.5rem .7rem;border-radius:var(--radius)}.msg-body{max-width:70ch}.msg.in{align-self:flex-start;background:var(--bg);border:1px solid var(--line)}.msg.out{align-self:flex-end;background:#1e3a8a;color:#fff}.msg-meta{font-size:.875rem;color:var(--ink-soft);margin-top:.25rem}.msg.out .msg-meta{color:#e5ecff}</style>
  <?php endif; ?>
  <?php if (!$caseClosed): ?>
  <form method="post" action="portal.php?t=<?= h($token) ?>&amp;lang=<?= h(page_lang()) ?>" class="stacked" novalidate>
    <?= csrf_field() ?>
    <?= form_error_summary(['message' => 'Your message']) ?>
    <div class="field">
      <label for="message"><?= t('Your message') ?> <span class="hint"><?= t('(required)') ?></span></label>
      <p class="hint" id="message-hint"><?= t('Up to 5,000 characters.') ?></p>
      <textarea id="message" name="message" rows="4" required <?= field_aria('message', 'message-hint') ?>><?= h(old_in('send_message') !== '' ? old_in('message') : '') ?></textarea>
      <?= field_error('message') ?>
    </div>
    <button type="submit" name="send_message" value="1" class="btn btn-primary"><?= t('Send message') ?></button>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if (!$caseClosed): ?>
<section class="card" aria-labelledby="info-h">
  <h2 id="info-h"><?= t('My information') ?></h2>
  <p class="muted"><?= t('Keep your contact details and access needs up to date. Your instructor is notified of changes.') ?></p>
  <?php // After a rejected save, show what was typed rather than the stored values.
  // After a save refused because staff changed the details meanwhile (p_kept), only
  // the fields the client had changed are refilled; the rest show what is on file.
  $oldInfo = old_in('update_profile') !== '';
  $keptInfo = old_in('p_kept');
  $refill = fn(string $k): bool => $oldInfo && ($keptInfo === '' || str_contains($keptInfo, ',' . $k . ','));
  $pv = fn(string $k, $saved): string => $refill($k) ? old_in($k) : (string)($saved ?? '');
  // Closed until the client wants it; open again when a save came back with a problem.
  $infoOpen = $oldInfo || array_filter(['p_phone', 'p_address', 'p_tech', 'p_notes'], fn($k) => has_field_error($k)); ?>
  <?php // A sub-heading of "My information" (the section's h2), so the outline nests. ?>
  <h3 class="visually-hidden"><?= t('Update my details') ?></h3>
  <details id="info-details"<?= $infoOpen ? ' open' : '' ?>>
  <summary><?= t('Update my details') ?></summary>
  <form method="post" action="portal.php?t=<?= h($token) ?>&amp;lang=<?= h(page_lang()) ?>" class="stacked" novalidate>
    <?= csrf_field() ?>
    <?php // A fingerprint of each value shown, so a save from a page drawn before
    // staff changed the details is refused instead of writing old values back. A form
    // refilled from an earlier page (after a lapsed session) carries that page's
    // fingerprints with its values. The field is not type="hidden" on purpose: the
    // language switch restores every other field it keeps, so it restores this one
    // with them (it is hidden from view and from assistive technology all the same).
    $shownNow = json_encode(array_map(fn($v) => hash('sha256', $v), portal_profile_values($client)));
    $shownJson = ($oldInfo && $keptInfo === '' && old_in('p_shown_json') !== '') ? old_in('p_shown_json') : $shownNow; ?>
    <input type="text" name="p_shown_json" value="<?= h($shownJson) ?>" hidden aria-hidden="true" tabindex="-1" autocomplete="off">
    <?= form_error_summary(['p_phone' => 'Phone', 'p_address' => 'Address', 'p_tech' => 'Assistive technology you use', 'p_notes' => 'Anything else we should know']) ?>
    <div class="grid grid-2">
      <div class="field"><label for="p_phone"><?= t('Phone') ?></label><input type="tel" id="p_phone" name="p_phone" maxlength="40" autocomplete="tel" value="<?= h($pv('p_phone', $client['phone'] ?? '')) ?>" <?= field_aria('p_phone') ?>><?= field_error('p_phone') ?></div>
      <?php // The email address is shown, not edited: the next portal link is sent to it. ?>
      <div class="field"><p><strong><?= t('Email on file:') ?></strong> <?= trim((string)($client['email'] ?? '')) !== '' ? en((string)$client['email']) : t('No email on file.') ?>
        <?= t('Ask your instructor to change it.') ?></p></div>
    </div>
    <div class="field"><label for="p_address"><?= t('Address') ?></label><input type="text" id="p_address" name="p_address" maxlength="255" autocomplete="street-address" value="<?= h($pv('p_address', $client['address'] ?? '')) ?>" <?= field_aria('p_address') ?>><?= field_error('p_address') ?></div>
    <fieldset>
      <legend><?= t('Access needs') ?></legend>
      <div class="field"><label><input type="checkbox" name="p_sr" value="1" <?= ($refill('p_sr') ? old_in('p_sr') !== '' : !empty($client['a11y_screen_reader'])) ? 'checked' : '' ?>> <?= t('I use a screen reader') ?></label></div>
      <?php // select_options() translates each label, or marks it lang="en" when it has no translation. ?>
      <?php // A value staff saved that is not in the list is offered too, so saving this form never clears it.
      $fmtOpts = ['Standard print', 'Large print', 'Plain text', 'Braille-ready (BRF)', 'Audio', 'Accessible PDF'];
      $curFmt = (string)($client['a11y_format'] ?? '');
      if ($curFmt !== '' && !in_array($curFmt, $fmtOpts, true)) $fmtOpts[] = $curFmt; ?>
      <div class="field"><label for="p_format"><?= t('Preferred document format') ?></label>
        <select id="p_format" name="p_format">
          <option value=""><?= h(__('No preference')) ?></option>
          <?= select_options($fmtOpts, $pv('p_format', $client['a11y_format'] ?? '')) ?>
        </select></div>
      <div class="field"><label for="p_tech"><?= t('Assistive technology you use') ?></label><input type="text" id="p_tech" name="p_tech" maxlength="120" value="<?= h($pv('p_tech', $client['a11y_primary_tech'] ?? '')) ?>" <?= field_aria('p_tech') ?>><?= field_error('p_tech') ?></div>
      <?php $contactOpts = ['Email', 'Phone', 'Client portal', 'Postal mail'];
      $curContact = (string)($client['a11y_contact'] ?? '');
      if ($curContact !== '' && $curContact !== 'No preference' && !in_array($curContact, $contactOpts, true)) $contactOpts[] = $curContact; ?>
      <div class="field"><label for="p_contact"><?= t('How should we contact you?') ?></label>
        <select id="p_contact" name="p_contact">
          <option value=""><?= h(__('No preference')) ?></option>
          <?= select_options($contactOpts, $pv('p_contact', $client['a11y_contact'] ?? '')) ?>
        </select></div>
      <div class="field"><label for="p_notes"><?= t('Anything else we should know') ?></label><p class="hint" id="p-notes-hint"><?= t('Up to 16,000 characters.') ?></p><textarea id="p_notes" name="p_notes" rows="2" <?= field_aria('p_notes', 'p-notes-hint') ?>><?= h($pv('p_notes', $client['a11y_notes'] ?? '')) ?></textarea><?= field_error('p_notes') ?></div>
    </fieldset>
    <button type="submit" name="update_profile" value="1" class="btn btn-primary"><?= t('Save my information') ?></button>
  </form>
  </details>
</section>
<?php endif; ?>

<?php if ($kb): ?>
<section class="card" aria-labelledby="kb-h">
  <h2 id="kb-h"><?= t('Helpful guides') ?></h2>
  <?php // A visible label, and a visible count that also says when nothing matches. ?>
  <div class="field"><label for="kbfilter"><?= t('Search guides') ?></label>
    <input type="search" id="kbfilter"></div>
  <p id="kb-count" class="hint" role="status" data-label="<?= h(__('guides shown')) ?>" data-none="<?= h(__('No guides match. Clear the search to see all guides.')) ?>"></p>
  <script>
  // Filter the guides as the visitor types, then say how many are left (after a
  // short pause, so a screen reader is not interrupted on every keystroke).
  (function () {
    var box = document.getElementById('kbfilter'), count = document.getElementById('kb-count'), timer;
    box.addEventListener('input', function () {
      var q = box.value.toLowerCase(), shown = 0;
      document.querySelectorAll('[data-kb]').forEach(function (d) {
        var hit = d.getAttribute('data-kb').toLowerCase().indexOf(q) >= 0;
        d.style.display = hit ? '' : 'none';
        if (hit) shown++;
      });
      clearTimeout(timer);
      timer = setTimeout(function () {
        count.textContent = q === '' ? '' : (shown === 0 ? count.getAttribute('data-none') : shown + ' ' + count.getAttribute('data-label'));
      }, 600);
    });
  })();
  </script>
  <?php foreach ($kb as $art): ?>
  <details class="goal-history" data-kb="<?= h($art['title'] . ' ' . ($art['category'] ?? '') . ' ' . mb_substr((string)$art['body'], 0, 400)) ?>">
    <summary><?= en((string)$art['title']) ?><?= $art['category'] ? ' <span class="muted">· ' . en((string)$art['category']) . '</span>' : '' ?></summary>
    <?php /* Staff author '## ' headings and '- ' lists: they come through as structure (escaped). */ ?>
    <div style="max-width:75ch"<?= page_lang() === 'en' ? '' : ' lang="en"' ?>><?= plain_text_html((string)$art['body'], 3, false) ?></div>
  </details>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($resources): ?>
<section class="card" aria-labelledby="res-h">
  <h2 id="res-h"><?= t('Resources from your instructor') ?></h2>
  <ul class="dl-list">
  <?php foreach ($resources as $doc): ?>
    <li><a href="portal.php?t=<?= h($token) ?>&amp;download=<?= (int)$doc['id'] ?>"><?= en((string)$doc['title']) ?><span class="visually-hidden"> <?= t('(downloads a file)') ?></span></a></li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php require __DIR__ . '/inc/at_portal.php';
page_footer();
