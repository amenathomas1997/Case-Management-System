<?php
// AI progress review & goal inference: reads a client's active goals plus recent
// notes and logged sessions and drafts a review of which goals advanced, which
// are stalled, and suggested next steps. Staff apply progress per goal in one
// click (writes goal_progress + updates the goal). Governed by ai_run().
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('clients');

$cid = (int)($_GET['client'] ?? 0);
$cstmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
$cstmt->execute([$cid]);
$client = $cstmt->fetch();
if (!$client) { flash('Client not found.', 'error'); redirect('clients.php'); }
scope_check_client($client, $user);

// review_goals(), the review's context and its prompt are shared with the app (inc/progress_ai.php, 4.34.0).
require_once __DIR__ . '/inc/progress_ai.php';

/** The AI review as structure a screen reader can move through: markdown-style
 *  heading lines become h3, "- " / "* " / "1. " lines become list items, other lines
 *  are paragraphs, and leftover emphasis markers (** __ `) are dropped so they are
 *  not read aloud. All text is escaped. */
function review_html(string $text): string {
    $out = ''; $list = null;
    $close = function () use (&$out, &$list) { if ($list !== null) { $out .= '</' . $list . '>'; $list = null; } };
    foreach (preg_split('/\R/u', $text) as $line) {
        $line = trim(str_replace(['**', '__', '`'], '', $line));
        if ($line === '') { $close(); continue; }
        if (preg_match('/^#{1,6}\s*(.+)$/u', $line, $m)) { $close(); $out .= '<h3>' . h(rtrim($m[1], ' #')) . '</h3>'; continue; }
        if (preg_match('/^(?:[-*•]|(\d+)[.)])\s+(.+)$/u', $line, $m)) {
            $want = $m[1] !== '' ? 'ol' : 'ul';
            if ($list !== $want) { $close(); $out .= '<' . $want . '>'; $list = $want; }
            $out .= '<li>' . h($m[2]) . '</li>';
            continue;
        }
        $close();
        $out .= '<p>' . h($line) . '</p>';
    }
    $close();
    return $out;
}

