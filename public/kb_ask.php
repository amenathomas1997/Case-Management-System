<?php
// Knowledge-base assistant: answers a question grounded in the knowledge-base
// articles (retrieval → the AI answers only from the retrieved articles and
// cites them). Governed by ai_run(); KB content is staff guidance, not PHI.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();

if (!ai_feature_enabled('kb')) {
    page_header('Ask the knowledge base', $user, 'kb');
    echo '<div class="card"><h1>Ask the knowledge base</h1><p class="muted">This AI tool is turned off. An administrator can enable it under AI assistant settings.</p></div>';
    page_footer();
    exit;
}

// Words too common to say which article a question is about.
const KB_STOP_WORDS = ['the', 'and', 'for', 'are', 'but', 'not', 'you', 'your', 'our', 'can', 'could', 'should', 'would', 'will',
    'what', 'when', 'where', 'which', 'who', 'whom', 'whose', 'why', 'how', 'does', 'did', 'doing', 'done', 'has', 'have', 'had',
    'was', 'were', 'been', 'being', 'this', 'that', 'these', 'those', 'with', 'from', 'into', 'onto', 'about', 'after', 'before',
    'there', 'here', 'then', 'than', 'them', 'they', 'their', 'its', 'his', 'her', 'him', 'she', 'any', 'all', 'some', 'more',
    'most', 'other', 'such', 'only', 'also', 'just', 'very', 'get', 'got', 'set', 'use', 'using', 'need', 'make', 'way', 'want',
    'like', 'please', 'tell', 'know', 'one', 'out', 'off', 'via', 'per'];

$question = trim(is_string($_POST['question'] ?? null) ? $_POST['question'] : '');
// Optional: when no article covers the question, answer from the model's general knowledge,
// clearly marked as such (the default stays article-only).
$general = !empty($_POST['general']);
$answer = null;
$answerGeneral = false;
$used = [];
$noMatch = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ask'])) {
    // Search terms: the question's words (letters and digits only, so they hold
    // no LIKE wildcards), minus short and common words, up to 8 distinct ones. A
    // plural's final "s" is dropped so "profiles" also finds "profile".
    $terms = [];
    foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($question), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
        if (mb_strlen($w) < 3 || in_array($w, KB_STOP_WORDS, true)) continue;
        if (mb_strlen($w) > 4 && str_ends_with($w, 's')) $w = mb_substr($w, 0, -1);
        $terms[(string)$w] = true;
    }
    $terms = array_map('strval', array_slice(array_keys($terms), 0, 8));
    $articles = [];
    if ($question === '') {
        set_form_errors(['question' => 'Type a question to ask.']);
    } elseif (($qLen = mb_strlen($question)) > 2000) {
        // Same limit as the app's knowledge-base question; refused, never cut short.
        set_form_errors(['question' => 'Shorten the question to 2,000 characters or fewer (it has ' . number_format($qLen) . ').']);
    } elseif (!$terms) {
        set_form_errors(['question' => 'That question has no words to search the articles for. Include the key words, for example the product or task name.']);
    } else {
        // Rank by how many terms an article contains (a term in the title counts
        // twice), then by most recently updated. COALESCE: a NULL category or body
        // counts as "no match" and never turns the score NULL.
        $score = []; $args = [];
        foreach ($terms as $w) {
            $score[] = '(CASE WHEN title LIKE ? THEN 2 WHEN COALESCE(category, \'\') LIKE ? OR COALESCE(body, \'\') LIKE ? THEN 1 ELSE 0 END)';
            $l = '%' . $w . '%'; array_push($args, $l, $l, $l);
        }
        $st = db()->prepare('SELECT id, title, category, body FROM (SELECT id, title, category, body, updated_at, ' . implode(' + ', $score) . ' AS score FROM kb_articles) k
            WHERE k.score > 0 ORDER BY k.score DESC, k.updated_at DESC LIMIT 6');
        $st->execute($args);
        $articles = $st->fetchAll();
        if (!$articles && !$general) {
            // Not an error: the assistant only knows the staff knowledge base, and nothing in it
            // mentions these words. Say that, show the words, and offer the next steps.
            $noMatch = 'No article in the Staff knowledge base mentions ' . implode(', ', array_map(fn($t) => '“' . $t . '”', $terms)) . '. The assistant answers only from your own articles.';
        }
    }

    if (!$articles && $general && $question !== '' && $terms) {
        $res = ai_run('kb',
            ai_system_base() . ' No knowledge-base article covers this question, and the user asked for a general answer. Answer from general knowledge about assistive technology, accessibility and vocational rehabilitation practice, briefly and carefully. '
                . 'Begin with the sentence "No knowledge-base article covers this, so this answer is general knowledge, not agency procedure." Do not invent agency policies, prices, names or contacts.'
                . ai_plain_text_rule() . ' Number the steps of a procedure.',
            "Question: {$question}", 1200, null, (int)$user['id']);
        if ($res['ok']) { $answer = $res['text']; $answerGeneral = true; flash('A general-knowledge answer is ready below, under the heading "Answer".'); }
        else flash($res['error'], 'error');
    }

    if ($articles) {
        $ctx = '';
        foreach ($articles as $a) {
            $used[] = $a;
            $ctx .= "Article: " . $a['title'] . ($a['category'] ? " ({$a['category']})" : '') . "\n" . mb_strimwidth((string)$a['body'], 0, 2500, '…') . "\n\n";
        }
        $res = ai_run('kb',
            ai_system_base() . ' Answer the question using ONLY the knowledge-base articles provided below. Cite the article titles you used. '
                . 'If the articles do not contain the answer, say so plainly and suggest what to add. Do not use outside knowledge.'
                . ai_plain_text_rule() . ' Number the steps of a procedure.',
            "Question: {$question}\n\nArticles:\n{$ctx}", 1200, null, (int)$user['id']);
        if ($res['ok']) { $answer = $res['text']; flash('The answer is ready below, under the heading "Answer".'); }
        else flash($res['error'], 'error');
    }
}

