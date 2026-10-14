<?php
// Pre-ETS & group programs: manage cohorts of Pre-ETS students, log group
// sessions against the five required Pre-ETS activities with per-student
// attendance, and report each student's hours by activity for the school /
// state. Gated by the 'programs' permission.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('programs');

// The five federally required Pre-ETS activities (Section 113), plus Other.
const PREETS_ACTIVITIES = [
    'Job Exploration Counseling',
    'Work-Based Learning',
    'Postsecondary Counseling',
    'Workplace Readiness',
    'Self-Advocacy Instruction',
    'Other',
];

function cohort_load(int $id): ?array {
    global $user;
    if (!cohort_accessible($id, (int)$user['id'])) return null;
    $s = db()->prepare('SELECT co.*, u.name AS coordinator FROM cohorts co LEFT JOIN users u ON u.id = co.coordinator_id WHERE co.id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

/* ---- Create a cohort ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_cohort'])) {
    $name = trim($_POST['name'] ?? '');
    // Both dates are optional, but a typed one is checked, never dropped: the same
    // year range as the app's cohort dates (api_date), 2000 to five years ahead.
    $maxYear = (int)date('Y') + 5;
    $startIn = is_string($_POST['start_date'] ?? null) ? trim($_POST['start_date']) : '';
    $endIn = is_string($_POST['end_date'] ?? null) ? trim($_POST['end_date']) : '';
    $start = valid_date($startIn, 2000, $maxYear);
    $end = valid_date($endIn, 2000, $maxYear);
    $errors = [];
    if ($name === '') $errors['name'] = 'Cohort name is required.';
    if ($startIn !== '' && $start === null) $errors['start_date'] = 'Enter the start date as a real date with a year from 2000 to ' . $maxYear . ' (for example 2026-09-15), or leave it blank.';
    if ($endIn !== '' && $end === null) $errors['end_date'] = 'Enter the end date as a real date with a year from 2000 to ' . $maxYear . ' (for example 2026-09-15), or leave it blank.';
    elseif ($start !== null && $end !== null && $end < $start) $errors['end_date'] = 'The end date cannot be before the start date.';
    if ($errors) { keep_input(); keep_field_errors($errors); redirect('programs.php'); }
    db()->prepare('INSERT INTO cohorts (name, school, coordinator_id, start_date, end_date, notes) VALUES (?,?,?,?,?,?)')
        ->execute([mb_substr($name, 0, 190), mb_substr(trim($_POST['school'] ?? ''), 0, 190) ?: null,
            (int)($_POST['coordinator_id'] ?? 0) ?: null, $start, $end,
            mb_substr(trim($_POST['notes'] ?? ''), 0, 2000) ?: null]);
    $cid = (int)db()->lastInsertId();
    audit($user['id'], 'cohort.add', 'cohort', $cid, $name);
    flash('Cohort created.');
    redirect('programs.php?id=' . $cid);
}

/* ---- Cohort-scoped actions ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cohort_id'])) {
    $cid = (int)$_POST['cohort_id'];
    $co = cohort_load($cid);
    if (!$co) { flash('Cohort not found.', 'error'); redirect('programs.php'); }
    // A session deleted from the full list returns to the full list.
    $back = 'programs.php?id=' . $cid . (!empty($_POST['all_sessions']) ? '&all_sessions=1#sess-h' : '');

    if (isset($_POST['add_member'])) {
        $clientId = (int)($_POST['client_id'] ?? 0);
        scope_check_client_id($clientId, $user);
        $ok = db()->prepare("SELECT id FROM clients WHERE id = ? AND client_type = 'Pre-ETS'");
        $ok->execute([$clientId]);
        if ($ok->fetchColumn()) {
            // A student who left the cohort earlier rejoins on the same membership row.
            db()->prepare('INSERT INTO cohort_members (cohort_id, client_id) VALUES (?,?) ON DUPLICATE KEY UPDATE left_at = NULL')->execute([$cid, $clientId]);
            audit($user['id'], 'cohort.member_add', 'cohort', $cid, 'client #' . $clientId);
            flash('Student added to the cohort.');
        } else {
            keep_field_errors(['add_client' => 'Choose a Pre-ETS student.']);
        }
    } elseif (isset($_POST['remove_member'])) {
        $clientId = (int)($_POST['client_id'] ?? 0);
        scope_check_client_id($clientId, $user);
        // The membership is marked as left, not deleted: the sessions the student
        // attended stay in the hours report and the export.
        $left = db()->prepare('UPDATE cohort_members SET left_at = NOW() WHERE cohort_id = ? AND client_id = ? AND left_at IS NULL');
        $left->execute([$cid, $clientId]);
        if ($left->rowCount() === 1) {
            audit($user['id'], 'cohort.member_remove', 'cohort', $cid, 'client #' . $clientId);
            flash('Student removed from the cohort. Hours from sessions they attended stay in the report.');
        } else {
            flash('That student is no longer in this cohort, so nothing changed.', 'error');
        }
    } elseif (isset($_POST['log_session'])) {
        // Checked, never defaulted: a blank date, a zero duration or an unknown
        // activity would file hours under the wrong day or activity without a word.
        $activity = is_string($_POST['activity'] ?? null) ? $_POST['activity'] : '';
        $dateIn = is_string($_POST['session_date'] ?? null) ? trim($_POST['session_date']) : '';
        // The same year range as the app's log_cohort_session (api_date): a year typed
        // as 26 (0026) or 2062 would file the hours outside every reporting year.
        $maxYear = (int)date('Y') + 5;
        $date = valid_date($dateIn, 2000, $maxYear);
        $durIn = is_string($_POST['duration_min'] ?? null) ? trim($_POST['duration_min']) : '';
        $dur = ctype_digit($durIn) ? (int)$durIn : 0;
        $errors = [];
        if (!in_array($activity, PREETS_ACTIVITIES, true)) $errors['activity'] = 'Choose a Pre-ETS activity from the list.';
        if ($dateIn === '') $errors['session_date'] = 'Enter the date of the session.';
        elseif ($date === null) $errors['session_date'] = 'Enter the session date as a real date with a year from 2000 to ' . $maxYear . ' (for example 2026-09-15).';
        // Attendance counts in the hours report at once, so a session is logged once it
        // has taken place, as work logs are (a future date is usually a mistyped year).
        elseif ($date > date('Y-m-d')) $errors['session_date'] = 'The date ' . fmt_date($date) . ' is in the future. Log a session once it has taken place, with today\'s date or an earlier one.';
        if ($dur < 1 || $dur > 1440) $errors['duration_min'] = 'Enter the duration as whole minutes, from 1 to 1440.';
        if ($errors) {
            keep_input();
            // keep_input() keeps text fields only: the attendance ticks are kept separately.
            $_SESSION['log_present'] = is_array($_POST['present'] ?? null) ? array_filter($_POST['present'], 'is_string') : [];
            keep_field_errors($errors);
            redirect($back);
        }
        $topic = mb_substr(trim($_POST['topic'] ?? ''), 0, 190) ?: null;
        $sessNotes = mb_substr(trim($_POST['notes'] ?? ''), 0, 2000) ?: null;
        db()->beginTransaction();
        try {
            // The cohort row is locked first, so a repeated submit (Log session pressed
            // again while the first was saving) finds the session it already logged and
            // adds nothing: the hours would otherwise count twice in the report.
            db()->prepare('SELECT id FROM cohorts WHERE id = ? FOR UPDATE')->execute([$cid]);
            $dup = db()->prepare('SELECT id FROM cohort_sessions WHERE cohort_id = ? AND session_date = ? AND activity = ? AND duration_min = ?
                AND staff_id <=> ? AND topic <=> ? AND notes <=> ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE) LIMIT 1');
            $dup->execute([$cid, $date, $activity, $dur, $user['id'], $topic, $sessNotes]);
            if ($dup->fetchColumn()) {
                db()->rollBack();
                flash('That session (' . $activity . ', ' . fmt_date($date) . ', ' . $dur . ' minutes) was already logged a moment ago, so it was not added again. It is under Recent sessions.', 'info');
                redirect($back);
            }
            db()->prepare('INSERT INTO cohort_sessions (cohort_id, session_date, activity, topic, duration_min, staff_id, notes) VALUES (?,?,?,?,?,?,?)')
                ->execute([$cid, $date, $activity, $topic, $dur, $user['id'], $sessNotes]);
            $sid = (int)db()->lastInsertId();
            // Attendance: a checked box per present student.
            $present = $_POST['present'] ?? [];
            $mem = db()->prepare('SELECT client_id FROM cohort_members WHERE cohort_id = ? AND left_at IS NULL');
            $mem->execute([$cid]);
            $ins = db()->prepare('INSERT INTO cohort_attendance (session_id, client_id, present) VALUES (?,?,?)');
            foreach ($mem->fetchAll() as $m) {
                $cidm = (int)$m['client_id'];
                $ins->execute([$sid, $cidm, isset($present[$cidm]) ? 1 : 0]);
            }
            db()->commit();
        } catch (Throwable $e) { db()->rollBack(); throw $e; }
        audit($user['id'], 'cohort.session', 'cohort', $cid, $activity . ' ' . $date);
        flash('Session logged with attendance: ' . $activity . ', ' . fmt_date($date) . ', ' . $dur . ' minutes. If anything is wrong, delete it under Recent sessions and log it again.');
    } elseif (isset($_POST['delete_session'])) {
        // A session logged by mistake (wrong activity, date, minutes or attendance)
        // can be taken back: it and its attendance are removed together, and the
        // audit entry keeps what was removed. It can then be logged again correctly.
        $sid = (int)($_POST['session_id'] ?? 0);
        db()->beginTransaction();
        try {
            $sq = db()->prepare('SELECT id, session_date, activity, duration_min FROM cohort_sessions WHERE id = ? AND cohort_id = ? FOR UPDATE');
            $sq->execute([$sid, $cid]);
            $sess = $sq->fetch();
            if ($sess) {
                $pq = db()->prepare('SELECT client_id FROM cohort_attendance WHERE session_id = ? AND present = 1 ORDER BY client_id');
                $pq->execute([$sid]);
                $presentIds = array_map('intval', $pq->fetchAll(PDO::FETCH_COLUMN));
                db()->prepare('DELETE FROM cohort_attendance WHERE session_id = ?')->execute([$sid]);
                db()->prepare('DELETE FROM cohort_sessions WHERE id = ?')->execute([$sid]);
            }
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }
        if ($sess) {
            audit($user['id'], 'cohort.session_delete', 'cohort', $cid, 'session #' . $sid . ': ' . $sess['activity'] . ' ' . $sess['session_date'] . ', '
                . (int)$sess['duration_min'] . ' min; present: ' . ($presentIds ? 'client #' . implode(', #', $presentIds) : 'nobody'));
            flash('Session deleted: ' . $sess['activity'] . ', ' . fmt_date($sess['session_date']) . '. Its hours are no longer in the report. Log it again below if it took place.');
        } else {
            flash('That session was already deleted, so nothing changed.', 'error');
        }
    } elseif (isset($_POST['archive_cohort'])) {
        // The form posts the state its button named, and the update only changes a
        // cohort not already in that state: a double submit or a stale page can never
        // do the opposite of what the button said, and racing submits audit it once.
        $to = ($_POST['to'] ?? '') === '1' ? 1 : (($_POST['to'] ?? '') === '0' ? 0 : null);
        if ($to === null) {
            flash('Choose whether to archive or reactivate the cohort.', 'error');
        } else {
            $upd = db()->prepare('UPDATE cohorts SET active = ? WHERE id = ? AND active <> ?');
            $upd->execute([$to, $cid, $to]);
            if ($upd->rowCount() === 1) {
                audit($user['id'], $to ? 'cohort.reactivate' : 'cohort.archive', 'cohort', $cid, $co['name']);
                flash($to ? 'Cohort reactivated.' : 'Cohort archived.');
            } else {
                flash($to ? 'That cohort is already active.' : 'That cohort is already archived.');
            }
        }
    }
    redirect($back);
}

/* ---- Per-student hours-by-activity (shared by detail view and CSV) ---- */
// Current members, plus anyone who left after attending: hours already delivered
// stay reported. 'left' marks a student who is no longer in the cohort.
function cohort_matrix(int $cid): array {
    $rows = db()->prepare("SELECT p.client_id, c.name, s.activity, COALESCE(SUM(IF(a.present = 1, s.duration_min, 0)),0) AS mins,
            MAX(cm.id IS NULL OR cm.left_at IS NOT NULL) AS has_left
        FROM (SELECT client_id FROM cohort_members WHERE cohort_id = ? AND left_at IS NULL
              UNION SELECT a0.client_id FROM cohort_attendance a0 JOIN cohort_sessions s0 ON s0.id = a0.session_id
              WHERE s0.cohort_id = ? AND a0.present = 1) p
        JOIN clients c ON c.id = p.client_id
        LEFT JOIN cohort_members cm ON cm.cohort_id = ? AND cm.client_id = p.client_id
        LEFT JOIN cohort_attendance a ON a.client_id = p.client_id
        LEFT JOIN cohort_sessions s ON s.id = a.session_id AND s.cohort_id = ?
        GROUP BY p.client_id, c.name, s.activity ORDER BY c.name");
    $rows->execute([$cid, $cid, $cid, $cid]);
    $byStudent = [];
    foreach ($rows->fetchAll() as $r) {
        $byStudent[(int)$r['client_id']]['name'] = $r['name'];
        $byStudent[(int)$r['client_id']]['left'] = !empty($byStudent[(int)$r['client_id']]['left']) || (int)$r['has_left'] === 1;
        if ($r['activity'] !== null) $byStudent[(int)$r['client_id']]['acts'][$r['activity']] = (int)$r['mins'];
    }
    return $byStudent;
}

/* ---- CSV export of the hours-by-activity matrix ---- */
if (isset($_GET['export'])) {
    $co = cohort_load((int)$_GET['export']);
    if (!$co) { flash('Cohort not found.', 'error'); redirect('programs.php'); }
    $matrix = cohort_matrix((int)$co['id']);
    audit($user['id'], 'cohort.export', 'cohort', (int)$co['id'], 'Pre-ETS cohort CSV (' . count($matrix) . ' students)');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="preets_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $co['name']) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    write_csv_row($out, ['PRE-ETS HOURS BY ACTIVITY — ' . csv_cell($co['name']) . ($co['school'] ? ' / ' . csv_cell($co['school']) : '')]);
    write_csv_row($out, ['Generated', date('Y-m-d'), 'By', csv_cell($user['name'])]);
    write_csv_row($out, []);
    $header = array_merge(['Student'], PREETS_ACTIVITIES, ['Total hours']);
    write_csv_row($out, $header);
    foreach ($matrix as $stu) {
        $row = [csv_cell($stu['name'] . ($stu['left'] ? ' (left the cohort)' : ''))]; $total = 0;
        foreach (PREETS_ACTIVITIES as $act) { $m = $stu['acts'][$act] ?? 0; $total += $m; $row[] = number_format($m / 60, 2); }
        $row[] = number_format($total / 60, 2);
        write_csv_row($out, $row);
    }
    fclose($out);
    exit;
}

/* ================= Cohort detail ================= */
if (isset($_GET['id'])) {
    $co = cohort_load((int)$_GET['id']);
    if (!$co) { flash('Cohort not found.', 'error'); redirect('programs.php'); }
    $cid = (int)$co['id'];
    $members = db()->prepare('SELECT c.id, c.name, c.school FROM cohort_members cm JOIN clients c ON c.id = cm.client_id WHERE cm.cohort_id = ? AND cm.left_at IS NULL ORDER BY c.name');
    $members->execute([$cid]);
    $members = $members->fetchAll();
    // The session list is the only place a wrong entry can be deleted, so every
    // session can be listed: the 50 most recent by default, all on request.
    $allSessions = !empty($_GET['all_sessions']);
    $sessionTotal = db()->prepare('SELECT COUNT(*) FROM cohort_sessions WHERE cohort_id = ?');
    $sessionTotal->execute([$cid]);
    $sessionTotal = (int)$sessionTotal->fetchColumn();
    $sessions = db()->prepare("SELECT s.*, u.name AS staff_name,
            (SELECT COUNT(*) FROM cohort_attendance a WHERE a.session_id = s.id AND a.present = 1) AS present_n
        FROM cohort_sessions s LEFT JOIN users u ON u.id = s.staff_id WHERE s.cohort_id = ? ORDER BY s.session_date DESC, s.id DESC" . ($allSessions ? '' : ' LIMIT 50'));
    $sessions->execute([$cid]);
    $sessions = $sessions->fetchAll();
    // Who was marked present and absent at each listed session, so a wrong entry can be spotted.
    $attendance = [];
    if ($sessions) {
        $ids = array_map(fn($s) => (int)$s['id'], $sessions);
        $aq = db()->prepare('SELECT a.session_id, a.present, c.name FROM cohort_attendance a JOIN clients c ON c.id = a.client_id
            WHERE a.session_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY c.name');
        $aq->execute($ids);
        foreach ($aq->fetchAll() as $a) $attendance[(int)$a['session_id']][(int)$a['present'] === 1 ? 'present' : 'absent'][] = $a['name'];
    }
    $matrix = cohort_matrix($cid);
    // Pre-ETS students not yet in this cohort.
    $avail = db()->prepare("SELECT id, name FROM clients WHERE client_type = 'Pre-ETS' AND status NOT LIKE 'Closed%'
        AND id NOT IN (SELECT client_id FROM cohort_members WHERE cohort_id = ? AND left_at IS NULL)" . (caseload_scoped() ? ' AND assigned_to = ' . (int)$user['id'] : '') . " ORDER BY name");
    $avail->execute([$cid]);
    $avail = $avail->fetchAll();

    page_header('Cohort: ' . $co['name'], $user, 'programs');
    ?>
    <?php /* A student's name link sits beside its remove button, so it also gets a 44px target (2.5.5). */ ?>
    <style>.link-target a{display:inline-flex;align-items:center;min-height:2.75rem}</style>
    <p><a href="programs.php">&larr; All cohorts</a></p>
    <h1><?= h($co['name']) ?> <?= $co['active'] ? '' : '<span class="badge badge-hold">Archived</span>' ?></h1>
    <p class="muted"><?= $co['school'] ? h($co['school']) . ' · ' : '' ?><?= $co['coordinator'] ? 'Coordinator: ' . h($co['coordinator']) : '' ?>
      <?= $co['start_date'] ? ' · ' . fmt_date($co['start_date']) . ($co['end_date'] ? ' – ' . fmt_date($co['end_date']) : '') : '' ?></p>
    <div class="page-actions">
      <a class="btn btn-secondary btn-sm" href="programs.php?export=<?= $cid ?>">Export hours by activity (CSV)</a>
      <form method="post" action="programs.php" class="inline-form"><?= csrf_field() ?>
        <input type="hidden" name="cohort_id" value="<?= $cid ?>"><input type="hidden" name="to" value="<?= $co['active'] ? '0' : '1' ?>">
        <button type="submit" name="archive_cohort" value="1" class="btn btn-secondary btn-sm"><?= $co['active'] ? 'Archive cohort' : 'Reactivate cohort' ?></button>
      </form>
    </div>

    <section class="card" aria-labelledby="mem-h">
      <h2 id="mem-h">Students (<?= count($members) ?>)</h2>
      <?php if ($members): ?>
      <ul class="link-target">
        <?php foreach ($members as $m): ?>
        <li><a href="client.php?id=<?= (int)$m['id'] ?>"><?= h($m['name']) ?></a>
          <form method="post" action="programs.php" class="inline-form"><?= csrf_field() ?>
            <input type="hidden" name="cohort_id" value="<?= $cid ?>"><input type="hidden" name="client_id" value="<?= (int)$m['id'] ?>">
            <button type="submit" name="remove_member" value="1" class="btn-link" onclick="return confirm('Remove this student from the cohort?');">remove<span class="visually-hidden"> <?= h($m['name']) ?></span></button>
          </form></li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?><p class="muted">No students yet.</p><?php endif; ?>
      <form method="post" action="programs.php" class="inline-form" style="margin-top:.5rem"><?= csrf_field() ?>
        <input type="hidden" name="cohort_id" value="<?= $cid ?>">
        <?= form_error_summary(['add_client' => '']) ?>
        <label for="add_client" class="visually-hidden">Add a Pre-ETS student</label>
        <select id="add_client" name="client_id" required <?= field_aria('add_client') ?>>
          <option value="">— Add a Pre-ETS student —</option>
          <?php foreach ($avail as $a): ?><option value="<?= (int)$a['id'] ?>"><?= h($a['name']) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" name="add_member" value="1" class="btn btn-secondary btn-sm">Add student</button>
        <?= field_error('add_client') ?>
      </form>
      <?php if (!$avail): ?><p class="hint">All Pre-ETS students are already in this cohort, or none are on file. Add students with client type "Pre-ETS".</p><?php endif; ?>
    </section>

    <section class="card" aria-labelledby="log-h">
      <h2 id="log-h">Log a group session</h2>
      <?php if (!$members): ?><p class="muted">Add students first, then log sessions with attendance.</p><?php else: ?>
      <?php // After a rejected submission the form comes back as it was sent, with the error on its field.
      $oldLog = old_in('log_session') !== '';
      $oldPresent = $oldLog && is_array($_SESSION['log_present'] ?? null) ? $_SESSION['log_present'] : null;
      unset($_SESSION['log_present']); ?>
      <form method="post" action="programs.php" class="stacked" novalidate><?= csrf_field() ?>
        <input type="hidden" name="cohort_id" value="<?= $cid ?>">
        <?= form_error_summary(['activity' => 'Pre-ETS activity', 'session_date' => 'Date', 'duration_min' => 'Duration (minutes)']) ?>
        <div class="grid grid-4">
          <div class="field"><label for="activity">Pre-ETS activity</label>
            <select id="activity" name="activity" <?= field_aria('activity') ?>><?= select_options(PREETS_ACTIVITIES, $oldLog ? old_in('activity') : 'Job Exploration Counseling') ?></select><?= field_error('activity') ?></div>
          <div class="field"><label for="session_date">Date</label><input type="date" id="session_date" name="session_date" required min="2000-01-01" max="<?= date('Y-m-d') ?>" value="<?= h($oldLog ? old_in('session_date') : date('Y-m-d')) ?>" <?= field_aria('session_date') ?>><?= field_error('session_date') ?></div>
          <div class="field"><label for="duration_min">Duration (minutes)</label><input type="number" id="duration_min" name="duration_min" min="1" max="1440" required value="<?= h($oldLog ? old_in('duration_min') : '60') ?>" <?= field_aria('duration_min') ?>><?= field_error('duration_min') ?></div>
          <div class="field"><label for="topic">Topic</label><input type="text" id="topic" name="topic" maxlength="190" value="<?= h($oldLog ? old_in('topic') : '') ?>"></div>
        </div>
        <fieldset>
          <legend>Attendance</legend>
          <?php foreach ($members as $m): $isPresent = $oldPresent === null || isset($oldPresent[(int)$m['id']]); ?>
          <label style="display:inline-flex;align-items:center;gap:.4rem;margin-right:1rem"><input type="checkbox" name="present[<?= (int)$m['id'] ?>]" value="1" <?= $isPresent ? 'checked' : '' ?>> <?= h($m['name']) ?></label>
          <?php endforeach; ?>
        </fieldset>
        <div class="field"><label for="notes">Session notes</label><textarea id="notes" name="notes" rows="2" maxlength="2000"><?= h($oldLog ? old_in('notes') : '') ?></textarea></div>
        <button type="submit" name="log_session" value="1" class="btn btn-primary">Log session</button>
      </form>
      <?php endif; ?>
    </section>

    <section class="card" aria-labelledby="matrix-h">
      <h2 id="matrix-h">Hours by activity, per student</h2>
      <div class="table-scroll" tabindex="0" role="region" aria-labelledby="matrix-cap">
      <table>
        <caption class="visually-hidden" id="matrix-cap">Hours in each Pre-ETS activity, one row per student</caption>
        <thead><tr><th scope="col">Student</th><?php foreach (PREETS_ACTIVITIES as $a): ?><th scope="col" class="num"><?= h($a) ?></th><?php endforeach; ?><th scope="col" class="num">Total</th></tr></thead>
        <tbody>
        <?php if (!$matrix): ?><tr><td colspan="<?= count(PREETS_ACTIVITIES) + 2 ?>" class="muted">No attendance recorded yet.</td></tr><?php endif; ?>
        <?php foreach ($matrix as $stu): $tot = 0; ?>
          <tr><th scope="row"><?= h($stu['name']) ?><?= $stu['left'] ? ' <span class="muted">(left the cohort)</span>' : '' ?></th>
            <?php foreach (PREETS_ACTIVITIES as $a): $mins = $stu['acts'][$a] ?? 0; $tot += $mins; ?>
              <td class="num"><?= $mins ? number_format($mins / 60, 1) : '<span aria-hidden="true">—</span><span class="visually-hidden">0</span>' ?></td>
            <?php endforeach; ?>
            <td class="num"><strong><?= number_format($tot / 60, 1) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </section>

    <section class="card" aria-labelledby="sess-h">
      <h2 id="sess-h"><?= $allSessions ? 'All sessions' : 'Recent sessions' ?> (<?= count($sessions) < $sessionTotal ? 'showing ' . count($sessions) . ' of ' . $sessionTotal : $sessionTotal ?>)</h2>
      <?php if (count($sessions) < $sessionTotal): ?><p><a href="programs.php?id=<?= $cid ?>&amp;all_sessions=1#sess-h">Show all <?= $sessionTotal ?> sessions</a> <span class="muted">(older sessions, including any logged with a mistyped date, can be deleted from the full list)</span></p>
      <?php elseif ($allSessions && $sessionTotal > 50): ?><p><a href="programs.php?id=<?= $cid ?>#sess-h">Show the 50 most recent sessions only</a></p><?php endif; ?>
      <?php if ($sessions): ?>
      <ul class="timeline">
        <?php foreach ($sessions as $s): ?>
        <?php // The delete button names the session fully (two sessions can share an activity
        // and a date, such as a morning and an afternoon one), ending with its record number.
        $att = $attendance[(int)$s['id']] ?? [];
        $sessWhich = h($s['activity']) . ', ' . fmt_date($s['session_date']) . ', ' . (int)$s['duration_min'] . ' minutes'
            . ($s['topic'] ? ', ' . h($s['topic']) : '') . ' (record ' . (int)$s['id'] . ')'; ?>
        <li><strong><?= h($s['activity']) ?></strong> · <?= fmt_date($s['session_date']) ?> · <?= (int)$s['duration_min'] ?> min · <?= (int)$s['present_n'] ?> present
          <?= $s['topic'] ? '<br>' . h($s['topic']) : '' ?>
          <span class="muted"><?= $s['staff_name'] ? ' — ' . h($s['staff_name']) : '' ?></span>
          <br>Present: <?= !empty($att['present']) ? h(implode(', ', $att['present'])) : 'nobody' ?>.
          <?= !empty($att['absent']) ? 'Absent: ' . h(implode(', ', $att['absent'])) . '.' : '' ?>
          <form method="post" action="programs.php" class="inline-form"><?= csrf_field() ?>
            <input type="hidden" name="cohort_id" value="<?= $cid ?>"><input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
            <?php if ($allSessions): ?><input type="hidden" name="all_sessions" value="1"><?php endif; ?>
            <button type="submit" name="delete_session" value="1" class="btn-link"
              onclick="return confirm('Delete this session and its attendance? Its hours come out of the report. Choose OK to delete it, or Cancel to keep it.');">Delete session<span class="visually-hidden"> — <?= $sessWhich ?></span></button>
          </form></li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?><p class="muted">No sessions logged yet.</p><?php endif; ?>
    </section>
    <?php
    page_footer();
    exit;
}

/* ================= Cohort list ================= */
$showArchived = !empty($_GET['archived']);
$cohorts = db()->query("SELECT co.*, u.name AS coordinator,
        (SELECT COUNT(*) FROM cohort_members cm WHERE cm.cohort_id = co.id AND cm.left_at IS NULL) AS members,
        (SELECT COUNT(*) FROM cohort_sessions s WHERE s.cohort_id = co.id) AS sessions
    FROM cohorts co LEFT JOIN users u ON u.id = co.coordinator_id
    WHERE co.active = " . ($showArchived ? '0' : '1') . cohort_access_sql((int)$user['id']) . " ORDER BY co.start_date IS NULL, co.start_date DESC, co.name")->fetchAll();

// The title, and a heading over the list, say which cohorts are shown.
page_header($showArchived ? 'Programs (archived cohorts)' : 'Programs', $user, 'programs');
?>
<h1>Pre-ETS &amp; group programs</h1>
<p class="muted">Manage cohorts of Pre-ETS students, log group sessions against the five required activities, and report each student's hours by activity.</p>

<section class="card" aria-labelledby="new-h">
  <h2 id="new-h">New cohort</h2>
  <?php // After a rejected submission the form comes back filled in, with the error on its field.
  $oldCohort = old_in('add_cohort') !== '';
  $coordPick = $oldCohort ? (int)old_in('coordinator_id') : (int)$user['id']; ?>
  <form method="post" action="programs.php" class="stacked" novalidate>
    <?= csrf_field() ?>
    <?= form_error_summary(['name' => '', 'start_date' => '', 'end_date' => '']) ?>
    <div class="grid grid-4">
      <div class="field"><label for="name">Cohort name *</label><input type="text" id="name" name="name" required maxlength="190" placeholder="e.g. Central HS Fall 2026" value="<?= h($oldCohort ? old_in('name') : '') ?>" <?= field_aria('name') ?>><?= field_error('name') ?></div>
      <div class="field"><label for="school">School / district</label><input type="text" id="school" name="school" maxlength="190" value="<?= h($oldCohort ? old_in('school') : '') ?>"></div>
      <div class="field"><label for="coordinator_id">Coordinator</label>
        <select id="coordinator_id" name="coordinator_id">
          <option value="">— None —</option>
          <?php foreach (db()->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name') as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= $coordPick === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option><?php endforeach; ?>
        </select></div>
    </div>
    <div class="grid grid-4">
      <div class="field"><label for="start_date">Start</label><input type="date" id="start_date" name="start_date" min="2000-01-01" max="<?= (int)date('Y') + 5 ?>-12-31" value="<?= h($oldCohort ? old_in('start_date') : '') ?>" <?= field_aria('start_date') ?>><?= field_error('start_date') ?></div>
      <div class="field"><label for="end_date">End</label><input type="date" id="end_date" name="end_date" min="2000-01-01" max="<?= (int)date('Y') + 5 ?>-12-31" value="<?= h($oldCohort ? old_in('end_date') : '') ?>" <?= field_aria('end_date') ?>><?= field_error('end_date') ?></div>
    </div>
    <button type="submit" name="add_cohort" value="1" class="btn btn-primary">Create cohort</button>
  </form>
</section>

<div class="page-actions">
  <a class="btn btn-secondary btn-sm" href="programs.php<?= $showArchived ? '' : '?archived=1' ?>"><?= $showArchived ? 'Show active cohorts' : 'Show archived' ?></a>
</div>

<h2 id="cohorts-h"><?= $showArchived ? 'Archived cohorts' : 'Active cohorts' ?> (<?= count($cohorts) ?>)</h2>
<div class="grid grid-2">
  <?php if (!$cohorts): ?><p class="muted">No <?= $showArchived ? 'archived' : 'active' ?> cohorts.</p><?php endif; ?>
  <?php foreach ($cohorts as $co): ?>
  <section class="card">
    <h3 style="margin-top:0"><a href="programs.php?id=<?= (int)$co['id'] ?>"><?= h($co['name']) ?><?= $showArchived ? '<span class="visually-hidden"> (archived)</span>' : '' ?></a></h3>
    <p class="muted"><?= $co['school'] ? h($co['school']) . ' · ' : '' ?><?= (int)$co['members'] ?> students · <?= (int)$co['sessions'] ?> sessions
      <?= $co['coordinator'] ? '<br>Coordinator: ' . h($co['coordinator']) : '' ?></p>
  </section>
  <?php endforeach; ?>
</div>
<?php page_footer();
