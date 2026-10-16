<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/at_ai.php';
require_once __DIR__ . '/inc/workflows.php'; // track_note_create()
csrf_check();
$user = require_login();
require_perm('clients');

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT t.*, c.name AS client_name FROM training_tracks t JOIN clients c ON c.id = t.client_id WHERE t.id = ?');
$stmt->execute([$id]);
$track = $stmt->fetch();
if (!$track) { flash('Training track not found.', 'error'); redirect('clients.php'); }
scope_check_client_id((int)$track['client_id'], $user);
if ($_SERVER['REQUEST_METHOD'] === 'GET') record_client_view((int)$user['id'], (int)$track['client_id'], 'training track');

/* A collapsed <details> opens when the URL's #fragment points inside it, so links
   and redirects to a heading or field in a closed "Add" form still land there. */
if (!function_exists('details_open_on_hash')) {
    function details_open_on_hash(string $id): string {
        return '<script>(function(){var d=document.getElementById(' . json_encode($id) . ');if(!d)return;'
            . 'function o(j){var t=null;try{t=location.hash.length>1?document.getElementById(decodeURIComponent(location.hash.slice(1))):null;}catch(e){}'
            . 'if(t&&d.contains(t)&&!d.open){d.open=true;if(j)t.scrollIntoView();}}o(false);window.addEventListener("hashchange",function(){o(true);});})();</script>';
    }
}

/* ---- Goal-template search (shared by the picker and its live JSON endpoint) ----
   Templates are searched on demand so the page never has to render the whole
   library (which is very large). */
function search_goal_templates(string $q, string $cat, int $limit = 50): array {
    $q = trim($q); $cat = trim($cat);
    $where = []; $args = [];
    if ($cat !== '') { $where[] = 'category = ?'; $args[] = $cat; }
    // Each word must match somewhere (category, objective, or focus), so a
    // multi-word query like "JAWS email" narrows instead of needing an exact phrase.
    foreach (array_slice(preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6) as $word) {
        $where[] = '(specific_goal LIKE ? OR category LIKE ? OR focus_area LIKE ?)';
        $like = '%' . $word . '%'; array_push($args, $like, $like, $like);
    }
    $sql = 'SELECT id, category, specific_goal FROM goal_templates';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY category, specific_goal LIMIT ' . max(1, min(200, $limit));
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}
/* posted_note_homework() lives in inc/core.php (shared with client.php). */

if (isset($_GET['tpl_search_json'])) {
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    $out = array_map(fn($r) => ['id' => (int)$r['id'], 'category' => $r['category'], 'specific_goal' => $r['specific_goal']],
        search_goal_templates((string)($_GET['tpl_q'] ?? ''), (string)($_GET['tpl_cat'] ?? ''), 50));
    echo json_encode($out);
    exit;
}