/* ---- Apply a suggested progress update to one goal ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_progress'])) {
    $gid = (int)($_POST['goal_id'] ?? 0);
    // The same Record sent twice (4.29.0) is recorded once, as on the track page.
    $progKey = web_idem_key($_POST);
    if (web_once_get($progKey) !== null) { flash('This progress was already recorded (the form was sent twice), so it was recorded once.', 'info'); redirect('progress_review.php?client=' . $cid . '#goal-' . $gid); }
    $own = db()->prepare('SELECT sg.status, sg.progress_pct FROM smart_goals sg JOIN training_tracks t ON t.id = sg.track_id WHERE sg.id = ? AND t.client_id = ?');
    $own->execute([$gid, $cid]);
    $cur = $own->fetch();
    if (!$cur) { flash('Goal not found.', 'error'); redirect('progress_review.php?client=' . $cid); }
    // The rules, the history row, the audit and the sentence are the helper the apps' update_goal
    // shares (inc/progress_ai.php, 4.37.0), the same as the track page's goal update.
    try {
        $rec = goal_progress_record($gid, ['status' => $_POST['goal_status'] ?? '', 'progress_pct' => is_string($_POST['progress_pct'] ?? null) ? $_POST['progress_pct'] : '',
            'note' => (string)($_POST['progress_note'] ?? ''), 'reopen_confirmed' => (string)($_POST['reopen_confirmed'] ?? '')], $user, 'web', $progKey);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000' && $progKey !== null) { flash('This progress was already recorded, so it was recorded once.', 'info'); redirect('progress_review.php?client=' . $cid . '#goal-' . $gid); }
        throw $e;
    }
    if (!$rec['ok'] && isset($rec['errors'])) {
        // Refuse this one goal's row with its own error, and refill that row only. When the question is
        // about reopening an Achieved goal, that row is shown (it is no longer in the active list) and its
        // next Record confirms the percent typed.
        keep_input();
        $field = isset($rec['errors']['note']) ? 'goal_note_' : 'goal_pct_';
        keep_field_errors([$field . $gid => (string)reset($rec['errors'])]);
        if (!empty($rec['needs_reopen_confirm'])) $_SESSION['progress_reopen'] = ['cid' => $cid, 'gid' => $gid];
        redirect('progress_review.php?client=' . $cid . '#goal-' . $gid);
    }
    if (!$rec['ok']) { flash($rec['error'], 'error'); redirect('progress_review.php?client=' . $cid); }
    web_once_set($progKey, $gid);
    $gs = $rec['status'];
    $done = $rec['message'];
    if ($gs === 'Achieved') {
        // An achieved goal leaves the active list, so the confirmation goes at the top.
        flash($done);
        redirect('progress_review.php?client=' . $cid);
    }
    // Otherwise the confirmation is shown in that goal's row, and focus goes back to
    // it, so the user carries on from where they were instead of from the top.
    $_SESSION['progress_recorded'] = ['cid' => $cid, 'gid' => $gid, 'msg' => $done];
    redirect('progress_review.php?client=' . $cid . '#goal-' . $gid);
}

/* ---- Dismiss the kept review draft ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dismiss_review'])) {
    unset($_SESSION['progress_review']);
    flash('Review dismissed.');
    redirect('progress_review.php?client=' . $cid);
}

// A goal whose reopening from Achieved was just asked about (shown once, with the
// question in its row).
$reopen = $_SESSION['progress_reopen'] ?? null;
unset($_SESSION['progress_reopen']);
$reopenGid = (is_array($reopen) && (int)($reopen['cid'] ?? 0) === $cid && $_SERVER['REQUEST_METHOD'] !== 'POST') ? (int)($reopen['gid'] ?? 0) : 0;
$goals = review_goals($cid, $reopenGid);
$review = null;
$reviewAt = null;
$reviewCut = false;
// The latest draft stays on the page while staff record each goal (every Record is a
// new page load). Only one draft is kept, for one client, for at most an hour, and it
// goes on dismiss, on a new review, or when the session ends.
$kept = $_SESSION['progress_review'] ?? null;
if (!is_array($kept) || (int)($kept['at'] ?? 0) <= time() - 3600) {
    unset($_SESSION['progress_review']);
} elseif ((int)($kept['cid'] ?? 0) === $cid) {
    $review = (string)($kept['text'] ?? '');
    $reviewAt = (int)$kept['at'];
    $reviewCut = !empty($kept['truncated']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    if (!ai_feature_enabled('progress')) { flash('Progress review is turned off.', 'error'); }
    elseif (!$goals) { flash('No active goals to review.', 'error'); }
    else {
        $res = ai_run('progress', progress_ai_system(), progress_ai_context($cid, $goals), 1400, $client, (int)$user['id']);
        audit($user['id'], 'ai.draft', 'client', $cid, 'progress review');
        if ($res['ok']) {
            $review = $res['text']; $reviewAt = time();
            // A review the AI stopped short is still shown, and says so: the goals
            // after the last one it mentions were not reviewed.
            $reviewCut = !empty($res['truncated']);
            $_SESSION['progress_review'] = ['cid' => $cid, 'text' => $review, 'at' => $reviewAt, 'truncated' => $reviewCut];
            flash($reviewCut
                ? 'Review drafted, but it is incomplete: the AI stopped before the end, so goals after the last one it mentions were not reviewed. Apply any progress updates below.'
                : 'Review drafted — apply any progress updates below.');
        }
        else flash($res['error'], 'error');
    }
}

// The confirmation of the last Record, shown once in that goal's row.
$recorded = $_SESSION['progress_recorded'] ?? null;
unset($_SESSION['progress_recorded']);
if (!is_array($recorded) || (int)($recorded['cid'] ?? 0) !== $cid) $recorded = null;
if ($recorded && !in_array((int)$recorded['gid'], array_map(fn($g) => (int)$g['id'], $goals), true)) {
    flash((string)$recorded['msg']);
    $recorded = null;
}

page_header('Progress review: ' . $client['name'], $user, 'clients');
?>
<p><a href="client.php?id=<?= $cid ?>"><span aria-hidden="true">&larr; </span><?= h($client['name']) ?></a></p>
<h1>AI progress review for <?= h($client['name']) ?></h1>
<p class="muted">The AI reviews recent notes and sessions against each active goal. Staff decide what to record.</p>

<?php if (ai_feature_enabled('progress')): ?>
<form method="post" action="progress_review.php?client=<?= $cid ?>"><?= csrf_field() ?>
  <button type="submit" name="generate" value="1" class="btn btn-primary">Generate progress review</button>
</form>
<?php else: ?><p class="muted">Progress review is turned off in AI settings.</p><?php endif; ?>

<?php if ($review !== null): ?>
<section class="card" aria-labelledby="rev-h" style="margin-top:1rem">
  <h2 id="rev-h">Review (AI draft<?= $reviewCut ? ', incomplete' : '' ?>)</h2>
  <?php if ($reviewCut): ?><p class="field-error"><strong>Incomplete:</strong> this review was cut short, so goals after the last one it mentions were not reviewed.</p><?php endif; ?>
  <?php if ($reviewAt): ?><p class="muted">Drafted <?= h(date('g:i A', $reviewAt)) ?>. It stays here while you record progress below.</p><?php endif; ?>
  <div class="ai-review"><?= review_html($review) ?></div>
  <form method="post" action="progress_review.php?client=<?= $cid ?>"><?= csrf_field() ?>
    <button type="submit" name="dismiss_review" value="1" class="btn btn-secondary btn-sm">Dismiss review</button>
  </form>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="ga-h">
  <h2 id="ga-h">Active goals (<?= count($goals) ?>)</h2>
  <?php if (!$goals): ?><p class="muted">No active goals.</p><?php endif; ?>
  <?php if ($goals): ?><ul style="list-style:none;padding:0;margin:0"><?php endif; ?>
  <?php foreach ($goals as $g): $gid = (int)$g['id']; $goalName = h($g['specific_goal']);
    // After a refused Record, only that goal's row comes back with what was typed.
    $refill = old_in('apply_progress') !== '' && old_in('goal_id') === (string)$gid; ?>
  <li id="goal-<?= $gid ?>" style="border-top:1px solid var(--line); padding:.6rem 0">
    <h3 style="margin:0;font-size:1.05rem">Goal: <?= $goalName ?></h3>
    <p style="margin:0" class="muted"><?= h($g['track']) ?> · currently <?= (int)$g['progress_pct'] ?>% · <?= h($g['status']) ?></p>
    <?php if ($recorded && (int)$recorded['gid'] === $gid): ?>
    <p class="alert alert-success" id="goal-recorded" tabindex="-1"><?= h((string)$recorded['msg']) ?></p>
    <script>(window.cmsFocusAfterLoad||function(f){document.addEventListener("DOMContentLoaded",f);})(function(){var e=document.getElementById("goal-recorded");if(e)e.focus();});</script>
    <?php endif; ?>
    <?php // Every control names its goal (in hidden text), so repeated forms are told apart when tabbing. ?>
    <form method="post" action="progress_review.php?client=<?= $cid ?>" class="stacked" novalidate><?= csrf_field() ?><?= web_idem_field() ?>
      <?= form_error_summary(['goal_pct_' . $gid => '', 'goal_note_' . $gid => ''], ['goal_pct_' . $gid => 'pc-' . $gid, 'goal_note_' . $gid => 'pn-' . $gid]) ?>
      <input type="hidden" name="goal_id" value="<?= $gid ?>">
      <?php if ($reopenGid === $gid): ?><input type="hidden" name="reopen_confirmed" value="1"><?php endif; ?>
      <div class="grid grid-4">
        <div class="field"><label for="st-<?= $gid ?>">Status<span class="visually-hidden"> for goal: <?= $goalName ?></span></label>
          <select id="st-<?= $gid ?>" name="goal_status"><?= select_options(GOAL_STATUSES, $refill ? old_in('goal_status', $g['status']) : $g['status']) ?></select></div>
        <div class="field"><label for="pc-<?= $gid ?>">Percent complete<span class="visually-hidden"> for goal: <?= $goalName ?></span></label>
          <input type="number" id="pc-<?= $gid ?>" name="progress_pct" min="0" max="100" value="<?= $refill ? h(old_in('progress_pct')) : (int)$g['progress_pct'] ?>" style="max-width:6rem" <?= field_aria('goal_pct_' . $gid) ?>>
          <?= field_error('goal_pct_' . $gid) ?></div>
        <div class="field"><label for="pn-<?= $gid ?>">Note<span class="visually-hidden"> for goal: <?= $goalName ?></span> <span class="hint">optional</span></label>
          <input type="text" id="pn-<?= $gid ?>" name="progress_note" value="<?= $refill ? h(old_in('progress_note')) : '' ?>" <?= field_aria('goal_note_' . $gid) ?>>
          <?= field_error('goal_note_' . $gid) ?></div>
      </div>
      <button type="submit" name="apply_progress" value="1" class="btn btn-secondary btn-sm">Record<span class="visually-hidden"> progress for goal: <?= $goalName ?></span></button>
    </form>
  </li>
  <?php endforeach; ?>
  <?php if ($goals): ?></ul><?php endif; ?>
</section>
<?php page_footer();
