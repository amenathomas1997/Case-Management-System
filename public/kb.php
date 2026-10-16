<?php
// Knowledge base / resource library: searchable how-to articles, tip sheets and
// device guides for staff, with the option to publish an article to clients'
// portals. Every signed-in user can read; managing articles needs 'templates'.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();

/* ---- Management actions (require templates permission) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_article'])) {
    require_perm('templates');
    $in = fn(string $k): string => trim(is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
    $title = $in('title'); $category = $in('category'); $body = $in('body');
    $id = (int)($_POST['id'] ?? 0);
    $errs = [];
    if ($title === '') $errs['title'] = 'Enter a title.';
    elseif (mb_strlen($title) > 255) $errs['title'] = 'Shorten the title to 255 characters or fewer.';
    if (mb_strlen($category) > 80) $errs['category'] = 'Shorten the category to 80 characters or fewer.';
    if (mb_strlen($body) > 60000) $errs['body'] = 'Shorten the content to 60,000 characters or fewer, or split it into two articles.';
    // Back to the editor with what was typed and each problem marked (a new-article
    // editor when the article was deleted meanwhile, so nothing typed is lost).
    if ($id) {
        $ex = db()->prepare('SELECT id FROM kb_articles WHERE id = ?');
        $ex->execute([$id]);
        if (!$ex->fetchColumn()) {
            keep_input(); keep_field_errors($errs);
            flash('This article no longer exists; someone may have deleted it. Your text is below: save it to create a new article.', 'error');
            redirect('kb.php?edit=0');
        }
    }
    if ($errs) { keep_input(); keep_field_errors($errs); flash('The article was not saved. Fix the fields marked below.', 'error'); redirect('kb.php?edit=' . $id); }
    $vals = [$title, $category !== '' ? $category : null, $body !== '' ? $body : null, !empty($_POST['is_public']) ? 1 : 0];
    if ($id) {
        $vals[] = $id;
        db()->prepare('UPDATE kb_articles SET title=?, category=?, body=?, is_public=? WHERE id=?')->execute($vals);
        audit($user['id'], 'kb.update', 'kb_article', $id, $title);
    } else {
        // A new article sent twice (Enter pressed again while it loads) is created once.
        $kbKey = web_idem_key($_POST);
        if (($firstId = web_once_get($kbKey)) !== null) {
            flash('This article was already saved (the form was sent twice), so it was saved once.', 'info');
            redirect('kb.php?id=' . $firstId);
        }
        $vals[] = $user['id'];
        db()->prepare('INSERT INTO kb_articles (title, category, body, is_public, created_by) VALUES (?,?,?,?,?)')->execute($vals);
        $id = (int)db()->lastInsertId();
        web_once_set($kbKey, $id);
        audit($user['id'], 'kb.create', 'kb_article', $id, $title);
    }
    flash('Article saved.');
    redirect('kb.php?id=' . $id);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_article'])) {
    require_perm('templates');
    $id = (int)($_POST['id'] ?? 0);
    $s = db()->prepare('SELECT title FROM kb_articles WHERE id = ?');
    $s->execute([$id]);
    $title = $s->fetchColumn();
    $del = db()->prepare('DELETE FROM kb_articles WHERE id = ?');
    $del->execute([$id]);
    if ($title === false || $del->rowCount() === 0) { flash('That article was already deleted.', 'error'); redirect('kb.php'); }
    audit($user['id'], 'kb.delete', 'kb_article', $id, (string)$title);
    flash('Article deleted permanently.');
    redirect('kb.php');
}

/* ---- Editor (new/edit) ---- */
if (isset($_GET['edit']) && can('templates')) {
    $a = ['id' => 0, 'title' => '', 'category' => '', 'body' => '', 'is_public' => 0];
    if ((int)$_GET['edit'] > 0) {
        $s = db()->prepare('SELECT * FROM kb_articles WHERE id = ?');
        $s->execute([(int)$_GET['edit']]);
        if (!($a = $s->fetch())) { flash('Article not found.', 'error'); redirect('kb.php'); }
    }
    // After a refused save, show what was typed rather than the stored article.
    if (isset($GLOBALS['OLD_INPUT']['save_article'])) {
        foreach (['title', 'category', 'body'] as $k) $a[$k] = old_in($k);
        $a['is_public'] = old_in('is_public') === '1' ? 1 : 0;
    }
    page_header($a['id'] ? 'Edit article' : 'New article', $user, 'kb');
    ?>
    <p><a href="kb.php" style="display:inline-flex; align-items:center; min-height:2.75rem;"><span aria-hidden="true">&larr; </span>Knowledge base</a></p>
    <h1><?= $a['id'] ? 'Edit article' : 'New article' ?></h1>
    <form method="post" action="kb.php" class="stacked"><?= csrf_field() ?><?= web_idem_field() ?>
      <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
      <p class="hint">Fields marked * are required.</p>
      <div class="grid grid-2">
        <div class="field"><label for="title">Title <?= required_mark() ?></label><input type="text" id="title" name="title" required maxlength="255" value="<?= h($a['title']) ?>" <?= field_aria('title') ?>><?= field_error('title') ?></div>
        <div class="field"><label for="category">Category</label><input type="text" id="category" name="category" maxlength="80" value="<?= h($a['category'] ?? '') ?>" placeholder="e.g. JAWS, Device care, Policy" <?= field_aria('category') ?>><?= field_error('category') ?></div>
      </div>
      <div class="field"><label for="body">Content</label>
        <span class="hint" id="body-hint">Plain text; blank lines separate paragraphs. Start a line with “## ” for a section heading, “- ” for a bullet point, or “1. ” for a numbered step. Up to 60,000 characters.</span>
        <textarea id="body" name="body" rows="14" <?= field_aria('body', 'body-hint') ?>><?= h($a['body'] ?? '') ?></textarea><?= field_error('body') ?></div>
      <div class="field"><label><input type="checkbox" name="is_public" value="1" <?= !empty($a['is_public']) ? 'checked' : '' ?>> Publish to client portals (visible to clients)</label></div>
      <button type="submit" name="save_article" value="1" class="btn btn-primary">Save article</button>
      <?php if ($a['id']): ?>
      <button type="submit" name="delete_article" value="1" class="btn btn-danger" onclick="return confirm('Delete this article permanently? It does not go to the trash, so this cannot be undone.');">Delete permanently</button>
      <?php endif; ?>
    </form>
    <?php page_footer(); exit;
}