/* ---- POST: add SMART goal ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_goal'])) {
    // The same form sent twice (Enter pressed again while it loads) adds the goal once.
    $goalKey = web_idem_key($_POST);
    if (web_once_get($goalKey) !== null) {
        flash('This goal was already added (the form was sent twice), so it was saved once.', 'info');
        redirect("track.php?id=$id#goals");
    }
    // A field posted as an array (name[]=) is treated as blank, never a TypeError.
    $specific = is_string($_POST['specific_goal'] ?? null) ? trim($_POST['specific_goal']) : '';
    $metric = is_string($_POST['measurable_metric'] ?? null) ? trim($_POST['measurable_metric']) : '';
    $focus = is_string($_POST['focus_area'] ?? null) ? trim($_POST['focus_area']) : '';
    // A target date that does not parse is reported, never dropped: same years as the work date.
    $targetRaw = is_string($_POST['target_date'] ?? null) ? trim($_POST['target_date']) : '';
    $target = valid_date($targetRaw, 2000, (int)date('Y') + 5);
    $gErrs = [];
    // Both are TEXT columns (65,535 bytes), as the app's API_TEXT_BYTES allows.
    if ($specific === '') $gErrs['specific_goal'] = 'A specific objective is required.';
    elseif (strlen($specific) > 65535) $gErrs['specific_goal'] = 'The specific objective is too long to save. It can hold 65,535 bytes (accented letters and symbols count as 2 to 4 bytes each); shorten it.';
    if ($metric === '') $gErrs['measurable_metric'] = 'Measurable criteria are required.';
    elseif (strlen($metric) > 65535) $gErrs['measurable_metric'] = 'The measurable criteria are too long to save. They can hold 65,535 bytes (accented letters and symbols count as 2 to 4 bytes each); shorten them.';
    if (mb_strlen($focus) > 190) $gErrs['focus_area'] = 'Keep the focus area to 190 characters or fewer.';
    if ($targetRaw !== '' && !$target) $gErrs['target_date'] = 'Enter a valid target completion date with a four-digit year from 2000 to ' . ((int)date('Y') + 5) . ', or leave it blank.';
    if ($gErrs) {
        keep_input();
        keep_field_errors($gErrs);
    } else {
        $newStatus = in_array($_POST['goal_status'] ?? '', GOAL_STATUSES, true) ? $_POST['goal_status'] : 'Not Started';
        // A goal created as Achieved is complete; any other status starts at 0%.
        db()->prepare('INSERT INTO smart_goals (track_id, specific_goal, measurable_metric, focus_area, status, progress_pct, target_date) VALUES (?,?,?,?,?,?,?)')
            ->execute([$id, $specific, $metric, $focus ?: null, $newStatus, $newStatus === 'Achieved' ? 100 : 0, $target]);
        web_once_set($goalKey, (int)db()->lastInsertId());
        audit($user['id'], 'goal.add', 'track', $id);
        flash('SMART goal added.');
    }
    redirect("track.php?id=$id#goals");
}

/* ---- POST: record goal progress (status + percent + note, kept as history) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_goal'])) {
    $gid = (int)($_POST['goal_id'] ?? 0);
    $own = db()->prepare('SELECT status, progress_pct FROM smart_goals WHERE id = ? AND track_id = ?');
    $own->execute([$gid, $id]);
    $cur = $own->fetch();
    if (!$cur) { flash('Goal not found.', 'error'); redirect("track.php?id=$id#goals"); }
    $gs = in_array($_POST['goal_status'] ?? '', GOAL_STATUSES, true) ? $_POST['goal_status'] : $cur['status'];
    $note = (is_string($_POST['progress_note'] ?? null) ? trim($_POST['progress_note']) : '') ?: null;
    $rawPct = is_string($_POST['progress_pct'] ?? null) ? trim($_POST['progress_pct']) : '';
    $curPct = (int)$cur['progress_pct'];
    // Refuse this one row with its own error (each goal row has its own form), and
    // keep what was typed for that row only, so no other form on the page refills.
    $goalErr = function (string $msg, bool $askReopen = false) use ($gid, $gs, $rawPct, $note, $id): never {
        $_SESSION['goal_progress_draft'] = ['goal_id' => $gid, 'status' => $gs, 'pct' => $rawPct, 'note' => (string)$note, 'reopen_asked' => $askReopen];
        keep_field_errors(['goal_pct_' . $gid => $msg]);
        redirect("track.php?id=$id#goals");
    };
    // The note is a TEXT column (65,535 bytes), as the app's API_TEXT_BYTES allows.
    if ($note !== null && strlen($note) > 65535) $goalErr('The progress note is too long to save. It can hold 65,535 bytes; shorten it.');
    // A blank percent keeps the current value, so a status-only change keeps the bar.
    $pct = $curPct;
    if ($rawPct !== '') {
        $pct = filter_var($rawPct, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
        if ($pct === false) $goalErr('Enter the percent complete as a whole number from 0 to 100.');
    }
    // Percent follows the status at the extremes. When only the status changed,
    // Not Started means 0%; a goal reopened from Achieved at 100% is asked once
    // for its real percent, and submitting the same percent again keeps it.
    if ($gs === 'Achieved') $pct = 100;
    elseif ($gs !== $cur['status'] && $pct === $curPct) {
        if ($gs === 'Not Started') $pct = 0;
        elseif ($cur['status'] === 'Achieved' && $curPct === 100 && ($_POST['reopen_confirmed'] ?? '') !== '1') {
            $goalErr('This goal was ' . $curPct . '% complete while Achieved. Enter how complete it is now that it is ' . $gs
                . ' again, or choose Record again to keep it at ' . $curPct . '%.', true);
        }
    }
    db()->beginTransaction();
    try {
        db()->prepare('UPDATE smart_goals SET status = ?, progress_pct = ? WHERE id = ? AND track_id = ?')
            ->execute([$gs, $pct, $gid, $id]);
        db()->prepare('INSERT INTO goal_progress (goal_id, progress_pct, status, note, recorded_by) VALUES (?,?,?,?,?)')
            ->execute([$gid, $pct, $gs, $note, $user['id']]);
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    audit($user['id'], 'goal.status', 'goal', $gid, $gs . ' ' . $pct . '%');
    flash('Progress recorded — ' . $pct . '% (' . $gs . ').');
    redirect("track.php?id=$id#goals");
}

/* ---- POST: record a session — its hours, its note, or both, from one form ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_log'])) {
    // The same form sent twice (Enter pressed again while it loads) is saved once:
    // its key is stored in work_logs.idem_key and on the note (client_notes.idem_key),
    // as the app's entries are.
    $logKey = web_idem_key($_POST);
    $logDup = db()->prepare('SELECT id FROM work_logs WHERE idem_key = ? UNION SELECT id FROM client_notes WHERE idem_key = ?');
    $logAlready = function () use ($logKey, $logDup, $id): void {
        if ($logKey === null) return;
        $logDup->execute([$logKey, $logKey]);
        if ($logDup->fetchColumn()) {
            flash('This session was already saved (the form was sent twice), so it was saved once.', 'info');
            redirect("track.php?id=$id#logs");
        }
    };
    $logAlready();
    $hoursRaw = is_string($_POST['hours'] ?? null) ? trim($_POST['hours']) : '';
    // The session note: type, format, structured sections and narrative, as the
    // client's Add a note form takes them. Pages opened before 3.0.31 post the
    // narrative as session_note and its subject as session_subject.
    $noteIn = $_POST;
    if (trim(is_string($noteIn['body'] ?? null) ? $noteIn['body'] : '') === '' && is_string($_POST['session_note'] ?? null)) $noteIn['body'] = $_POST['session_note'];
    $na = assemble_note($noteIn);
    $hasNote = $na['body'] !== '';
    $hasHours = $hoursRaw !== '' || !$hasNote;
    // As for session completion (auto_log_session) and the voice assistant: work
    // is never snapshotted at a $0.00 rate. What was typed is kept.
    if ($hasHours && track_needs_rate($track) && (float)$track['hourly_rate'] <= 0) {
        keep_input();
        flash('Nothing was saved: this training track has no hourly rate yet. Enter its hourly billing rate under Edit track / authorization, then log the hours. To save only the note, clear Hours worked.', 'error');
        redirect("track.php?id=$id#logs");
    }
    // A year from 2000 on and never after today (hours are logged for a session
    // that has taken place; a year slip would otherwise be billed), hours checked
    // after rounding to the column's two decimals (so 0.004 can't store 0.00), and
    // at most 1000 miles per entry (an odometer reading typed here would otherwise
    // be billed, or overflow the column).
    $date = valid_date(is_string($_POST['work_date'] ?? null) ? $_POST['work_date'] : '', 2000, (int)date('Y') + 5);
    $futureDate = $date !== null && $date > date('Y-m-d');
    $hours = is_numeric($hoursRaw) ? round((float)$hoursRaw, 2) : 0.0;
    $mileRaw = is_string($_POST['mileage'] ?? null) ? trim($_POST['mileage']) : '';
    $mileage = $mileRaw === '' ? 0.0 : (is_numeric($mileRaw) ? round((float)$mileRaw, 1) : -1.0);
    $desc = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';
    // The visit's service, RSA category and goal: a choice left out takes the
    // track's default (or no goal), but one that is not offered here (a stale
    // page, a goal since removed) is refused, never swapped for the default.
    $rawType = is_string($_POST['visit_service_type'] ?? null) ? $_POST['visit_service_type'] : '';
    $visitType = $rawType === '' ? ($track['service_type'] ?? 'Assistive Technology') : $rawType;
    $offered = track_visit_categories($track);
    $rawRsa = is_string($_POST['visit_rsa_category'] ?? null) ? $_POST['visit_rsa_category'] : '';
    $visitRsa = $rawRsa === '' ? ($offered[0] ?? null) : $rawRsa;
    // Optional goal link — must be one of THIS track's goals.
    $rawGoal = is_string($_POST['goal_id'] ?? null) ? trim($_POST['goal_id']) : '';
    $goalId = null; $goalOk = true;
    if ($rawGoal !== '') {
        $goalOk = ctype_digit($rawGoal) && (int)$rawGoal > 0;
        if ($goalOk) {
            $gv = db()->prepare('SELECT id FROM smart_goals WHERE id = ? AND track_id = ?');
            $gv->execute([(int)$rawGoal, $id]);
            $goalOk = (bool)$gv->fetch();
            $goalId = $goalOk ? (int)$rawGoal : null;
        }
    }
    $typeOk = $rawType === '' || in_array($rawType, SERVICE_TYPES, true);
    $rsaOk = $rawRsa === '' || in_array($rawRsa, $offered, true);
    // The description is a TEXT column (65,535 bytes), as the app's API_TEXT_BYTES allows.
    $descTooLong = strlen($desc) > 65535;
    // The note's subject defaults to the session's date.
    $sSubject = is_string($_POST['subject'] ?? null) ? trim($_POST['subject']) : '';
    if ($sSubject === '' && is_string($_POST['session_subject'] ?? null)) $sSubject = trim($_POST['session_subject']);
    if ($sSubject === '' && $date) $sSubject = 'Session on ' . fmt_date($date);
    // Work outside the authorization (its dates, hours or dollars) or on a track that is
    // not Active is refused until an amendment is recorded (owner decision, 3.0.34); it
    // is checked again under the track's lock below. A note on its own is not work.
    $authIssue = null;
    $lErrs = [];
    if (!$date) $lErrs['work_date'] = 'Enter a valid date with a four-digit year from 2000 on, no later than today.';
    elseif ($futureDate) $lErrs['work_date'] = 'The date ' . fmt_date($date) . ' is in the future. A session is recorded once it has taken place: enter the session date, today or earlier.';
    if ($hasHours) {
        if (!$rsaOk) $lErrs['visit_rsa_category'] = 'RSA service delivered this visit: choose one of the services listed for this track.';
        if (!$typeOk) $lErrs['visit_service_type'] = 'Service type: choose one of the service types listed.';
        if (!$goalOk) $lErrs['goal_id'] = 'Goal this session advanced: that goal is not on this track any more. Choose one of the goals listed, or None.';
        if ($hours < 0.01 || $hours > 24) $lErrs['hours'] = $hoursRaw === '' ? 'Enter the hours worked, or write a session note to save the note on its own.' : 'Enter the hours worked as a number more than 0 and no more than 24, such as 1.5.';
        if ($mileage < 0 || $mileage > 1000) $lErrs['mileage'] = 'Mileage must be a number from 0 to 1000 miles per entry.';
        if ($desc === '') $lErrs['description'] = 'Describe the activity, for the hours on the invoice.';
        elseif ($descTooLong) $lErrs['description'] = 'The activity description is too long to save. It can hold 65,535 bytes (accented letters and symbols count as 2 to 4 bytes each); shorten it.';
    }
    if ($hasNote) {
        if (mb_strlen($sSubject) > 255) $lErrs['subject'] = 'Keep the note subject to 255 characters or fewer.';
        // Each section and the assembled note are TEXT columns (65,535 bytes), as on client.php.
        $tooLong = [];
        foreach ($na['cols'] as $i => $v) if ($v !== null && strlen($v) > 65535) $tooLong[] = NOTE_FORMATS[$na['format']][$i];
        if ($tooLong || strlen($na['body']) > 65535) {
            $lErrs['note_content'] = 'The note is too long to save'
                . ($tooLong ? ' (the ' . implode(', ', $tooLong) . ' section' . (count($tooLong) === 1 ? ' is' : 's are') . ' over the limit)' : '')
                . '. A note can hold 65,535 bytes, and accented letters and symbols count as 2 to 4 bytes each. Shorten it, or add the rest as a separate note.';
        }
        // A ticked homework row's due date is checked as the Assign homework form checks
        // it, and reported on that row, never dropped (the portal would show none).
        $hwMaxYear = (int)date('Y') + 5;
        foreach (posted_note_homework($_POST) as $k => $hwRow) {
            if (!$hwRow['include'] || trim($hwRow['title']) === '' || trim($hwRow['due']) === '') continue;
            if (!valid_date($hwRow['due'], 2000, $hwMaxYear)) {
                $lErrs['tn_hw_due_' . $k] = 'Homework item ' . ($k + 1) . ': enter a valid due date with a four-digit year from 2000 to ' . $hwMaxYear . ', or clear it.';
            }
        }
    }
    if ($lErrs) {
        keep_input();
        // Generated homework rows are arrays, which keep_input() leaves out: hold
        // them for the next page so they stay in the form until the session is saved.
        if ($hwRows = posted_note_homework($_POST)) $_SESSION['tn_homework'] = ['track' => $id, 'rows' => $hwRows];
        keep_field_errors($lErrs);
    } else {
        // The rate snapshot is read under the track's row lock, in one transaction
        // with the insert (track_edit.php re-prices unbilled work under that lock),
        // so a rate saved at this moment either applies here or re-prices this log.
        // The hours, the note and its homework are saved together or not at all.
        $noteId = null; $logId = null; $added = 0;
        db()->beginTransaction();
        try {
            if ($hasHours) {
                $rq = db()->prepare('SELECT * FROM training_tracks WHERE id = ? FOR UPDATE');
                $rq->execute([$id]);
                $rates = $rq->fetch();
                if (!$rates || (track_needs_rate($rates) && (float)$rates['hourly_rate'] <= 0)) {
                    db()->rollBack();
                    keep_input();
                    flash('Nothing was saved: this training track has no hourly rate yet. Enter its hourly billing rate under Edit track / authorization, then log the hours.', 'error');
                    redirect("track.php?id=$id#logs");
                }
                // A copy of this form that waited on the lock behind the first is that same session.
                if ($logKey !== null) { $logDup->execute([$logKey, $logKey]); if ($logDup->fetchColumn()) { db()->rollBack(); $logAlready(); } }
                $block = work_authorization_block($rates, $date, $hours, round($hours * (float)$rates['hourly_rate'], 2) + round($mileage * (float)$rates['mileage_rate'], 2));
                if ($block !== null) {
                    db()->rollBack();
                    keep_input();
                    if ($hwRows = posted_note_homework($_POST)) $_SESSION['tn_homework'] = ['track' => $id, 'rows' => $hwRows];
                    keep_field_errors(['hours' => $block]);
                    redirect("track.php?id=$id#log-session-h");
                }
                db()->prepare('INSERT INTO work_logs (track_id, work_date, service_type, rsa_category, goal_id, hours, mileage, description, created_by, hourly_rate, mileage_rate, idem_key) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$id, $date, $visitType, $visitRsa, $goalId, $hours, $mileage, $desc, $user['id'],
                        $rates['hourly_rate'], $rates['mileage_rate'], $logKey]);
                $logId = (int)db()->lastInsertId();
            }
            if ($hasNote) {
                $noteId = track_note_create((int)$track['client_id'], $id, $sSubject, $na, (int)$user['id'], $logKey);
                // Homework captured by the generator attaches to THIS track (shows in the portal).
                $added = save_note_homework((int)$track['client_id'], $id, $_POST, (int)$user['id']);
            }
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            // Only a clash on the form's key means "already saved" (a concurrent copy won).
            if ($e instanceof PDOException && (string)$e->getCode() === '23000') $logAlready();
            throw $e;
        }
        if ($hasHours) audit($user['id'], 'hours.log', 'track', $id, $hours . ' hrs on ' . $date . ' (' . $visitRsa . ')');
        flash(($hasHours && $hasNote ? fmt_hours($hours) . ' logged and the session note saved.' : ($hasHours ? 'Hours logged.' : 'Session note saved.'))
            . ($added ? ' ' . $added . ' homework item' . ($added === 1 ? '' : 's') . ' added to the client portal.' : ''));
        // Documentation reminder: active-training sessions should have homework.
        $hwq = db()->prepare('SELECT COUNT(*) FROM homework WHERE track_id = ?');
        $hwq->execute([$id]);
        if ($hasHours && (int)$hwq->fetchColumn() === 0) {
            // The save succeeded: a reminder, not an error (no "Error:" prefix or error earcon).
            flash('Reminder: no homework is assigned on this track. Add practice/homework so the session is fully documented.', 'info');
        }
        // The saved session note is offered for an AI homework draft beside the message.
        if ($noteId !== null) redirect("track.php?id=$id&saved_note=$noteId#logs");
    }
    redirect("track.php?id=$id#logs");
}

/* ---- POST: generate invoice from unbilled entries (needs the billing permission) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_billed'])) {
    require_perm('billing');
    $invId = generate_invoice($id, $user['id']);
    if ($invId) {
        audit($user['id'], 'invoice.create', 'invoice', $invId, 'from track #' . $id);
        // Name the new draft, and land on it alone (with a link back to every invoice).
        $ni = db()->prepare('SELECT invoice_number, total_amount FROM invoices WHERE id = ?');
        $ni->execute([$invId]);
        $newInv = $ni->fetch() ?: ['invoice_number' => '', 'total_amount' => 0];
        flash('Draft invoice ' . $newInv['invoice_number'] . ' for ' . $track['client_name'] . ', ' . fmt_money((float)$newInv['total_amount'])
            . ', created. Review it below, then mark it submitted when it goes to the funder.');
        redirect('invoices.php?id=' . $invId);
    }
    flash('No unbilled entries dated today or earlier to invoice.', 'error');
    redirect("track.php?id=$id#logs");
}

/* ---- POST: delete a log entry (unbilled only; goes to the 30-day trash) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_log'])) {
    // Looked up whether billed or not, so a stale page (already deleted) is told
    // that, and only work really on an invoice gets the invoice answer.
    $lg = db()->prepare('SELECT id, work_date, hours, is_billed FROM work_logs WHERE id = ? AND track_id = ?');
    $lg->execute([(int)($_POST['log_id'] ?? 0), $id]);
    $log = $lg->fetch();
    if (!$log) {
        flash('That entry was already deleted or no longer exists. The list below shows the current entries.', 'error');
    } elseif ($log['is_billed']) {
        flash('That entry is already on an invoice and cannot be deleted (the invoice totals would no longer match).', 'error');
    } elseif (trash_row('work_logs', (int)$log['id'], (int)$user['id'],
            'Work log: ' . fmt_hours((float)$log['hours']) . ' on ' . fmt_date($log['work_date']) . ' (' . $track['client_name'] . ')')) {
        audit($user['id'], 'hours.delete', 'track', $id, 'log #' . (int)$log['id'] . ' (to trash)');
        flash('Log entry moved to the trash — an administrator can restore it for 30 days.');
    } else {
        flash('That entry was not deleted: it was invoiced or removed a moment ago. The list below shows its current state.', 'error');
    }
    redirect("track.php?id=$id#logs");
}

/* ---- POST: delete an expense (unbilled only; goes to the 30-day trash) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_expense'])) {
    $ex = db()->prepare('SELECT id, expense_date, description, amount, is_billed FROM track_expenses WHERE id = ? AND track_id = ?');
    $ex->execute([(int)($_POST['expense_id'] ?? 0), $id]);
    $exp = $ex->fetch();
    if (!$exp) {
        flash('That expense was already deleted or no longer exists. The list below shows the current expenses.', 'error');
    } elseif ($exp['is_billed']) {
        flash('That expense is already on an invoice and cannot be deleted (the invoice totals would no longer match).', 'error');
    } elseif (trash_row('track_expenses', (int)$exp['id'], (int)$user['id'],
            'Expense: ' . fmt_money((float)$exp['amount']) . ' ' . $exp['description'] . ' on ' . fmt_date($exp['expense_date']) . ' (' . $track['client_name'] . ')')) {
        audit($user['id'], 'expense.delete', 'track', $id, 'expense #' . (int)$exp['id'] . ' ' . fmt_money((float)$exp['amount']) . ' (to trash)');
        flash('Expense moved to the trash — an administrator can restore it for 30 days.');
    } else {
        flash('That expense was not deleted: it was invoiced or removed a moment ago. The list below shows its current state.', 'error');
    }
    redirect("track.php?id=$id#expenses");
}

/* ---- POST: homework ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_homework'])) {
    $title = is_string($_POST['hw_title'] ?? null) ? trim($_POST['hw_title']) : '';
    $instructions = is_string($_POST['hw_instructions'] ?? null) ? trim($_POST['hw_instructions']) : '';
    // A due date that does not parse is reported, never dropped (the portal would show none).
    $dueRaw = is_string($_POST['hw_due'] ?? null) ? trim($_POST['hw_due']) : '';
    $due = valid_date($dueRaw, 2000, (int)date('Y') + 5);
    $hErrs = [];
    if ($title === '') $hErrs['hw_title'] = 'A homework title is required.';
    elseif (mb_strlen($title) > 190) $hErrs['hw_title'] = 'Keep the homework title to 190 characters or fewer.';
    // A TEXT column (65,535 bytes), as the app's API_TEXT_BYTES allows.
    if (strlen($instructions) > 65535) $hErrs['hw_instructions'] = 'The instructions are too long to save. They can hold 65,535 bytes (accented letters and symbols count as 2 to 4 bytes each); shorten them.';
    if ($dueRaw !== '' && !$due) $hErrs['hw_due'] = 'Enter a valid due date with a four-digit year from 2000 to ' . ((int)date('Y') + 5) . ', or leave it blank.';
    // The same form sent twice (Enter pressed again while it loads) assigns the homework once.
    $hwKey = web_idem_key($_POST);
    if ($hErrs) {
        keep_input();
        keep_field_errors($hErrs);
    } elseif (web_once_get($hwKey) !== null) {
        flash('This homework was already assigned (the form was sent twice), so it was saved once.', 'info');
    } else {
        db()->prepare('INSERT INTO homework (track_id, title, instructions, assigned_at, due_date, created_by) VALUES (?,?,?,?,?,?)')
            ->execute([$id, $title, $instructions ?: null, date('Y-m-d'), $due, $user['id']]);
        web_once_set($hwKey, (int)db()->lastInsertId());
        audit($user['id'], 'homework.assign', 'track', $id, $title);
        flash('Homework assigned — it now shows in the client portal.');
    }
    redirect("track.php?id=$id#homework");
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hw_done'])) {
    $hwId = (int)($_POST['hw_id'] ?? 0);
    $done = db()->prepare("UPDATE homework SET status = 'Completed' WHERE id = ? AND track_id = ?");
    $done->execute([$hwId, $id]);
    if ($done->rowCount() > 0) {
        audit($user['id'], 'homework.complete', 'homework', $hwId, 'track #' . $id);
        flash('Homework marked completed.');
    } else {
        flash('That homework was not changed: it is already completed or no longer exists. The list below shows its current status.', 'error');
    }
    redirect("track.php?id=$id#homework");
}

/* ---- POST: expense ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense'])) {
    // The same form sent twice is logged once: its key is stored in track_expenses.idem_key.
    $expKey = web_idem_key($_POST);
    $expDup = db()->prepare('SELECT id FROM track_expenses WHERE idem_key = ?');
    $expAlready = function () use ($expKey, $expDup, $id): void {
        if ($expKey === null) return;
        $expDup->execute([$expKey]);
        if ($expDup->fetchColumn()) {
            flash('This expense was already logged (the form was sent twice), so it was saved once.', 'info');
            redirect("track.php?id=$id#expenses");
        }
    };
    $expAlready();
    $desc = is_string($_POST['exp_description'] ?? null) ? trim($_POST['exp_description']) : '';
    $amtRaw = is_string($_POST['exp_amount'] ?? null) ? trim($_POST['exp_amount']) : '';
    // Dollars and cents only (the column holds up to 9,999,999.99); never round or guess.
    $amt = preg_match('/^(\d{1,7}(\.\d{0,2})?|\.\d{1,2})$/D', $amtRaw) ? (float)$amtRaw : 0.0;
    $expDateRaw = is_string($_POST['exp_date'] ?? null) ? trim($_POST['exp_date']) : '';
    $expDate = $expDateRaw === '' ? date('Y-m-d') : valid_date($expDateRaw, 2000, (int)date('Y') + 5);
    // An expense is logged once it has been incurred, never ahead (it would be billed on the next invoice).
    $expFuture = $expDate !== null && $expDate > date('Y-m-d');
    $eErrs = [];
    if ($desc === '') $eErrs['exp_description'] = 'An expense description is required.';
    elseif (mb_strlen($desc) > 255) $eErrs['exp_description'] = 'Keep the description to 255 characters or fewer.';
    if ($amt <= 0) $eErrs['exp_amount'] = 'Enter a positive amount in dollars and cents, such as 150.00.';
    if (!$expDate) $eErrs['exp_date'] = 'Enter a valid date with a four-digit year from 2000 on, no later than today, or leave it blank for today.';
    elseif ($expFuture) $eErrs['exp_date'] = 'The date ' . fmt_date($expDate) . ' is in the future. Log an expense once it has been incurred: enter its date, today or earlier.';
    // As for hours: an expense outside the authorization (its dates or dollars), or on
    // a track that is not Active, is refused until an amendment is recorded (3.0.34),
    // checked under the track's lock with the insert.
    if ($eErrs) {
        keep_input();
        keep_field_errors($eErrs);
    }
    else {
        db()->beginTransaction();
        try {
            $lt = db()->prepare('SELECT * FROM training_tracks WHERE id = ? FOR UPDATE');
            $lt->execute([$id]);
            if ($expKey !== null) { $expDup->execute([$expKey]); if ($expDup->fetchColumn()) { db()->rollBack(); $expAlready(); } }
            $block = work_authorization_block($lt->fetch() ?: $track, $expDate, 0.0, $amt);
            if ($block !== null) {
                db()->rollBack();
                keep_input();
                keep_field_errors(['exp_amount' => $block]);
                redirect("track.php?id=$id#expenses");
            }
            db()->prepare('INSERT INTO track_expenses (track_id, expense_date, description, amount, created_by, idem_key) VALUES (?,?,?,?,?,?)')
                ->execute([$id, $expDate, $desc, $amt, $user['id'], $expKey]);
            db()->commit();
        } catch (PDOException $e) {
            if (db()->inTransaction()) db()->rollBack();
            // Only a clash on the form's key means "already logged" (a concurrent copy won).
            if ((string)$e->getCode() === '23000') $expAlready();
            throw $e;
        }
        audit($user['id'], 'expense.add', 'track', $id, fmt_money($amt) . ' ' . $desc);
        flash('Expense logged' . (track_mode($track) === 'none' ? '.' : ' — it will be included on the next invoice.'));
    }
    redirect("track.php?id=$id#expenses");
}

/* ---- POST: add a case note attached to THIS track (the note-only form before
   3.0.31; kept for pages opened before then — Record a session now takes notes) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_track_note'])) {
    // The same form sent twice saves the note (and its homework) once: its key is
    // stored in client_notes.idem_key, as the app's notes are.
    $noteKey = web_idem_key($_POST);
    $noteDup = db()->prepare('SELECT id FROM client_notes WHERE idem_key = ?');
    $noteAlready = function () use ($noteKey, $noteDup, $id): void {
        if ($noteKey === null) return;
        $noteDup->execute([$noteKey]);
        if ($noteDup->fetchColumn()) {
            flash('This note was already saved (the form was sent twice), so it was saved once.', 'info');
            redirect("track.php?id=$id#notes");
        }
    };
    $noteAlready();
    $subject = is_string($_POST['subject'] ?? null) ? trim($_POST['subject']) : '';
    $a = assemble_note($_POST);
    $tnErrs = [];
    if ($subject === '') $tnErrs['subject'] = 'A subject is required.';
    elseif (mb_strlen($subject) > 255) $tnErrs['subject'] = 'Keep the subject to 255 characters or fewer.';
    if ($a['body'] === '') $tnErrs['note_content'] = 'Add a note, or fill in the structured sections.';
    else {
        // Each section and the assembled note are TEXT columns (65,535 bytes), as on client.php.
        $tooLong = [];
        foreach ($a['cols'] as $i => $v) if ($v !== null && strlen($v) > 65535) $tooLong[] = NOTE_FORMATS[$a['format']][$i];
        if ($tooLong || strlen($a['body']) > 65535) {
            $tnErrs['note_content'] = 'The note is too long to save'
                . ($tooLong ? ' (the ' . implode(', ', $tooLong) . ' section' . (count($tooLong) === 1 ? ' is' : 's are') . ' over the limit)' : '')
                . '. A note can hold 65,535 bytes, and accented letters and symbols count as 2 to 4 bytes each. Shorten it, or split it into two notes.';
        }
    }
    // A ticked homework row's due date is checked as the Assign homework form checks
    // it, and reported on that row, never dropped (the portal would show none).
    $hwMaxYear = (int)date('Y') + 5;
    foreach (posted_note_homework($_POST) as $k => $hwRow) {
        if (!$hwRow['include'] || trim($hwRow['title']) === '' || trim($hwRow['due']) === '') continue;
        if (!valid_date($hwRow['due'], 2000, $hwMaxYear)) {
            $tnErrs['tn_hw_due_' . $k] = 'Homework item ' . ($k + 1) . ': enter a valid due date with a four-digit year from 2000 to ' . $hwMaxYear . ', or clear it.';
        }
    }
    if ($tnErrs) {
        keep_input();
        // Generated homework rows are arrays, which keep_input() leaves out: hold
        // them for the next page so they stay in the form until the note is saved.
        if ($hwRows = posted_note_homework($_POST)) $_SESSION['tn_homework'] = ['track' => $id, 'rows' => $hwRows];
        keep_field_errors($tnErrs);
    } else {
        // The note and its homework are saved together or not at all.
        db()->beginTransaction();
        try {
            track_note_create((int)$track['client_id'], $id, $subject, $a, (int)$user['id'], $noteKey);
            // Homework captured by the generator attaches to THIS track (shows in the portal).
            $added = save_note_homework((int)$track['client_id'], $id, $_POST, (int)$user['id']);
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            // Only a clash on the form's key means "already saved" (a concurrent copy won).
            if ($e instanceof PDOException && (string)$e->getCode() === '23000') $noteAlready();
            throw $e;
        }
        flash('Note added to this track.' . ($added ? ' ' . $added . ' homework item' . ($added === 1 ? '' : 's') . ' added to the client portal.' : ''));
    }
    // Errors are shown on Record a session, which holds the note fields since 3.0.31.
    redirect("track.php?id=$id" . ($tnErrs ? "#log-session-h" : "#notes"));
}

/* ---- POST: generate a structured note from messy notes (no redirect; re-renders) ---- */
$tnSections = null; $tnQuestions = null; $tnHomework = null; $tnFormat = 'BIRP';
// Homework rows held over from a note save that failed validation (see add_track_note).
if (($_SESSION['tn_homework']['track'] ?? null) === $id) $tnHomework = $_SESSION['tn_homework']['rows'];
unset($_SESSION['tn_homework']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gen_track_note'])) {
    // This re-renders in the same request, so refill the form from what was just
    // posted (subject, type, format, sections, notes) whether or not it works,
    // and keep any homework rows already in the form until the note is saved.
    $GLOBALS['OLD_INPUT'] = safe_form_draft($_POST);
    $tnHomework = posted_note_homework($_POST) ?: null;
    $postedFormat = is_string($_POST['note_format'] ?? null) ? $_POST['note_format'] : '';
    $tnFormat = (isset(NOTE_FORMATS[$postedFormat]) && $postedFormat !== 'free') ? $postedFormat : 'BIRP';
    $raw = is_string($_POST['body'] ?? null) ? trim($_POST['body']) : '';
    foreach (['sec1', 'sec2', 'sec3', 'sec4'] as $sf) { $v = is_string($_POST[$sf] ?? null) ? trim($_POST[$sf]) : ''; if ($v !== '') $raw .= "\n" . $v; }
    if (!function_exists('ai_feature_enabled') || !ai_feature_enabled('draft')) { flash('The AI note generator is turned off. An administrator can enable it under AI assistant settings.', 'error'); }
    elseif ($raw === '') { flash('Paste your messy session notes in the box first, then Generate.', 'error'); }
    else {
        $cRow = db()->prepare('SELECT * FROM clients WHERE id = ?'); $cRow->execute([(int)$track['client_id']]); $cRow = $cRow->fetch() ?: null;
        $g = ai_generate_case_note($raw, $tnFormat, $cRow, (int)$user['id']);
        if ($g['ok'] && implode('', $g['sections']) === '') {
            // A reply without note sections must not blank what was typed.
            flash('The AI reply could not be split into ' . $tnFormat . ' sections, so nothing was changed. Try Generate again.', 'error');
        } elseif ($g['ok']) {
            $tnSections = $g['sections']; $tnQuestions = $g['questions'];
            // A section the AI left empty keeps whatever was typed in it.
            for ($i = 0; $i < 4; $i++) if (($tnSections[$i] ?? '') === '') $tnSections[$i] = trim(old_in('sec' . ($i + 1)));
            $tnHomework = array_map(fn($hw) => $hw + ['include' => true], $g['homework']) ?: null;
            flash('Generated a ' . $tnFormat . ' note — review the sections, then Save session. Text left in the narrative box is saved after the sections under "Narrative" — clear that box if the sections already cover it.');
        }
        else flash($g['error'], 'error');
        audit($user['id'], 'ai.draft', 'client', (int)$track['client_id'], 'track note generate (' . $tnFormat . ')');
    }
}

