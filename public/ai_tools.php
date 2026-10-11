<?php
// AI tools hub — a home for the general-purpose AI features (the client-specific
// ones live on the client's own pages). Each tool appears only when the AI
// assistant is configured and that feature is switched on.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();

page_header('AI tools', $user, 'ai_tools');
?>
<h1>AI tools</h1>
<?php if (!ai_enabled()): ?>
<p class="muted">The AI assistant is not configured. <?php if (can('admin')): ?><a href="ai.php">Set up the AI assistant</a>.<?php else: ?>Ask an administrator to enable it.<?php endif; ?></p>
<?php else: ?>
<p class="muted">Staff review every AI draft before it is used. <?= ai_redaction_on() ? 'Client identifiers are stripped before client data is sent to the provider.' : 'Identifier redaction is currently off.' ?></p>

<?php
// key => [title, description, href, perm-or-null]
$tools = [
    'remediate' => ['Accessible content &amp; remediation', 'Rewrite content in plain language, simplify, summarize, format for large print, or draft image alt text.', 'remediate.php', null],
    'kb' => ['Knowledge-base assistant', 'Ask a question and get an answer grounded in your knowledge-base articles, with citations.', 'kb_ask.php', null],
    'ask' => ['Ask your caseload', 'Ask a plain-language question and get an answer summarized over your own clients, goals and notes.', 'ask.php', 'clients'],
    'translate' => ['On-demand translation', 'Translate a document, report or message into another language.', 'translate.php', null],
    'curriculum' => ['Curriculum generator', 'Draft a training curriculum and skills for a specific assistive technology and skill level.', 'curriculum_ai.php', 'templates'],
    'risk' => ['Outcome risk flags', 'Surface clients at risk of non-completion from their activity and authorization data.', 'risk.php', 'analytics'],
];
$any = false;
foreach ($tools as $key => [$title, $desc, $href, $perm]):
    if (!ai_feature_enabled($key)) continue;
    if ($perm !== null && !can($perm)) continue;
    $any = true;
    // Only link tools whose page has been deployed.
    if (!is_file(__DIR__ . '/' . $href)) continue;
?>
<section class="card">
  <h2 style="margin-top:0"><a href="<?= h($href) ?>"><?= $title ?></a></h2>
  <p class="muted"><?= $desc ?></p>
</section>
<?php endforeach; ?>
<?php if (!$any): ?><p class="muted">No AI tools are switched on. An administrator can enable them under <a href="ai.php">AI assistant settings</a>.</p><?php endif; ?>
<?php endif; ?>
<?php page_footer();
