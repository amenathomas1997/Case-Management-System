<?php
// CLI-only synthetic render; never loads production configuration or data.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function cfg($k) { return ['name'=>'Accessibility test fixture','session_timeout_minutes'=>30,'support_email'=>'help@example.test','mail_from'=>'notifications@example.test','backup_email'=>'private-backups@example.test'][$k] ?? ''; }
function brand($k) { return ['short'=>'Synthetic Case Management','color'=>'#1e40af','color_dark'=>'#1e3a8a','accent'=>'#0f766e'][$k] ?? ''; }
function can($p) { return true; }
function db() { throw new RuntimeException('No database in fixture'); }
function csrf_field() { return ''; }
function csrf_token() { return 'synthetic'; }
function take_flashes() { return []; }
function ai_feature_enabled($f) { return $f === 'voice'; }
function voice_available() { return true; }
if (!function_exists('support_contact_html')) { function support_contact_html(bool $capital = true): string { return 'Email <a href="mailto:help@example.test">help@example.test</a>'; } }
const APP_VERSION = 'test';
require __DIR__ . '/../public/inc/i18n.php';
require __DIR__ . '/../public/inc/layout.php';
page_header('Accessibility fixture', ['id'=>1], 'tasks');
?>
<h1>Accessibility fixture</h1>
<p>Synthetic content only. This page renders the actual shared layout and styles without loading application configuration or client data.</p>
<section class="card"><h2>Form controls</h2>
<form class="stacked"><div class="grid grid-2">
<div class="field"><label for="title">Task title</label><input id="title" type="text" placeholder="Enter a title"></div>
<div class="field"><label for="search">Search tasks</label><input id="search" type="search"></div>
<div class="field"><label for="due">Due date</label><input id="due" type="date"></div>
<div class="field"><label for="status">Task status</label><select id="status"><option>Open</option><option>Done</option></select></div>
</div><label><input type="checkbox">Send a copy to me</label>
<button type="button" class="btn btn-primary">Save task</button> <button type="button" class="btn-link">Remove</button>
</form></section>
<section class="card" style="background:var(--surface-2,#f4f6fb)"><h2>Secondary surface</h2><p class="muted">Readable supporting text in both themes.</p><a href="#results">View results</a></section>
<h2 id="results">Results</h2><div class="table-scroll" tabindex="0" role="region" aria-labelledby="results"><table><caption>Tasks</caption><tr><th scope="col">Task</th><th scope="col">Status</th><th scope="col">Action</th></tr><tr><td>Sample task</td><td><span class="badge badge-active">In Progress</span></td><td><a href="#title">Edit sample task</a></td></tr></table></div>
<?php page_footer(); ?>