/* ---- POST: draft homework from one of this track's notes (no redirect; re-renders
   with the Assign homework form prefilled). Nothing is saved until Assign. ---- */
$hwDraft = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['draft_homework'])) {
    // The note must be on this track (the track's client scope was checked above).
    $dn = db()->prepare('SELECT * FROM client_notes WHERE id = ? AND track_id = ?');
    $dn->execute([(int)($_POST['note_id'] ?? 0), $id]);
    $draftNote = $dn->fetch();
    if (!$draftNote) flash('That note is not on this track or no longer exists, so no homework was drafted.', 'error');
    elseif (!function_exists('homework_draft_from_note') || !ai_feature_enabled('homework_draft')) flash('Drafting homework from a note is turned off. An administrator can enable it under AI assistant settings.', 'error');
    else {
        $d = homework_draft_from_note($draftNote, $track, (int)$user['id']);
        if (!$d['ok']) flash($d['error'], 'error');
        else {
            $instr = $d['instructions'];
            if ($d['minutes'] > 0) $instr .= ($instr !== '' ? "\n\n" : '') . 'Practice time: about ' . $d['minutes'] . ' minutes a day.';
            $hwDraft = ['subject' => (string)$draftNote['subject'], 'minutes' => $d['minutes'], 'due_days' => $d['due_days']];
            // The Assign homework form reads these as it reads a kept draft.
            $GLOBALS['OLD_INPUT'] = ['hw_title' => $d['title'], 'hw_instructions' => $instr,
                'hw_due' => $d['due_days'] > 0 ? date('Y-m-d', strtotime('+' . $d['due_days'] . ' days')) : ''] + ($GLOBALS['OLD_INPUT'] ?? []);
        }
    }
}

