<?php
// Training curricula & competency mastery. A curriculum is an ordered set of
// skills for an assistive technology (e.g. a JAWS curriculum). Staff track each
// client's mastery of those skills over time (Not started → Mastered), so
// progress is visible skill-by-skill, not just as a goal percentage.
// Management (create curricula / skills) needs the 'templates' permission;
// tracking a client needs 'clients'. Client tracking is caseload-scoped.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
// Either permission opens the page: managing curricula needs 'templates' and
// tracking a client's competency needs 'clients'. Each action checks its own.
if (!can('templates')) require_perm('clients');

const COMPETENCY_LEVELS = [0 => 'Not started', 1 => 'Introduced', 2 => 'Practicing', 3 => 'Proficient', 4 => 'Mastered'];

/* ---- Management actions (require templates permission) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_curriculum'])) {
    require_perm('templates');
    // A field posted as an array (name[]=) counts as blank, never a TypeError.
    $str = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
    $name = $str('name');
    $tech = $str('tech');
    $description = $str('description');
    // Each problem is tied to its field, and what was typed stays in the form
    // (sizes are the curricula columns).
    $cErrs = [];
    if ($name === '') $cErrs['name'] = 'Curriculum name is required.';
    elseif (mb_strlen($name) > 190) $cErrs['name'] = 'Keep the curriculum name to 190 characters or fewer.';
    if (mb_strlen($tech) > 120) $cErrs['tech'] = 'Keep the technology to 120 characters or fewer.';
    if (mb_strlen($description) > 500) $cErrs['description'] = 'Keep the description to 500 characters or fewer.';
    if ($cErrs) {
        keep_input();
        keep_field_errors($cErrs);
        redirect('curriculum.php#new-h');
    }
    db()->prepare('INSERT INTO curricula (name, tech, description) VALUES (?,?,?)')
        ->execute([$name, $tech !== '' ? $tech : null, $description !== '' ? $description : null]);
    // Capture the id before audit() inserts its own row and replaces it.
    $newCurId = (int)db()->lastInsertId();
    audit($user['id'], 'curriculum.add', 'curriculum', $newCurId, $name);
    flash('Curriculum created. Add its skills below.');
    redirect('curriculum.php?manage=' . $newCurId);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_skill'])) {
    require_perm('templates');
    $curId = (int)($_POST['curriculum_id'] ?? 0);
    $curQ = db()->prepare('SELECT id FROM curricula WHERE id = ?');
    $curQ->execute([$curId]);
    if ($curQ->fetchColumn() === false) { flash('Curriculum not found.', 'error'); redirect('curriculum.php'); }
    // Each problem is tied to the skills box, and what was typed stays in it
    // (a skill is at most 300 characters, the curriculum_skills.skill column).
    $skillLines = [];
    $tooLong = [];
    // Not text (skills[]=x) counts as empty, so it gets the 'enter a skill' message
    // instead of becoming a skill named "Array".
    foreach (preg_split('/\r?\n/', is_string($_POST['skills'] ?? null) ? $_POST['skills'] : '') as $i => $line) {
        $s = trim($line);
        if ($s === '') continue;
        if (mb_strlen($s) > 300) $tooLong[] = $i + 1;
        $skillLines[] = $s;
    }
    $skErr = !$skillLines ? 'Enter at least one skill, one per line.'
        : ($tooLong ? 'Keep each skill to 300 characters or fewer (too long on line ' . implode(', ', $tooLong) . ').' : null);
    if ($skErr !== null) {
        keep_input();
        keep_field_errors(['skills' => $skErr]);
        redirect('curriculum.php?manage=' . $curId . '#add-sk-h');
    }
    // All the skills are added, or none (in their typed order after the existing ones).
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $sortQ = $pdo->prepare('SELECT COALESCE(MAX(sort),0) FROM curriculum_skills WHERE curriculum_id = ? FOR UPDATE'); $sortQ->execute([$curId]); $sortBase = (int)$sortQ->fetchColumn();
        $ins = $pdo->prepare('INSERT INTO curriculum_skills (curriculum_id, skill, sort) VALUES (?,?,?)');
        foreach ($skillLines as $s) $ins->execute([$curId, $s, ++$sortBase]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $n = count($skillLines);
    audit($user['id'], 'curriculum.skills', 'curriculum', $curId, $n . ' skills');
    flash("Added {$n} skill(s).");
    redirect('curriculum.php?manage=' . $curId);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_skill'])) {
    require_perm('templates');
    $curId = (int)($_POST['curriculum_id'] ?? 0);
    $skillId = (int)($_POST['skill_id'] ?? 0);
    // The skill must belong to the curriculum being managed.
    $sk = db()->prepare('SELECT skill FROM curriculum_skills WHERE id = ? AND curriculum_id = ?');
    $sk->execute([$skillId, $curId]);
    $skillName = $sk->fetchColumn();
    if ($skillName === false) {
        flash('That skill is not part of this curriculum.', 'error');
        redirect('curriculum.php?manage=' . $curId);
    }
    // Deleting a skill cascades to every client's competency history for it,
    // so only a skill nobody has been rated on can be removed. The guard is in
    // the DELETE itself so a rating saved a moment earlier still blocks it.
    $del = db()->prepare('DELETE FROM curriculum_skills WHERE id = ? AND curriculum_id = ?
        AND NOT EXISTS (SELECT 1 FROM client_competency cc WHERE cc.skill_id = curriculum_skills.id)');
    $del->execute([$skillId, $curId]);
    if ($del->rowCount() === 1) {
        audit($user['id'], 'curriculum.skill_delete', 'curriculum', $curId, (string)$skillName);
        flash('Skill removed.');
    } else {
        $used = db()->prepare('SELECT COUNT(*) FROM client_competency WHERE skill_id = ?');
        $used->execute([$skillId]);
        $ratings = (int)$used->fetchColumn();
        flash($ratings > 0
            ? 'This skill has ' . $ratings . ' competency rating' . ($ratings === 1 ? '' : 's')
                . ' on client records, so it cannot be removed — removing it would delete that history. Deactivate the curriculum instead to stop it being offered.'
            : 'That skill was already removed.', 'error');
    }
    redirect('curriculum.php?manage=' . $curId);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_curriculum'])) {
    require_perm('templates');
    // The form posts the state its button named, and the update only changes a
    // curriculum not already in that state: a double submit or a stale page can never
    // do the opposite of what the button said, and racing submits audit it once.
    $curId = (int)($_POST['curriculum_id'] ?? 0);
    $to = ($_POST['to'] ?? '') === '1' ? 1 : (($_POST['to'] ?? '') === '0' ? 0 : null);
    $cname = db()->prepare('SELECT name FROM curricula WHERE id = ?');
    $cname->execute([$curId]);
    $curName = $cname->fetchColumn();
    if ($curName === false) {
        flash('Curriculum not found.', 'error');
    } elseif ($to === null) {
        flash('The curriculum was not changed. Reload the page and try again.', 'error');
    } else {
        $upd = db()->prepare('UPDATE curricula SET active = ? WHERE id = ? AND active <> ?');
        $upd->execute([$to, $curId, $to]);
        if ($upd->rowCount() === 1) {
            audit($user['id'], $to ? 'curriculum.activate' : 'curriculum.deactivate', 'curriculum', $curId, (string)$curName);
            flash($to ? 'Curriculum activated.' : 'Curriculum deactivated.');
        } else {
            flash($to ? 'That curriculum is already active.' : 'That curriculum is already inactive.');
        }
    }
    redirect('curriculum.php');
}

/* ---- Record competency for a client ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_competency'])) {
    require_perm('clients');
    $cid = (int)($_POST['client_id'] ?? 0);
    $cstmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $cstmt->execute([$cid]);
    $client = $cstmt->fetch();
    if (!$client) { flash('Client not found.', 'error'); redirect('clients.php'); }
    scope_check_client($client, $user);
    $curId = (int)($_POST['curriculum_id'] ?? 0);
    // Current level per skill, to only write history rows when something changed.
    $cur = db()->prepare('SELECT cs.id, (SELECT level FROM client_competency cc WHERE cc.client_id = ? AND cc.skill_id = cs.id ORDER BY cc.assessed_at DESC, cc.id DESC LIMIT 1) AS lvl
        FROM curriculum_skills cs WHERE cs.curriculum_id = ?');
    $cur->execute([$cid, $curId]);
    $levels = is_array($_POST['level'] ?? null) ? $_POST['level'] : [];
    // The level each skill showed when the page was drawn: only a skill the user
    // actually changed is written, so a page left open never reverts a level set
    // elsewhere since (another tab, another instructor, the app).
    $shown = is_array($_POST['prev'] ?? null) ? $_POST['prev'] : [];
    $ins = db()->prepare('INSERT INTO client_competency (client_id, skill_id, level, note, assessed_by) VALUES (?,?,?,?,?)');
    $changed = 0; $movedElsewhere = 0;
    foreach ($cur->fetchAll() as $row) {
        $sid = (int)$row['id'];
        if (!isset($levels[$sid])) continue;
        $newLvl = max(0, min(4, (int)$levels[$sid]));
        $stored = (int)($row['lvl'] ?? 0);
        $before = isset($shown[$sid]) ? (int)$shown[$sid] : $stored;
        if ($newLvl === $before) {
            if ($stored !== $before) $movedElsewhere++;
            continue;
        }
        if ($stored !== $newLvl) { $ins->execute([$cid, $sid, $newLvl, null, $user['id']]); $changed++; }
    }
    audit($user['id'], 'competency.save', 'client', $cid, $changed . ' skill(s)');
    flash(($changed ? "Updated {$changed} skill level(s)." : 'No changes.')
        . ($movedElsewhere ? " {$movedElsewhere} skill level(s) you did not change had been updated by someone else since this page opened; those were kept." : ''));
    redirect('curriculum.php?client=' . $cid . '&curriculum=' . $curId);
}

/* ================= Per-client competency tracking ================= */
if (isset($_GET['client'])) {
    require_perm('clients');
    $cid = (int)$_GET['client'];
    $cstmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $cstmt->execute([$cid]);
    $client = $cstmt->fetch();
    if (!$client) { flash('Client not found.', 'error'); redirect('clients.php'); }
    scope_check_client($client, $user);
    $curId = (int)($_GET['curriculum'] ?? 0);
    // Active curricula, plus any deactivated one this client was rated on (its
    // history stays reachable) and the one asked for, so the chooser always names
    // the curriculum whose table is shown.
    $curQ = db()->prepare('SELECT id, name, tech, active FROM curricula WHERE active = 1 OR id = ?
        OR id IN (SELECT cs.curriculum_id FROM curriculum_skills cs JOIN client_competency cc ON cc.skill_id = cs.id WHERE cc.client_id = ?)
        ORDER BY active DESC, name');
    $curQ->execute([$curId, $cid]);
    $curricula = $curQ->fetchAll();
    $skills = [];
    if ($curId) {
        $sk = db()->prepare('SELECT cs.id, cs.skill,
                (SELECT level FROM client_competency cc WHERE cc.client_id = ? AND cc.skill_id = cs.id ORDER BY cc.assessed_at DESC, cc.id DESC LIMIT 1) AS lvl,
                (SELECT assessed_at FROM client_competency cc WHERE cc.client_id = ? AND cc.skill_id = cs.id ORDER BY cc.assessed_at DESC, cc.id DESC LIMIT 1) AS at
            FROM curriculum_skills cs WHERE cs.curriculum_id = ? ORDER BY cs.sort, cs.id');
        $sk->execute([$cid, $cid, $curId]);
        $skills = $sk->fetchAll();
    }
    $mastered = count(array_filter($skills, fn($s) => (int)$s['lvl'] === 4));
    page_header('Competency: ' . $client['name'], $user, 'clients');
    ?>
    <p><a href="client.php?id=<?= $cid ?>&tab=assessments">&larr; <?= h($client['name']) ?></a></p>
    <h1>Curriculum &amp; competency — <?= h($client['name']) ?></h1>
    <form method="get" action="curriculum.php" class="page-actions">
      <input type="hidden" name="client" value="<?= $cid ?>">
      <label for="curriculum">Curriculum</label>
      <select id="curriculum" name="curriculum">
        <option value="">— Choose a curriculum —</option>
        <?php foreach ($curricula as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $curId === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?><?= $c['tech'] ? ' (' . h($c['tech']) . ')' : '' ?><?= $c['active'] ? '' : ' (inactive)' ?></option><?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-secondary btn-sm">Go</button>
    </form>
    <?php if ($curId && $skills): ?>
    <div class="progress-wrap card">
      <label for="cmastery" class="progress-caption"><strong><?= $mastered ?></strong> of <?= count($skills) ?> skills mastered</label>
      <progress id="cmastery" class="hours" max="<?= count($skills) ?>" value="<?= $mastered ?>" aria-label="Skills mastered"><?= $mastered ?>/<?= count($skills) ?></progress>
    </div>
    <form method="post" action="curriculum.php" class="stacked"><?= csrf_field() ?>
      <input type="hidden" name="client_id" value="<?= $cid ?>"><input type="hidden" name="curriculum_id" value="<?= $curId ?>">
      <div class="table-scroll" tabindex="0" role="region" aria-label="Competency by skill">
      <table>
        <caption class="visually-hidden">Competency level per skill</caption>
        <thead><tr><th scope="col">Skill</th><th scope="col">Level</th><th scope="col">Last assessed</th></tr></thead>
        <tbody>
        <?php foreach ($skills as $s): $lvl = (int)($s['lvl'] ?? 0); ?>
          <tr>
            <th scope="row"><label for="lvl-<?= (int)$s['id'] ?>"><?= h($s['skill']) ?></label></th>
            <td><select id="lvl-<?= (int)$s['id'] ?>" name="level[<?= (int)$s['id'] ?>]">
              <?php foreach (COMPETENCY_LEVELS as $v => $lab): ?><option value="<?= $v ?>" <?= $v === $lvl ? 'selected' : '' ?>><?= h($lab) ?></option><?php endforeach; ?>
            </select><input type="hidden" name="prev[<?= (int)$s['id'] ?>]" value="<?= $lvl ?>"></td>
            <td class="muted"><?= fmt_date($s['at'], true) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <button type="submit" name="save_competency" value="1" class="btn btn-primary">Save competency levels</button>
    </form>
    <?php elseif ($curId): ?><p class="muted">This curriculum has no skills yet.</p>
    <?php elseif (!$curricula): ?><p class="muted">No curricula defined yet. <?php if (can('templates')): ?><a href="curriculum.php">Create a curriculum</a>.<?php else: ?>An administrator can create them.<?php endif; ?></p>
    <?php endif; ?>
    <?php page_footer(); exit;
}

/* ================= Management: curricula + skills ================= */
if (isset($_GET['manage']) && can('templates')) {
    $curId = (int)$_GET['manage'];
    $cur = db()->prepare('SELECT * FROM curricula WHERE id = ?');
    $cur->execute([$curId]);
    $c = $cur->fetch();
    if (!$c) { flash('Curriculum not found.', 'error'); redirect('curriculum.php'); }
    $skills = db()->prepare('SELECT * FROM curriculum_skills WHERE curriculum_id = ? ORDER BY sort, id');
    $skills->execute([$curId]);
    $skills = $skills->fetchAll();
    page_header('Curriculum: ' . $c['name'], $user, 'templates');
    ?>
    <p><a href="curriculum.php">&larr; All curricula</a></p>
    <h1><?= h($c['name']) ?><?= $c['tech'] ? ' <span class="muted">' . h($c['tech']) . '</span>' : '' ?></h1>
    <?php if ($c['description']): ?><p class="muted"><?= h($c['description']) ?></p><?php endif; ?>
    <section class="card" aria-labelledby="sk-h">
      <h2 id="sk-h">Skills (<?= count($skills) ?>)</h2>
      <?php if ($skills): ?>
      <ol>
      <?php foreach ($skills as $s): ?>
        <li><?= h($s['skill']) ?>
          <form method="post" action="curriculum.php" class="inline-form"><?= csrf_field() ?>
            <input type="hidden" name="curriculum_id" value="<?= $curId ?>"><input type="hidden" name="skill_id" value="<?= (int)$s['id'] ?>">
            <button type="submit" name="del_skill" value="1" class="btn-link" onclick="return confirm('Remove this skill?');">remove<span class="visually-hidden"> — <?= h($s['skill']) ?></span></button>
          </form></li>
      <?php endforeach; ?>
      </ol>
      <?php else: ?><p class="muted">No skills yet.</p><?php endif; ?>
      <h3 id="add-sk-h">Add skills</h3>
      <form method="post" action="curriculum.php" class="stacked" aria-labelledby="add-sk-h" novalidate><?= csrf_field() ?>
        <input type="hidden" name="curriculum_id" value="<?= $curId ?>">
        <?= form_error_summary(['skills' => 'Skills']) ?>
        <div class="field"><label for="skills">Skills to add <span class="hint" id="skills-hint">one per line, up to 300 characters each</span></label>
          <textarea id="skills" name="skills" rows="5" <?= field_aria('skills', 'skills-hint') ?>><?= h(old_in('skills')) ?></textarea><?= field_error('skills') ?></div>
        <button type="submit" name="add_skill" value="1" class="btn btn-primary">Add skills</button>
      </form>
    </section>
    <?php page_footer(); exit;
}

/* ================= Curricula list ================= */
$curricula = db()->query('SELECT c.*, (SELECT COUNT(*) FROM curriculum_skills s WHERE s.curriculum_id = c.id) AS n FROM curricula c ORDER BY c.active DESC, c.name')->fetchAll();
page_header('Curricula', $user, 'templates');
?>
<h1>Training curricula</h1>
<p class="muted">Ordered skill sets for an assistive technology. Staff track each client's mastery of these skills over time from the client's Assessments tab.</p>

<?php if (can('templates')): ?>
<section class="card" aria-labelledby="new-h">
  <h2 id="new-h">New curriculum</h2>
  <form method="post" action="curriculum.php" class="stacked" novalidate>
    <?= csrf_field() ?>
    <?= form_error_summary(['name' => 'Name', 'tech' => 'Technology', 'description' => 'Description']) ?>
    <div class="grid grid-4">
      <div class="field"><label for="name">Name <span aria-hidden="true">*</span><span class="visually-hidden"> (required)</span></label><input type="text" id="name" name="name" required maxlength="190" placeholder="e.g. JAWS Fundamentals" value="<?= h(old_in('name')) ?>" <?= field_aria('name') ?>><?= field_error('name') ?></div>
      <div class="field"><label for="tech">Technology</label><input type="text" id="tech" name="tech" maxlength="120" placeholder="e.g. JAWS" value="<?= h(old_in('tech')) ?>" <?= field_aria('tech') ?>><?= field_error('tech') ?></div>
    </div>
    <div class="field"><label for="description">Description</label><input type="text" id="description" name="description" maxlength="500" value="<?= h(old_in('description')) ?>" <?= field_aria('description') ?>><?= field_error('description') ?></div>
    <button type="submit" name="add_curriculum" value="1" class="btn btn-primary">Create curriculum</button>
  </form>
</section>
<?php endif; ?>

<h2 id="list-h">Curricula</h2>
<div class="table-scroll" tabindex="0" role="region" aria-labelledby="list-h">
<table>
  <caption class="visually-hidden">Training curricula</caption>
  <thead><tr><th scope="col">Curriculum</th><th scope="col">Technology</th><th scope="col" class="num">Skills</th><th scope="col">Status</th><?php if (can('templates')): ?><th scope="col"><span class="visually-hidden">Actions</span></th><?php endif; ?></tr></thead>
  <tbody>
  <?php if (!$curricula): ?><tr><td colspan="5" class="muted">No curricula yet.</td></tr><?php endif; ?>
  <?php foreach ($curricula as $c): ?>
    <tr>
      <th scope="row"><?php if (can('templates')): ?><a href="curriculum.php?manage=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a><?php else: ?><?= h($c['name']) ?><?php endif; ?></th>
      <td><?= h($c['tech'] ?? '—') ?></td>
      <td class="num"><?= (int)$c['n'] ?></td>
      <td><?= $c['active'] ? '<span class="badge badge-closed">Active</span>' : '<span class="badge badge-hold">Inactive</span>' ?></td>
      <?php if (can('templates')): ?>
      <td><form method="post" action="curriculum.php" class="inline-form"><?= csrf_field() ?>
        <input type="hidden" name="curriculum_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="to" value="<?= $c['active'] ? '0' : '1' ?>">
        <button type="submit" name="toggle_curriculum" value="1" class="btn btn-secondary btn-sm"><?= $c['active'] ? 'Deactivate' : 'Activate' ?><span class="visually-hidden"> — <?= h($c['name']) ?></span></button>
      </form></td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php page_footer();