page_header('Ask the knowledge base', $user, 'kb');
?>
<p><a href="kb.php" style="display:inline-flex; align-items:center; min-height:2.75rem;"><span aria-hidden="true">&larr; </span>Knowledge base</a></p>
<h1>Ask the knowledge base</h1>
<p class="muted">Answers come from the articles in your Staff knowledge base and cite them. The assistant does not search the internet or other sources: if no article covers a question, it says so, and you can add an article on the Knowledge page. Tick the box below to allow a general-knowledge answer instead, clearly marked as not agency procedure.</p>

<form method="post" action="kb_ask.php" class="stacked"><?= csrf_field() ?>
  <?php // One announcement (4.29.0): the summary, tied to the field's own error; no flash as well. ?>
  <?= form_error_summary(['question' => 'Your question']) ?>
  <div class="field"><label for="question">Your question</label>
    <input type="text" id="question" name="question" value="<?= h($question) ?>" required placeholder="e.g. How do I set up JAWS voice profiles?" <?= field_aria('question') ?>><?= field_error('question') ?></div>
  <div class="field"><label><input type="checkbox" name="general" value="1"<?= $general ? ' checked' : '' ?>> If no article covers it, answer from general knowledge (marked as not agency procedure)</label></div>
  <button type="submit" name="ask" value="1" class="btn btn-primary">Ask</button>
</form>
<?php if ($noMatch !== ''): ?>
<section class="card" aria-labelledby="nomatch-h" style="margin-top:1rem">
  <h2 id="nomatch-h">No article covers this yet</h2>
  <p><?= h($noMatch) ?></p>
  <ul>
    <li>Try fewer or more general words, for example the product name or the task.</li>
    <li><a href="kb.php">Add an article</a> on the Knowledge page, then ask again.</li>
    <li>Or tick “answer from general knowledge” above and ask again.</li>
  </ul>
</section>
<script>document.addEventListener("DOMContentLoaded",function(){var s=document.getElementById("nomatch-h");if(s){s.setAttribute("tabindex","-1");s.focus();}});</script>
<?php endif; ?>

<?php if ($answer !== null): ?>
<section class="card" aria-labelledby="ans-h" style="margin-top:1rem">
  <h2 id="ans-h">Answer<?= $answerGeneral ? ' (general knowledge, not from your articles)' : '' ?></h2>
  <?= plain_text_html($answer) ?>
  <?php if ($used): ?>
  <p class="hint">Grounded in: <?php foreach ($used as $i => $a): ?><?= $i ? ', ' : '' ?><a href="kb.php?id=<?= (int)$a['id'] ?>"><?= h($a['title']) ?></a><?php endforeach; ?></p>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php page_footer();