$goals = db()->prepare('SELECT g.*, COALESCE(gh.hrs, 0) AS goal_hours FROM smart_goals g
    LEFT JOIN (SELECT goal_id, SUM(hours) hrs FROM work_logs WHERE goal_id IS NOT NULL GROUP BY goal_id) gh ON gh.goal_id = g.id
    WHERE g.track_id = ? ORDER BY g.status = "Achieved", g.target_date IS NULL, g.target_date');
$goals->execute([$id]); $goals = $goals->fetchAll();

// Progress history for every goal on this track, newest first, grouped by goal.
$progressHist = [];
if ($goals) {
    $gids = array_map(fn($g) => (int)$g['id'], $goals);
    $ph = db()->prepare('SELECT gp.*, u.name AS by_name FROM goal_progress gp LEFT JOIN users u ON u.id = gp.recorded_by
        WHERE gp.goal_id IN (' . implode(',', array_fill(0, count($gids), '?')) . ') ORDER BY gp.created_at DESC, gp.id DESC');
    $ph->execute($gids);
    foreach ($ph->fetchAll() as $row) $progressHist[(int)$row['goal_id']][] = $row;
}

$logs = db()->prepare('SELECT w.*, u.name AS author FROM work_logs w LEFT JOIN users u ON u.id = w.created_by WHERE w.track_id = ? ORDER BY w.work_date DESC, w.id DESC');
$logs->execute([$id]); $logs = $logs->fetchAll();

$notes = db()->prepare('SELECT n.*, u.name AS author FROM client_notes n LEFT JOIN users u ON u.id = n.author_id WHERE n.track_id = ? ORDER BY n.created_at DESC, n.id DESC');
$notes->execute([$id]); $notes = $notes->fetchAll();

$r = track_rollup($id);
// Nothing authorized yet: said in words, never as 0 hours of 0 or a 0% bar.
$noAuthHours = $r['authorized_hours'] <= 0;
$noAuthFunds = $r['authorized_funds'] <= 0;
$noFundsText = $noAuthHours ? 'No authorization entered' : 'No hourly rate entered';
// An expired authorization is announced whatever the track's status (work logged on a
// Completed track would still be billed under it); one about to expire, only while Active.
$authExpired = $track['expiration_date'] && $track['expiration_date'] < date('Y-m-d');
$expiringSoon = $authExpired || ($track['expiration_date'] && strtotime($track['expiration_date']) < strtotime('+30 days') && $track['status'] === 'Active');
// One goal row's typed values after its progress form was refused (see update_goal).
$gpDraft = $_SESSION['goal_progress_draft'] ?? null;
unset($_SESSION['goal_progress_draft']);

// Track titles are generic ("Braille Literacy"), so the title and h1 name the client too.
page_header((form_errors() ? 'Error — ' : '') . $track['title'] . ' — ' . $track['client_name'], $user, 'clients', [['client.php?id=' . (int)$track['client_id'], $track['client_name']], ['client.php?id=' . (int)$track['client_id'] . '&tab=tracks', 'Training Tracks']]);
?>
<p class="page-actions"><a href="client.php?id=<?= (int)$track['client_id'] ?>&amp;tab=tracks"><span aria-hidden="true">&larr; </span>Back to <?= h($track['client_name']) ?></a></p>
<h1><?= h($track['title']) ?> <span class="visually-hidden">for</span><span aria-hidden="true"> — </span><?= h($track['client_name']) ?> <?= status_badge($track['status']) ?></h1>
<div class="page-actions">
  <a class="btn btn-secondary" href="track_edit.php?id=<?= $id ?>">Edit track / authorization</a>
  <?php if (can('reports')): ?>
  <a class="btn btn-secondary" href="report.php?track=<?= $id ?>">Email report to <abbr title="Vocational Rehabilitation counselor">VRC</abbr></a>
  <?php endif; ?>
</div>
<?php // The four export/statement variants are kept out of the way until wanted. ?>
<?php if (can('billing')): ?>
<details id="billing-exports">
  <summary>Billing and statements</summary>
  <div class="page-actions">
    <a class="btn btn-secondary" href="export.php?track=<?= $id ?>">Export billing CSV</a>
    <a class="btn btn-secondary" href="export.php?track=<?= $id ?>&amp;layout=table">Export billing CSV as one table (sessions and expenses)</a>
    <a class="btn btn-secondary" href="invoice_pdf.php?track=<?= $id ?>">Download PDF statement</a>
    <a class="btn btn-secondary" href="invoice_pdf.php?track=<?= $id ?>&amp;format=html">Download accessible HTML statement</a>
  </div>
</details>
<?php endif; ?>
<nav aria-labelledby="onpage-h">
  <p id="onpage-h"><strong>On this page</strong></p>
  <ul>
    <li><a href="#authorization">Service authorization</a></li>
    <li><a href="#log-session-h">Record a session</a></li>
    <li><a href="#notes">Case notes</a></li>
    <li><a href="#goals"><abbr title="Specific, Measurable, Attainable, Relevant, Time-bound">SMART</abbr> goals</a></li>
    <li><a href="#homework">Homework</a></li>
    <li><a href="#expenses">Billable expenses</a></li>
  </ul>
</nav>

<?php if ($expiringSoon): ?>
<div class="alert alert-error" role="note"><?= $authExpired
    ? 'This authorization expired on ' . fmt_date($track['expiration_date']) . '. Its expiration date has passed — renew or amend it before billing more work.'
    : 'This authorization expires on ' . fmt_date($track['expiration_date']) . '.' ?></div>
<?php endif; ?>

<section id="authorization" class="card" aria-labelledby="sa-h">
  <h2 id="sa-h">Service authorization</h2>
  <dl class="props">
    <dt>Service type</dt><dd><?= h($track['service_type'] ?? 'Assistive Technology') ?></dd>
    <dt><abbr title="Rehabilitation Services Administration">RSA</abbr> program</dt><dd><strong><?= h(track_program_label($track)) ?></strong>
      <?php $visitCats = track_visit_categories($track); if (count($visitCats) > 1): ?>
      <br><span class="muted">Covers: <?= h(implode(', ', $visitCats)) ?></span>
      <?php endif; ?></dd>
    <dt>Authorization number</dt><dd><?= h($track['auth_number']) ?></dd>
    <dt>Funding agency</dt><dd><?= h($track['funding_agency']) ?></dd>
    <dt>Authorized hours</dt><dd><?= $noAuthHours ? 'No authorization entered' : fmt_hours($r['authorized_hours']) ?></dd>
    <dt>Hourly rate</dt><dd><?= fmt_money($r['hourly_rate']) ?>/hr</dd>
    <?php if ($r['mileage_rate'] > 0): ?><dt>Mileage rate</dt><dd><?= fmt_mileage_rate($r['mileage_rate']) ?>/mile</dd><?php endif; ?>
    <dt>Period</dt><dd><?= fmt_date($track['start_date'], true) ?> to <?= fmt_date($track['expiration_date'], true) ?></dd>
    <dt>Authorized funds</dt><dd><?= $noAuthFunds ? $noFundsText : fmt_money($r['authorized_funds']) ?></dd>
  </dl>
  <p class="hint" id="burndown-hint">The burn-down shows the hours and funds used so far against this authorization, and drafts a request for more.</p>
  <p class="page-actions"><a class="btn btn-secondary btn-sm" href="amendment_request.php?track=<?= $id ?>" aria-describedby="burndown-hint">Authorization burn-down &amp; amendment request</a></p>
  <?php
  $amendments = db()->prepare('SELECT a.*, u.name AS changed_by_name FROM auth_amendments a LEFT JOIN users u ON u.id = a.changed_by WHERE a.track_id = ? ORDER BY a.changed_at DESC');
  $amendments->execute([$id]);
  $amendments = $amendments->fetchAll();
  ?>
  <?php if ($amendments): ?>
  <h3>Amendment history</h3>
  <div class="table-scroll" tabindex="0" role="region" aria-label="Authorization amendments">
  <table>
    <caption class="visually-hidden">Authorization amendments</caption>
    <thead><tr><th scope="col">Date</th><th scope="col">Change</th><th scope="col">Reason</th><th scope="col">By</th></tr></thead>
    <tbody>
    <?php foreach ($amendments as $am): ?>
      <tr>
        <td><?= fmt_datetime($am['changed_at']) ?></td>
        <td>
          <?php
          $parts = [];
          if ($am['new_hours'] !== null) $parts[] = 'Hours: ' . fmt_hours((float)$am['old_hours']) . ' → ' . fmt_hours((float)$am['new_hours']);
          if ($am['new_rate'] !== null) $parts[] = 'Rate: ' . fmt_money((float)$am['old_rate']) . ' → ' . fmt_money((float)$am['new_rate']);
          if ($am['new_expiration'] !== null || $am['old_expiration'] !== null) $parts[] = 'Expiration: ' . fmt_date($am['old_expiration']) . ' → ' . fmt_date($am['new_expiration']);
          if (($am['new_auth_number'] ?? null) !== null) $parts[] = 'Authorization number: ' . ($am['old_auth_number'] ?? '—') . ' → ' . $am['new_auth_number'];
          // Three decimals, as stored: a cent-rounded rate could show no change at all.
          if (($am['new_mileage_rate'] ?? null) !== null) $parts[] = 'Mileage rate: $' . number_format((float)$am['old_mileage_rate'], 3) . ' → $' . number_format((float)$am['new_mileage_rate'], 3) . ' per mile';
          echo h(implode('; ', $parts));
          ?>
        </td>
        <td><?= h($am['reason'] ?? '—') ?></td>
        <td><?= h($am['changed_by_name'] ?? 'System') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>

<h2 class="visually-hidden">Totals at a glance</h2>
<div class="grid grid-4">
  <div class="card stat"><span class="stat-num"><?= fmt_hours($r['hours_worked']) ?></span><span class="stat-label">Total hours worked</span></div>
  <div class="card stat"><span class="stat-num"><?= fmt_money($r['total_billed']) ?></span><span class="stat-label">Value of all logged work, invoiced or not<?= $r['mileage_amount'] > 0 ? ' (including ' . fmt_money($r['mileage_amount']) . ' mileage)' : '' ?></span></div>
  <?php // A private-pay or no-charge track has no authorization to count down (3.0.34). ?>
  <?php if (track_mode($track) !== 'vr'): ?>
  <div class="card stat"><span class="stat-label">Billing:</span> <strong><?= track_mode($track) === 'private' ? 'Private pay, ' . fmt_money((float)$track['hourly_rate']) . ' an hour, invoiced to the client' : 'No charge: hours are logged at $0 and never invoiced' ?></strong></div>
  <?php elseif ($noAuthHours): // With nothing authorized, "0 hours remaining" and "0%" would read as used up. ?>
  <div class="card stat"><span class="stat-label">Authorized hours remaining:</span> <strong>No authorization entered</strong> <a href="track_edit.php?id=<?= $id ?>">Edit track<span class="visually-hidden"> to enter the authorized hours</span></a></div>
  <?php else: ?>
  <?php // An overrun (older work, or an authorization amended down) is said as such, never as 0 remaining. ?>
  <div class="card stat"><span class="stat-num"><?= $r['hours_over'] > 0 ? fmt_hours($r['hours_over']) . ' over' : fmt_hours($r['hours_remaining']) ?></span><span class="stat-label"><?= $r['hours_over'] > 0 ? 'Over the authorized hours: no more can be logged until it is amended' : 'Authorized hours remaining' ?></span></div>
  <?php endif; ?>
  <?php if (track_mode($track) !== 'vr'): ?>
  <?php elseif ($noAuthFunds): ?>
  <div class="card stat"><span class="stat-label">Authorized funds remaining:</span> <strong><?= $noFundsText ?></strong> <a href="track_edit.php?id=<?= $id ?>">Edit track<span class="visually-hidden"> to enter the authorization</span></a></div>
  <?php else: ?>
  <div class="card stat"><span class="stat-num"><?= $r['funds_over'] > 0 ? fmt_money($r['funds_over']) . ' over' : fmt_money($r['funds_remaining']) ?></span><span class="stat-label"><?= $r['funds_over'] > 0 ? 'Over the authorized amount' : 'Authorized funds remaining' ?><?= $r['expenses_amount'] > 0 ? ' (after logged work and ' . fmt_money($r['expenses_amount']) . ' of expenses)' : '' ?></span></div>
  <?php endif; ?>
</div>

<?php if (track_mode($track) !== 'vr'): // nothing authorized to use up ?>
<?php elseif ($noAuthHours): ?>
<p class="card"><strong>Authorization usage:</strong> No authorization entered. <a href="track_edit.php?id=<?= $id ?>">Edit track<span class="visually-hidden"> to enter the authorized hours</span></a></p>
<?php else: ?>
<div class="progress-wrap card">
  <label for="prog-main" class="progress-caption">
    <strong>Authorization usage:</strong> <?= fmt_hours($r['hours_worked']) ?> of <?= fmt_hours($r['authorized_hours']) ?> (<?= $r['pct_used'] ?>%)
  </label>
  <progress id="prog-main" class="hours" max="100" value="<?= $r['pct_used'] ?>"><?= $r['pct_used'] ?>%</progress>
</div>
<?php endif; ?>

<section id="logs" class="card" aria-labelledby="logs-h">
  <h2 id="logs-h">Hours &amp; billing tracker</h2>
  <h3 id="log-session-h">Record a session</h3>
  <?php // Just after hours and a session note were saved together: offer an AI homework draft from that note.
  $savedNote = null;
  if (isset($_GET['saved_note']) && function_exists('ai_feature_enabled') && ai_feature_enabled('homework_draft')) {
      foreach ($notes as $tn) if ((int)$tn['id'] === (int)$_GET['saved_note']) { $savedNote = $tn; break; }
  }
  if ($savedNote): ?>
  <form method="post" action="track.php?id=<?= $id ?>#assign-hw-h" class="inline-form"><?= csrf_field() ?>
    <input type="hidden" name="note_id" value="<?= (int)$savedNote['id'] ?>">
    <button type="submit" name="draft_homework" value="1" class="btn btn-secondary btn-sm">Draft homework from this session note<span class="visually-hidden">: <?= h($savedNote['subject']) ?></span></button>
    <span class="hint">An AI draft of practice for the learner, from the note you just saved. You review it before assigning.</span>
  </form>
  <?php endif; ?>
  <?php if (track_needs_rate($track) && (float)$track['hourly_rate'] <= 0): ?>
  <p class="alert alert-error" role="note">Hours cannot be logged to this track yet because it has no hourly rate. <a href="track_edit.php?id=<?= $id ?>">Enter the hourly billing rate</a> first.</p>
  <?php endif; ?>
  <form method="post" action="track.php?id=<?= $id ?>#log-session-h" class="stacked" novalidate>
    <?= csrf_field() ?><?= web_idem_field() ?>
    <?php $logSumLabels = ['work_date' => 'Date', 'visit_rsa_category' => 'RSA service delivered this visit', 'visit_service_type' => 'Service type',
        'goal_id' => 'Goal this session advanced', 'hours' => 'Hours worked', 'mileage' => 'Mileage', 'description' => 'Activity description',
        'subject' => 'Note subject', 'note_content' => 'Note'];
    $logSumAnchors = ['goal_id' => 'log_goal', 'subject' => 'tn_subject', 'note_content' => 'tn_body'];
    foreach (array_keys($tnHomework ?? []) as $k) { $logSumLabels['tn_hw_due_' . $k] = 'Homework due date'; $logSumAnchors['tn_hw_due_' . $k] = 'thwd' . (int)$k; }
    echo form_error_summary($logSumLabels, $logSumAnchors); ?>
    <div class="field"><label for="work_date">Date <span aria-hidden="true">*</span><span class="visually-hidden"> (required)</span></label>
      <input type="date" id="work_date" name="work_date" accesskey="t" required min="2000-01-01" max="<?= date('Y-m-d') ?>" value="<?= h(old_in('work_date', date('Y-m-d'))) ?>" <?= field_aria('work_date') ?>><?= field_error('work_date') ?></div>
    <?php // Shown once the date was refused as outside the authorization (or a ticked box is being kept). ?>
    <?php $visitCats = track_visit_categories($track); ?>
    <div class="field"><label for="visit_rsa_category">RSA service delivered this visit <span class="hint"><?= count($visitCats) > 1 ? 'Choose which of this program\'s categories was delivered — it drives state reporting' : 'This track bills a single category' ?></span></label>
      <select id="visit_rsa_category" name="visit_rsa_category" <?= field_aria('visit_rsa_category') ?>><?= select_options($visitCats, old_in('visit_rsa_category') ?: ($visitCats[0] ?? null)) ?></select><?= field_error('visit_rsa_category') ?></div>
    <div class="field"><label for="visit_service_type">Service type <span class="hint">Internal discipline label</span></label>
      <select id="visit_service_type" name="visit_service_type" <?= field_aria('visit_service_type') ?>><?= select_options(SERVICE_TYPES, old_in('visit_service_type') ?: ($track['service_type'] ?? 'Assistive Technology')) ?></select><?= field_error('visit_service_type') ?></div>
    <?php // Also shown when a refused goal must be corrected, so the error has a field to point to.
    $openGoals = array_filter($goals, fn($g) => $g['status'] !== 'Achieved'); if ($openGoals || has_field_error('goal_id')): ?>
    <div class="field"><label for="log_goal">Goal this session advanced <span class="hint">optional — shows counselors hours invested per goal</span></label>
      <select id="log_goal" name="goal_id" <?= field_aria('goal_id') ?>><option value="">— None —</option>
        <?php foreach ($openGoals as $g): ?>
        <option value="<?= (int)$g['id'] ?>" <?= old_in('goal_id') === (string)$g['id'] ? 'selected' : '' ?>><?= h(mb_strimwidth($g['specific_goal'], 0, 90, '…')) ?></option>
        <?php endforeach; ?>
      </select><?= field_error('goal_id') ?></div>
    <?php endif; ?>
    <div class="field"><label for="hours">Hours worked</label>
      <span class="hint" id="hours-hint">Such as 1.5. Leave blank to save only the session note.</span>
      <input type="number" id="hours" name="hours" step="0.25" min="0.25" max="24" value="<?= h(old_in('hours')) ?>" <?= field_aria('hours', 'hours-hint') ?>><?= field_error('hours') ?></div>
    <div class="field"><label for="mileage">Mileage / travel (miles)</label>
      <span class="hint" id="mileage-hint">Miles driven for this session, up to 1000</span>
      <input type="text" inputmode="decimal" autocomplete="off" id="mileage" name="mileage" value="<?= h(old_in('mileage', '0')) ?>" <?= field_aria('mileage', 'mileage-hint') ?>><?= field_error('mileage') ?></div>
    <div class="field"><label for="description">Activity description</label>
      <span class="hint" id="description-hint">Needed with hours: it goes on the invoice.</span>
      <textarea id="description" name="description" rows="3" <?= field_aria('description', 'description-hint') ?>><?= h(old_in('description')) ?></textarea><?= field_error('description') ?></div>
    <?php // The session note, saved with the hours (or on its own when Hours worked is
    // blank): the same note the client's Add a note form takes, with the AI generator. ?>
    <h4 id="session-note-h">Session note</h4>
    <?php
    $tnCurFmt = $tnSections !== null ? $tnFormat : (old_in('note_format') ?: 'free');
    if (!isset(NOTE_FORMATS[$tnCurFmt])) $tnCurFmt = 'free';
    $tnSecVals = [];
    for ($i = 0; $i < 4; $i++) $tnSecVals[$i] = $tnSections[$i] ?? old_in('sec' . ($i + 1));
    $tnLabels = NOTE_FORMATS[$tnCurFmt === 'free' ? 'BIRP' : $tnCurFmt];
    ?>
    <div class="grid grid-2">
      <div class="field"><label for="tn_type">Note type</label>
        <select id="tn_type" name="note_type" accesskey="n"><?= select_options(NOTE_TYPES, old_in('note_type') ?: null) ?></select></div>
      <div class="field"><label for="tn_format">Note format</label>
        <select id="tn_format" name="note_format">
          <?php foreach (NOTE_FORMATS as $fk => $labs): ?>
          <option value="<?= h($fk) ?>" <?= $tnCurFmt === $fk ? 'selected' : '' ?>><?= $fk === 'free' ? 'Free text' : $fk . ' (' . implode(', ', $labs) . ')' ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div class="field"><label for="tn_subject">Note subject</label>
      <span class="hint" id="tn_subject-hint">Left blank, it is "Session on" and the date.</span>
      <input type="text" id="tn_subject" name="subject" maxlength="255" value="<?= h(old_in('subject', old_in('session_subject'))) ?>" <?= field_aria('subject', 'tn_subject-hint') ?>><?= field_error('subject') ?></div>
    <fieldset id="tn-struct" <?= $tnCurFmt === 'free' ? 'hidden' : '' ?>>
      <legend class="legend-h"><span id="tn-fmt-name"><?= h($tnCurFmt === 'free' ? 'BIRP' : $tnCurFmt) ?></span> <span class="hint">structured case-note sections</span></legend>
      <?php for ($i = 0; $i < 4; $i++): $n = $i + 1; ?>
      <div class="field"><label for="tn_sec<?= $n ?>" id="tn-lbl-sec<?= $n ?>"><?= h($tnLabels[$i]) ?></label>
        <textarea id="tn_sec<?= $n ?>" name="sec<?= $n ?>" rows="2"><?= h($tnSecVals[$i]) ?></textarea></div>
      <?php endfor; ?>
    </fieldset>
    <div class="field"><label for="tn_body">Note</label>
      <span class="hint" id="tn_body-hint">What happened, progress, concerns and the plan for next time. Or paste rough notes and use Generate structured note. Leave the note blank to log only the hours.</span>
      <textarea id="tn_body" name="body" rows="5" <?= field_aria('note_content', 'tn_body-hint') ?>><?= h(old_in('body', old_in('session_note'))) ?></textarea><?= field_error('note_content') ?></div>
    <?php if ($tnQuestions): ?>
    <div class="alert alert-success" role="note"><strong>Before you save — double-check:</strong>
      <ul style="margin:.3rem 0 0"><?php foreach ($tnQuestions as $q): ?><li><?= h($q) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($tnHomework): ?>
    <fieldset>
      <legend class="legend-h">Homework from this note <span class="hint">checked items are added to this client's portal when you save</span></legend>
      <?php foreach ($tnHomework as $k => $hw): $hwItem = '<span class="visually-hidden"> (homework item ' . ((int)$k + 1) . ')</span>'; ?>
      <div class="field" style="border-top:1px solid var(--line);padding-top:.5rem;margin-top:.5rem">
        <label><input type="checkbox" name="hw_include[<?= (int)$k ?>]" value="1" <?= ($hw['include'] ?? true) ? 'checked' : '' ?>> Add this homework<?= $hwItem ?></label>
        <label for="thwt<?= (int)$k ?>">Title<?= $hwItem ?></label>
        <input type="text" id="thwt<?= (int)$k ?>" name="hw_title[<?= (int)$k ?>]" maxlength="190" value="<?= h($hw['title']) ?>">
        <label for="thwi<?= (int)$k ?>">Instructions<?= $hwItem ?></label>
        <input type="text" id="thwi<?= (int)$k ?>" name="hw_instructions[<?= (int)$k ?>]" maxlength="1000" value="<?= h($hw['instructions']) ?>">
        <label for="thwd<?= (int)$k ?>">Due date (optional)<?= $hwItem ?></label>
        <input type="date" id="thwd<?= (int)$k ?>" name="hw_due[<?= (int)$k ?>]" value="<?= h($hw['due']) ?>" <?= field_aria('tn_hw_due_' . (int)$k) ?>><?= field_error('tn_hw_due_' . (int)$k) ?>
      </div>
      <?php endforeach; ?>
    </fieldset>
    <?php endif; ?>
    <button type="submit" name="add_log" value="1" class="btn btn-primary">Save session</button>
    <?php if (function_exists('ai_feature_enabled') && ai_feature_enabled('draft')): ?>
    <button type="submit" name="gen_track_note" value="1" class="btn btn-secondary" formnovalidate>Generate structured note</button>
    <?php endif; ?>
  </form>
  <script>
  (function(){
    var FMT = <?= json_encode(NOTE_FORMATS, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var sel = document.getElementById('tn_format'), fs = document.getElementById('tn-struct'), nm = document.getElementById('tn-fmt-name');
    function sync(){
      var f = sel.value, labels = FMT[f] && FMT[f].length ? FMT[f] : FMT.BIRP;
      fs.hidden = (f === 'free');
      if (nm) nm.textContent = (f === 'free' ? 'BIRP' : f);
      for (var i = 0; i < 4; i++){ var l = document.getElementById('tn-lbl-sec' + (i+1)); if (l) l.textContent = labels[i]; }
    }
    sel.addEventListener('change', sync); sync();
  })();
  </script>

  <h3>Log entries (<?= count($logs) ?>)</h3>
  <?php $unbilledTotal = round($r['unbilled_amount'] + $r['unbilled_expenses'], 2);
  // What Generate invoice bills now: unbilled entries dated today or earlier (as generate_invoice()).
  $billableTotal = round($r['billable_amount'] + $r['billable_expenses'], 2); ?>
  <p>On invoices so far, including drafts: <?= fmt_money($r['invoiced_amount']) ?> (<?= fmt_money($r['submitted_amount']) ?> submitted to the funder or paid). Not yet on an invoice: <?= fmt_money($unbilledTotal) ?><?= $r['unbilled_expenses'] > 0 ? ' (including ' . fmt_money($r['unbilled_expenses']) . ' of expenses)' : '' ?>.<?= $billableTotal < $unbilledTotal ? ' ' . fmt_money(round($unbilledTotal - $billableTotal, 2)) . ' of that is dated after today and is invoiced once its date has come.' : '' ?></p>
  <?php // Expenses or mileage alone are billable too, so any billable item offers the button. ?>
  <?php if (($r['billable_hours'] > 0 || $r['billable_mileage'] > 0 || $r['billable_expenses'] > 0) && can('billing') && track_mode($track) !== 'none'): ?>
  <form method="post" action="track.php?id=<?= $id ?>" class="inline-form">
    <?= csrf_field() ?>
    <button type="submit" name="mark_billed" value="1" class="btn btn-secondary btn-sm"
      onclick="return confirm('Create an invoice from all unbilled entries and expenses on this track dated today or earlier?');">
      Generate invoice for <?= fmt_money($billableTotal) ?> unbilled (<?= fmt_hours($r['billable_hours']) ?><?= $r['billable_expenses'] > 0 ? ' and ' . fmt_money($r['billable_expenses']) . ' of expenses' : '' ?>)</button>
  </form>
  <?php endif; ?>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="logs-cap">
  <table>
    <caption class="visually-hidden" id="logs-cap">Work log entries</caption>
    <thead><tr>
      <th scope="col">Date</th><th scope="col">Service</th><th scope="col" class="num">Hours</th><th scope="col" class="num">Mileage</th>
      <th scope="col">Activity</th><th scope="col" class="num">Amount</th><th scope="col">Billed?</th>
      <th scope="col">Logged by</th><th scope="col"><span class="visually-hidden">Actions</span></th>
    </tr></thead>
    <tbody>
    <?php if (!$logs): ?><tr><td colspan="9" class="muted">No hours logged yet.</td></tr><?php endif; ?>
    <?php foreach ($logs as $l): ?>
      <tr>
        <td><?= fmt_date($l['work_date']) ?></td>
        <td><?= h($l['rsa_category'] ?? $l['service_type'] ?? $track['service_type'] ?? '—') ?></td>
        <td class="num"><?= fmt_hours((float)$l['hours']) ?></td>
        <td class="num"><?= (float)$l['mileage'] > 0 ? h((string)(float)$l['mileage']) . ' mi' : '—' ?></td>
        <td><?= h($l['description']) ?></td>
        <td class="num"><?= fmt_money(log_line_amounts($l, $r['hourly_rate'], $r['mileage_rate'])['total']) ?></td>
        <td><?= $l['is_billed'] ? 'Yes' : 'No' ?><?php if (!empty($l['signature_path'])): ?><br>
            <a href="download.php?sig=<?= (int)$l['id'] ?>">Signed<span class="visually-hidden"> — view client signature<?= $l['signed_name'] ? ' by ' . h($l['signed_name']) : '' ?></span></a><?php elseif (($l['signed_name'] ?? '') !== ''): ?><br>
            Signed by typed name: <?= h($l['signed_name']) ?><?php endif; ?></td>
        <td><?= h($l['author'] ?? '') ?></td>
        <td>
          <?php if (!$l['is_billed']): ?>
          <form method="post" action="track.php?id=<?= $id ?>" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="log_id" value="<?= (int)$l['id'] ?>">
            <button type="submit" name="delete_log" value="1" class="btn btn-danger btn-sm"
              onclick="return confirm('Move this log entry from <?= fmt_date($l['work_date']) ?> to the trash? An administrator can restore it for 30 days.');">
              Move to trash<span class="visually-hidden"> — log entry from <?= fmt_date($l['work_date']) ?>, <?= fmt_hours((float)$l['hours']) ?>: <?= h(mb_strimwidth($l['description'], 0, 50, '…')) ?></span></button>
          </form>
          <?php else: ?><span class="muted">Invoiced</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section id="notes" class="card" aria-labelledby="tnotes-h">
  <h2 id="tnotes-h">Case notes for this track (<?= count($notes) ?>)</h2>
  <p class="muted">Write a note for this track under <a href="#log-session-h">Record a session</a>, with or without hours. General notes that aren't about a specific track belong on the client's <a href="client.php?id=<?= (int)$track['client_id'] ?>&amp;tab=notes">Client Notes</a> tab.</p>
  <?php if (!$notes): ?><p class="muted">No notes recorded for this track yet.</p><?php endif; ?>
  <?php $hwDraftOn = function_exists('ai_feature_enabled') && ai_feature_enabled('homework_draft');
  foreach ($notes as $tn):
    $nfmt = $tn['note_format'] ?? 'free'; if (!isset(NOTE_FORMATS[$nfmt])) $nfmt = 'free';
    $nCols = [$tn['birp_behavior'] ?? '', $tn['birp_intervention'] ?? '', $tn['birp_response'] ?? '', $tn['birp_plan'] ?? ''];
    $isStruct = $nfmt !== 'free' && implode('', $nCols) !== ''; ?>
    <article class="card">
      <h3><?= h($tn['subject']) ?></h3>
      <p class="muted"><?= h($tn['note_type']) ?> <span aria-hidden="true">·</span> <?= fmt_datetime($tn['created_at']) ?> <span aria-hidden="true">·</span> by <?= h($tn['author'] ?? 'System') ?>
        <?= $isStruct ? ' <span aria-hidden="true">·</span> <span class="badge badge-active">' . h($nfmt) . '</span>' : '' ?>
        <?= $tn['updated_at'] ? ' <span aria-hidden="true">·</span> edited ' . fmt_datetime($tn['updated_at']) : '' ?></p>
      <?php if ($isStruct): $labels = NOTE_FORMATS[$nfmt]; ?>
        <?php // The narrative saved after the sections (see assemble_note()) is shown too. ?>
        <dl class="props"><?php foreach ($nCols as $i => $v): if ($v === '') continue; ?><dt><?= h($labels[$i]) ?></dt><dd style="white-space:pre-wrap"><?= h($v) ?></dd><?php endforeach; ?>
          <?php $narr = note_narrative($tn); if ($narr !== ''): ?><dt>Narrative</dt><dd style="white-space:pre-wrap"><?= h($narr) ?></dd><?php endif; ?></dl>
      <?php else: ?>
        <p style="white-space:pre-wrap"><?= h($tn['body']) ?></p>
      <?php endif; ?>
      <?php if (note_editable($tn, (int)$user['id'])): ?>
        <p><a class="btn btn-secondary btn-sm" href="client.php?id=<?= (int)$track['client_id'] ?>&amp;tab=notes&amp;edit_note=<?= (int)$tn['id'] ?>#addnote-h">Edit<span class="visually-hidden"> note: <?= h($tn['subject']) ?></span></a></p>
      <?php elseif (note_locked($tn)): // past the edit window: corrections go in an addendum (earlier versions are on the client page) ?>
        <p><a class="btn btn-secondary btn-sm" href="client.php?id=<?= (int)$track['client_id'] ?>&amp;tab=notes&amp;addendum_to=<?= (int)$tn['id'] ?>#addnote-h">Add addendum<span class="visually-hidden"> to note: <?= h($tn['subject']) ?></span></a></p>
      <?php endif; ?>
      <?php if ($hwDraftOn): // Drafts homework from this note into the Assign homework form; nothing is saved until Assign. ?>
      <form method="post" action="track.php?id=<?= $id ?>#assign-hw-h" class="inline-form"><?= csrf_field() ?>
        <input type="hidden" name="note_id" value="<?= (int)$tn['id'] ?>">
        <button type="submit" name="draft_homework" value="1" class="btn btn-secondary btn-sm">Draft homework from this note<span class="visually-hidden">: <?= h($tn['subject']) ?></span></button>
      </form>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</section>

<section id="goals" class="card" aria-labelledby="goals-h">
  <h2 id="goals-h"><abbr title="Specific, Measurable, Attainable, Relevant, Time-bound">SMART</abbr> goals (<?= count($goals) ?>)</h2>
  <?php // A refused progress entry: the summary links to that goal row's percent field.
  if ($goals) {
      $gpKeys = array_map(fn($g) => 'goal_pct_' . (int)$g['id'], $goals);
      echo form_error_summary(array_fill_keys($gpKeys, 'Percent complete'), array_combine($gpKeys, array_map(fn($g) => 'pct-' . (int)$g['id'], $goals)));
  } ?>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="goals-cap">
  <table>
    <caption class="visually-hidden" id="goals-cap">SMART goals for this training track</caption>
    <thead><tr>
      <th scope="col">Specific objective</th><th scope="col">Measurable criteria</th>
      <th scope="col">Focus area</th><th scope="col" class="num">Hours logged</th><th scope="col">Target date</th>
      <th scope="col">Progress</th><th scope="col">Record progress</th>
    </tr></thead>
    <tbody>
    <?php if (!$goals): ?><tr><td colspan="7" class="muted">No goals yet — add the first one below.</td></tr><?php endif; ?>
    <?php foreach ($goals as $g): $gpct = (int)($g['progress_pct'] ?? 0); $hist = $progressHist[(int)$g['id']] ?? [];
      // Each row repeats the same controls, so their names carry the goal too.
      $gName = '<span class="visually-hidden"> for goal: ' . h(mb_strimwidth($g['specific_goal'], 0, 60, '…')) . '</span>';
      $gDraft = ($gpDraft && (int)$gpDraft['goal_id'] === (int)$g['id']) ? $gpDraft : null; ?>
      <tr>
        <th scope="row"><?= h($g['specific_goal']) ?></th>
        <td><?= h($g['measurable_metric']) ?></td>
        <td><?= h($g['focus_area'] ?? '—') ?></td>
        <td class="num"><?= (float)$g['goal_hours'] > 0 ? fmt_hours((float)$g['goal_hours']) : '—' ?></td>
        <td><?= fmt_date($g['target_date'], true) ?></td>
        <td>
          <?= status_badge($g['status']) ?>
          <div class="progress-wrap">
            <label class="visually-hidden" for="gp-<?= (int)$g['id'] ?>">Progress<?= $gName ?></label>
            <progress id="gp-<?= (int)$g['id'] ?>" class="hours" max="100" value="<?= $gpct ?>"><?= $gpct ?>%</progress>
            <span class="muted"><?= $gpct ?>%</span>
          </div>
          <?php if ($hist): ?>
          <details class="goal-history">
            <summary>History (<?= count($hist) ?>)<?= $gName ?></summary>
            <ul class="timeline">
              <?php foreach ($hist as $hrow): ?>
              <li><strong><?= (int)$hrow['progress_pct'] ?>%</strong> <span aria-hidden="true">·</span> <?= h($hrow['status']) ?>
                <span class="muted"><?= fmt_datetime($hrow['created_at']) ?><?= $hrow['by_name'] ? ' <span aria-hidden="true">·</span> ' . h($hrow['by_name']) : '' ?></span>
                <?= $hrow['note'] ? '<br>' . h($hrow['note']) : '' ?></li>
              <?php endforeach; ?>
            </ul>
          </details>
          <?php endif; ?>
        </td>
        <td>
          <?php // novalidate: any whole percent is valid (other screens store e.g. 33), and the
          // server explains a bad value next to the field instead of a browser bubble. ?>
          <form method="post" action="track.php?id=<?= $id ?>" class="stacked goal-progress-form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="goal_id" value="<?= (int)$g['id'] ?>">
            <?php if (!empty($gDraft['reopen_asked'])): ?><input type="hidden" name="reopen_confirmed" value="1"><?php endif; ?>
            <label for="gs-<?= (int)$g['id'] ?>">Status<?= $gName ?></label>
            <span class="hint" id="gs-<?= (int)$g['id'] ?>-hint">Achieved sets 100%. Not Started sets 0% unless you change the percent.</span>
            <select id="gs-<?= (int)$g['id'] ?>" name="goal_status" aria-describedby="gs-<?= (int)$g['id'] ?>-hint"><?= select_options(GOAL_STATUSES, $gDraft['status'] ?? $g['status']) ?></select>
            <label for="pct-<?= (int)$g['id'] ?>">Percent complete<?= $gName ?></label>
            <input type="number" id="pct-<?= (int)$g['id'] ?>" name="progress_pct" min="0" max="100" step="1" value="<?= h($gDraft['pct'] ?? (string)$gpct) ?>" inputmode="numeric" <?= field_aria('goal_pct_' . (int)$g['id']) ?>><?= field_error('goal_pct_' . (int)$g['id']) ?>
            <label for="pn-<?= (int)$g['id'] ?>">Note<?= $gName ?> <span class="hint">optional — what changed</span></label>
            <input type="text" id="pn-<?= (int)$g['id'] ?>" name="progress_note" maxlength="500" value="<?= h($gDraft['note'] ?? '') ?>">
            <button type="submit" name="update_goal" value="1" class="btn btn-secondary btn-sm">Record<span class="visually-hidden"> progress for goal: <?= h(mb_strimwidth($g['specific_goal'], 0, 60, '…')) ?></span></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <?php
  // Prefill when a template was chosen (Prefill button), but not when the user
  // only ran a search (the Search button carries tpl_do=search).
  $goalTpl = null;
  if (($_GET['tpl_do'] ?? '') !== 'search' && !empty($_GET['goal_tpl'])) {
      $gt = db()->prepare('SELECT * FROM goal_templates WHERE id = ?');
      $gt->execute([(int)$_GET['goal_tpl']]);
      $goalTpl = $gt->fetch();
  }
  $goalCats = db()->query('SELECT category, COUNT(*) n FROM goal_templates GROUP BY category ORDER BY category')->fetchAll();
  $goalTplTotal = 0; foreach ($goalCats as $gc) $goalTplTotal += (int)$gc['n'];
  $tplQ = trim((string)($_GET['tpl_q'] ?? ''));
  $tplCat = trim((string)($_GET['tpl_cat'] ?? ''));
  $tplResults = search_goal_templates($tplQ, $tplCat, 50);
  // Prefill pressed before a template was chosen: say so at the list instead of
  // reloading to an empty form with no message.
  $tplMissing = ($_GET['tpl_do'] ?? '') === 'prefill' && empty($_GET['goal_tpl']);
  // Open after a template search or prefill, a refused goal, or a restored draft.
  $goalOpen = isset($_GET['tpl_do']) || isset($_GET['tpl_q']) || isset($_GET['tpl_cat']) || $goalTpl
      || array_intersect_key(form_errors(), array_flip(['specific_goal', 'measurable_metric', 'focus_area', 'target_date']))
      || array_filter(['specific_goal', 'measurable_metric', 'focus_area', 'target_date'], fn($k) => trim(old_in($k)) !== '');
  ?>
  <h3 class="visually-hidden">Add a SMART goal</h3>
  <details id="add-goal-h"<?= $goalOpen ? ' open' : '' ?>>
  <summary>Add a <abbr title="Specific, Measurable, Attainable, Relevant, Time-bound">SMART</abbr> goal</summary>
  <?php if ($goalTplTotal): ?>
  <?php // Search returns to the picker; Prefill lands on the goal form it filled in. ?>
  <form method="get" action="track.php#tpl-picker" class="stacked template-picker" aria-label="Start from a goal template" id="tpl-picker">
    <input type="hidden" name="id" value="<?= $id ?>">
    <p class="hint" id="tpl-help">Search <?= $goalTplTotal ?> goal templates across <?= count($goalCats) ?> areas by tool, task, or keyword, then prefill the form below — you can still edit everything before saving.</p>
    <div class="grid grid-2">
      <div class="field">
        <label for="tpl-cat">Area</label>
        <select id="tpl-cat" name="tpl_cat">
          <option value="">All areas (<?= $goalTplTotal ?>)</option>
          <?php foreach ($goalCats as $gc): ?>
          <option value="<?= h($gc['category']) ?>" <?= $tplCat === $gc['category'] ? 'selected' : '' ?>><?= h($gc['category']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="tpl-search">Search</label>
        <input type="search" id="tpl-search" name="tpl_q" value="<?= h($tplQ) ?>" placeholder="e.g. JAWS email, cane travel, Excel" autocomplete="off">
      </div>
    </div>
    <div class="field">
      <label for="goal_tpl">Matching templates</label>
      <select id="goal_tpl" name="goal_tpl" size="8" aria-describedby="tpl-help tpl-preview<?= $tplMissing ? ' tpl-err' : '' ?>"<?= $tplMissing ? ' aria-invalid="true" autofocus' : '' ?>>
        <?php // Grouped by area: each option is the goal alone; the area is read once per group.
              $tplGroup = null; foreach ($tplResults as $g):
              if ($g['category'] !== $tplGroup): if ($tplGroup !== null) echo '</optgroup>'; $tplGroup = $g['category']; ?>
        <optgroup label="<?= h($tplGroup) ?>">
        <?php endif; ?>
        <option value="<?= (int)$g['id'] ?>" data-full="<?= h($g['category'] . ' — ' . $g['specific_goal']) ?>"><?= h(mb_strimwidth($g['specific_goal'], 0, 90, '…')) ?></option>
        <?php endforeach; if ($tplGroup !== null) echo '</optgroup>'; ?>
      </select>
      <?php if ($tplMissing): ?><p class="field-error" id="tpl-err">Choose a template in Matching templates first, then press Prefill form.</p><?php endif; ?>
      <?php // Options do not wrap, so the highlighted template's full text is shown here. ?>
      <p class="hint" id="tpl-preview" hidden></p>
      <?php // Outside the label, and only filled after the user searches, so nothing is announced on page load. ?>
      <p class="hint" id="tpl-count" role="status" aria-live="polite"></p>
    </div>
    <div class="page-actions">
      <button type="submit" class="btn btn-secondary btn-sm" name="tpl_do" value="search" id="tpl-search-btn">Search</button>
      <button type="submit" class="btn btn-secondary btn-sm" name="tpl_do" value="prefill" id="tpl-prefill" formaction="track.php#add-goal-h">Prefill form</button>
      <?php if (can('templates')): ?><a class="muted" href="templates.php">Manage templates</a><?php endif; ?>
    </div>
  </form>
  <script>
  (function(){
    var form=document.getElementById('tpl-picker'); if(!form)return;
    var cat=document.getElementById('tpl-cat'), search=document.getElementById('tpl-search'),
        sel=document.getElementById('goal_tpl'), count=document.getElementById('tpl-count'),
        searchBtn=document.getElementById('tpl-search-btn'), preview=document.getElementById('tpl-preview');
    var tid=<?= (int)$id ?>, timer=null, seq=0;
    if(searchBtn)searchBtn.hidden=true;   // live search replaces the no-JS Search button
    function showPreview(){
      var o=sel.options[sel.selectedIndex];
      preview.textContent=o?'Selected: '+(o.getAttribute('data-full')||o.textContent):'';
      preview.hidden=!o;
    }
    sel.addEventListener('change',showPreview);
    function render(list){
      sel.innerHTML='';
      // Grouped by area, so each option reads as the goal alone (the group name is
      // announced once, on entering it) instead of "Area — goal" fifty times over.
      var groups={};
      list.forEach(function(g){
        var grp=groups[g.category];
        if(!grp){grp=document.createElement('optgroup');grp.label=g.category;groups[g.category]=grp;sel.appendChild(grp);}
        var o=document.createElement('option');
        o.value=g.id; o.textContent=g.specific_goal;
        o.setAttribute('data-full',g.category+' — '+g.specific_goal);
        grp.appendChild(o);
      });
      count.textContent=list.length?(list.length+(list.length>=50?'+ shown — refine to narrow':' shown')):'No matches — try different words';
      if(list.length)sel.selectedIndex=0;
      showPreview();
    }
    function query(){
      // Only the newest query may update the list and its announced count.
      var mine=++seq;
      var url='track.php?tpl_search_json=1&id='+tid+'&tpl_cat='+encodeURIComponent(cat.value)+'&tpl_q='+encodeURIComponent(search.value.trim());
      fetch(url,{credentials:'same-origin'}).then(function(r){if(!r.ok)throw new Error('http');return r.json();})
        .then(function(list){if(mine!==seq)return;if(!Array.isArray(list))throw new Error('shape');render(list);})
        .catch(function(){if(mine!==seq)return;count.textContent='Search failed — check your connection or reload the page, then try again.';});
    }
    function schedule(){clearTimeout(timer);timer=setTimeout(query,250);}
    search.addEventListener('input',schedule);
    // A native select fires a change for every arrow press: search (and announce the
    // count) only once the choice has settled, not 334 times on the way through the areas.
    cat.addEventListener('change',function(){clearTimeout(timer);timer=setTimeout(query,700);});
    cat.addEventListener('blur',function(){if(timer){clearTimeout(timer);timer=null;query();}});
    search.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();query();}});
    // No query on load: the server already listed the first matches.
  })();
  </script>
  <?php endif; ?>
  <form method="post" action="track.php?id=<?= $id ?>" class="stacked" novalidate>
    <?= csrf_field() ?><?= web_idem_field() ?>
    <?= form_error_summary(['specific_goal' => 'Specific objective', 'measurable_metric' => 'Measurable criteria', 'focus_area' => 'Relevant focus area', 'target_date' => 'Target completion date']) ?>
    <div class="field"><label for="specific_goal">Specific objective <span aria-hidden="true">*</span><span class="visually-hidden"> (required)</span></label>
      <textarea id="specific_goal" name="specific_goal" required rows="2" <?= field_aria('specific_goal') ?><?= $goalTpl ? ' autofocus' : '' ?>><?= h($goalTpl['specific_goal'] ?? old_in('specific_goal')) ?></textarea><?= field_error('specific_goal') ?></div>
    <div class="field"><label for="measurable_metric">Measurable criteria <span aria-hidden="true">*</span><span class="visually-hidden"> (required)</span></label>
      <span class="hint" id="measurable_metric-hint">e.g., “Student will edit a Word document at 30 WPM using keyboard shortcuts.”</span>
      <textarea id="measurable_metric" name="measurable_metric" required rows="2" <?= field_aria('measurable_metric', 'measurable_metric-hint') ?>><?= h($goalTpl['measurable_metric'] ?? old_in('measurable_metric')) ?></textarea><?= field_error('measurable_metric') ?></div>
    <div class="field"><label for="focus_area">Relevant focus area</label>
      <input type="text" id="focus_area" name="focus_area" maxlength="190" value="<?= h(old_in('focus_area', $goalTpl['focus_area'] ?? '')) ?>" <?= field_aria('focus_area') ?>><?= field_error('focus_area') ?></div>
    <div class="field"><label for="target_date">Target completion date</label>
      <input type="date" id="target_date" name="target_date" value="<?= h(old_in('target_date')) ?>" <?= field_aria('target_date') ?>><?= field_error('target_date') ?></div>
    <div class="field"><label for="goal_status">Status</label>
      <select id="goal_status" name="goal_status"><?= select_options(GOAL_STATUSES, old_in('goal_status', 'Not Started')) ?></select></div>
    <button type="submit" name="add_goal" value="1" class="btn btn-primary">Add goal</button>
  </form>
  </details>
  <?= details_open_on_hash('add-goal-h') ?>
</section>

<?php
$hw = db()->prepare('SELECT * FROM homework WHERE track_id = ? ORDER BY status, due_date IS NULL, due_date');
$hw->execute([$id]); $hw = $hw->fetchAll();
$expenses = db()->prepare('SELECT * FROM track_expenses WHERE track_id = ? ORDER BY expense_date DESC');
$expenses->execute([$id]); $expenses = $expenses->fetchAll();
?>
<section id="homework" class="card" aria-labelledby="hw-h">
  <h2 id="hw-h">Homework (<?= count($hw) ?>)</h2>
  <p class="muted">Assignments show on the client's portal page until completed.</p>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="hw-cap">
  <table>
    <caption class="visually-hidden" id="hw-cap">Homework assignments for this training track</caption>
    <thead><tr><th scope="col">Assignment</th><th scope="col">Assigned</th><th scope="col">Due</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php if (!$hw): ?><tr><td colspan="5" class="muted">No homework assigned yet.</td></tr><?php endif; ?>
    <?php foreach ($hw as $h_): ?>
      <tr>
        <td><?= h($h_['title']) ?><?= $h_['instructions'] ? '<br><span class="muted">' . h($h_['instructions']) . '</span>' : '' ?></td>
        <td><?= fmt_date($h_['assigned_at']) ?></td>
        <td><?= fmt_date($h_['due_date'], true) ?></td>
        <td><?= status_badge($h_['status']) ?>
            <?= (!empty($h_['client_reported_at']) && $h_['status'] === 'Assigned') ? '<br><span class="badge badge-active">Client reported done ' . fmt_date($h_['client_reported_at']) . '</span>' : '' ?></td>
        <td>
          <a href="homework_edit.php?id=<?= (int)$h_['id'] ?>">Edit / revise with AI<span class="visually-hidden"> — <?= h($h_['title']) ?></span></a>
          <?php if ($h_['status'] === 'Assigned'): ?>
          <form method="post" action="track.php?id=<?= $id ?>" class="inline-form"><?= csrf_field() ?>
            <input type="hidden" name="hw_id" value="<?= (int)$h_['id'] ?>">
            <button type="submit" name="hw_done" value="1" class="btn btn-secondary btn-sm"
              onclick="return confirm('Mark this homework completed? It disappears from the client portal.');">Mark completed<span class="visually-hidden"> — <?= h($h_['title']) ?></span></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <h3 id="assign-hw-h">Assign homework</h3>
  <?php if ($hwDraft): // The new draft is announced by moving focus to the notice, as the generated note is. ?>
  <div class="alert alert-success" role="note" id="hw-draft-note" tabindex="-1">Draft from the note "<?= h($hwDraft['subject']) ?>": review and change it before assigning.<?= $hwDraft['due_days'] > 0 ? ' The AI suggests it is due in ' . (int)$hwDraft['due_days'] . ' day' . ($hwDraft['due_days'] === 1 ? '' : 's') . '.' : '' ?> Nothing is saved until you choose Assign.</div>
  <script>document.addEventListener("DOMContentLoaded",function(){var d=document.getElementById("hw-draft-note");if(d){d.focus();if(window.Earcon)Earcon.play("success");}});</script>
  <?php endif; ?>
  <form method="post" action="track.php?id=<?= $id ?>" class="stacked" novalidate>
    <?= csrf_field() ?><?= web_idem_field() ?>
    <?= form_error_summary(['hw_title' => 'Title', 'hw_instructions' => 'Instructions', 'hw_due' => 'Due date']) ?>
    <div class="field"><label for="hw_title">Title <span aria-hidden="true">*</span><span class="visually-hidden"> (required)</span></label>
      <input type="text" id="hw_title" name="hw_title" required maxlength="190" value="<?= h(old_in('hw_title')) ?>" <?= field_aria('hw_title') ?>><?= field_error('hw_title') ?></div>
    <div class="field"><label for="hw_instructions">Instructions</label>
      <textarea id="hw_instructions" name="hw_instructions" rows="2" <?= field_aria('hw_instructions') ?>><?= h(old_in('hw_instructions')) ?></textarea><?= field_error('hw_instructions') ?></div>
    <div class="field"><label for="hw_due">Due date</label>
      <input type="date" id="hw_due" name="hw_due" value="<?= h(old_in('hw_due')) ?>" <?= field_aria('hw_due') ?>><?= field_error('hw_due') ?></div>
    <?php at_ai_controls('homework',[at_field('instructions','Instructions','textarea'),at_field('title','Title')],['track_id'=>(string)$id],['title'=>'hw_title','instructions'=>'hw_instructions']); ?>
    <button type="submit" name="add_homework" value="1" class="btn btn-primary">Assign</button>
  </form>
</section>

<section id="expenses" class="card" aria-labelledby="exp-h">
  <h2 id="exp-h">Billable expenses (<?= count($expenses) ?>)</h2>
  <p class="muted">Equipment, licenses, or other items billed to this authorization — included on the next generated invoice. An expense entered by mistake can be deleted until it is on an invoice.</p>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="exp-cap">
  <table>
    <caption class="visually-hidden" id="exp-cap">Billable expenses for this training track</caption>
    <thead><tr><th scope="col">Date</th><th scope="col">Description</th><th scope="col" class="num">Amount</th><th scope="col">Billed?</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php if (!$expenses): ?><tr><td colspan="5" class="muted">No expenses logged.</td></tr><?php endif; ?>
    <?php foreach ($expenses as $e_): ?>
      <tr>
        <td><?= fmt_date($e_['expense_date']) ?></td>
        <td><?= h($e_['description']) ?></td>
        <td class="num"><?= fmt_money((float)$e_['amount']) ?></td>
        <td><?= $e_['is_billed'] ? 'Yes' : 'No' ?></td>
        <td>
          <?php if (!$e_['is_billed']): ?>
          <form method="post" action="track.php?id=<?= $id ?>" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="expense_id" value="<?= (int)$e_['id'] ?>">
            <button type="submit" name="delete_expense" value="1" class="btn btn-danger btn-sm"
              onclick="return confirm('Move this <?= h(fmt_money((float)$e_['amount'])) ?> expense from <?= fmt_date($e_['expense_date']) ?> to the trash? An administrator can restore it for 30 days.');">
              Move to trash<span class="visually-hidden"> — expense from <?= fmt_date($e_['expense_date']) ?>, <?= fmt_money((float)$e_['amount']) ?>: <?= h(mb_strimwidth($e_['description'], 0, 50, '…')) ?></span></button>
          </form>
          <?php else: ?><span class="muted">Invoiced</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <h3>Log an expense</h3>
  <form method="post" action="track.php?id=<?= $id ?>" class="stacked" novalidate>
    <?= csrf_field() ?><?= web_idem_field() ?>
    <?= form_error_summary(['exp_date' => 'Date', 'exp_description' => 'Description', 'exp_amount' => 'Amount']) ?>
    <div class="field"><label for="exp_date">Date</label>
      <input type="date" id="exp_date" name="exp_date" min="2000-01-01" max="<?= date('Y-m-d') ?>" value="<?= h(old_in('exp_date', date('Y-m-d'))) ?>" <?= field_aria('exp_date') ?>><?= field_error('exp_date') ?></div>
    <?php // Shown once the date was refused as outside the authorization (or a ticked box is being kept). ?>
    <div class="field"><label for="exp_description">Description <span aria-hidden="true">*</span><span class="visually-hidden"> (required)</span></label>
      <input type="text" id="exp_description" name="exp_description" required maxlength="255" value="<?= h(old_in('exp_description')) ?>" <?= field_aria('exp_description') ?>><?= field_error('exp_description') ?></div>
    <div class="field"><label for="exp_amount">Amount (USD) <span aria-hidden="true">*</span><span class="visually-hidden"> (required)</span></label>
      <input type="number" id="exp_amount" name="exp_amount" step="0.01" min="0.01" required value="<?= h(old_in('exp_amount')) ?>" <?= field_aria('exp_amount') ?>><?= field_error('exp_amount') ?></div>
    <button type="submit" name="add_expense" value="1" class="btn btn-primary">Log expense</button>
  </form>
</section>
<?php page_footer();
