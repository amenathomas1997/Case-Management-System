<?php
declare(strict_types=1);
// Load only the real flash/error helpers, without configuration, sessions, or DB.
$source = file_get_contents(__DIR__ . '/../public/inc/bootstrap.php');
$start = strpos($source, 'function flash(');
$end = strpos($source, '/* ---------- CSRF', $start);
eval(substr($source, $start, $end - $start));
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
require __DIR__ . '/../public/inc/i18n.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); echo "PASS: $message\n"; }
$_SESSION = ['lang' => 'es']; enable_public_translation();
set_form_errors(['name' => "Enter a name & don't use <script>markup</script>."]);
$html = field_error('name') . form_error_summary(['name'=>'Name']);
$dom = new DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
$xp = new DOMXPath($dom);
check(!str_contains($html, '&lt;span') && !str_contains($html, '&amp;amp;'), 'Translated form errors are not double escaped');
check($xp->query('//span[@lang="en"]')->length > 0, 'Untranslated error text retains its language annotation');
check($xp->query('//p//script')->length===0, 'Form errors still escape embedded markup');
flash_ui('Please type a message first.', 'error');
$flashes=take_flashes();
check($flashes[0]['msg']==='Please type a message first.' && $flashes[0]['translate']===true, 'Portal flashes store interface text for translation at render time');
// AI answers and articles become escaped, structured HTML; language names map to BCP-47 codes.
require __DIR__ . '/../public/inc/ai.php';
$plain = plain_text_html("### Steps\n- Open <script>alert(1)</script>\n- Save\n\n1. First\n2. Second");
check(str_contains($plain, '&lt;script&gt;') && !str_contains($plain, '<script'), 'plain_text_html escapes markup in the text');
check(str_contains($plain, '<h3>Steps</h3>') && str_contains($plain, '<ul><li>Open') && str_contains($plain, '<ol><li>First</li><li>Second</li></ol>'),
    'plain_text_html builds h3, ul and ol from "### ", "- " and "1." lines');
check(translate_lang_code('Brazilian Portuguese') === 'pt-BR' && translate_lang_code('Portuguese (Brazil)') === 'pt-BR' && translate_lang_code('Klingon') === '',
    'translate_lang_code maps Brazilian Portuguese to pt-BR and an unknown language to an empty code');
// The AI endpoint guard refuses plain http and any loopback, private, link-local
// or metadata address (literal addresses only, so no DNS lookup is made here).
$refused = array_filter(['https://127.0.0.1/', 'https://10.0.0.5/', 'https://[::1]/', 'https://169.254.169.254/', 'http://8.8.8.8/'],
    fn(string $u): bool => ai_endpoint_ips($u) !== null);
check($refused === [] && ai_endpoint_ips('https://8.8.8.8/') === ['8.8.8.8'],
    'ai_endpoint_ips refuses http and internal addresses and returns a public https address');