/* ---- View one article ---- */
if (isset($_GET['id'])) {
    $s = db()->prepare('SELECT a.*, u.name AS author FROM kb_articles a LEFT JOIN users u ON u.id = a.created_by WHERE a.id = ?');
    $s->execute([(int)$_GET['id']]);
    $a = $s->fetch();
    if (!$a) { flash('Article not found.', 'error'); redirect('kb.php'); }
    page_header($a['title'], $user, 'kb');
    ?>
    <p><a href="kb.php" style="display:inline-flex; align-items:center; min-height:2.75rem;"><span aria-hidden="true">&larr; </span>Knowledge base</a></p>
    <h1><?= h($a['title']) ?></h1>
    <p class="muted"><?= $a['category'] ? h($a['category']) . ' · ' : '' ?>updated <?= fmt_date($a['updated_at']) ?><?= $a['author'] ? ' · ' . h($a['author']) : '' ?>
      <?= $a['is_public'] ? ' · <span class="badge badge-closed">Shared with clients</span>' : '' ?></p>
    <?php if (can('templates')): ?><div class="page-actions"><a class="btn btn-secondary btn-sm" href="kb.php?edit=<?= (int)$a['id'] ?>">Edit<span class="visually-hidden"> <?= h($a['title']) ?></span></a></div><?php endif; ?>
    <?php // "## " lines become section headings and "- " / "1. " lines lists (see the editor hint). ?>
    <article class="card"><?= plain_text_html((string)$a['body'], 2, false) ?></article>
    <?php page_footer(); exit;
}

/* ---- List / search ---- */
$q = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$where = []; $args = [];
if ($q !== '') {
    foreach (array_slice(preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6) as $w) {
        $where[] = '(title LIKE ? OR category LIKE ? OR body LIKE ?)';
        $like = '%' . $w . '%'; array_push($args, $like, $like, $like);
    }
}
$sql = 'SELECT id, title, category, is_public, updated_at FROM kb_articles';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY category IS NULL, category, title LIMIT 500';
$st = db()->prepare($sql);
$st->execute($args);
$articles = $st->fetchAll();

page_header('Knowledge base', $user, 'kb');
?>
<h1>Knowledge base</h1>
<p class="muted">How-to guides, tip sheets, and device references. Articles marked public also appear on clients' portals.</p>

<div class="page-actions">
  <form method="get" action="kb.php" role="search" class="inline-form" aria-label="Search the knowledge base">
    <label for="q" class="visually-hidden">Search articles in the knowledge base</label>
    <input type="search" id="q" name="q" value="<?= h($q) ?>" placeholder="Search articles…" minlength="2">
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
  </form>
  <?php if (can('templates')): ?><a class="btn btn-primary btn-sm" href="kb.php?edit=0">New article</a><?php endif; ?>
  <?php if (ai_feature_enabled('kb')): ?><a class="btn btn-secondary btn-sm" href="kb_ask.php">Ask the knowledge base</a><?php endif; ?>
</div>

<?php if (!$articles): ?>
<p class="muted"><?= $q !== '' ? 'No articles match your search.' : 'No articles yet.' ?></p>
<?php else: ?>
<div class="table-scroll" tabindex="0" role="region" aria-label="Knowledge base articles">
<table>
  <caption class="visually-hidden">Knowledge base articles</caption>
  <thead><tr><th scope="col">Title</th><th scope="col">Category</th><th scope="col">Updated</th><th scope="col">Portal</th></tr></thead>
  <tbody>
  <?php foreach ($articles as $a): ?>
    <tr>
      <td><a href="kb.php?id=<?= (int)$a['id'] ?>"><?= h($a['title']) ?></a></td>
      <td><?= h($a['category'] ?? '—') ?></td>
      <td><?= fmt_date($a['updated_at']) ?></td>
      <td><?= $a['is_public'] ? '<span class="badge badge-closed">Public</span>' : '<span class="muted">Staff only</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php page_footer();
