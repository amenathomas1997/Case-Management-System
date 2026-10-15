<?php
declare(strict_types=1);
require __DIR__ . '/../public/inc/navigation.php';
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function __(string $s): string { return $s; }
$allowed = true;
function can(string $permission): bool { return $GLOBALS['allowed']; }
function check(bool $ok, string $name): void { if (!$ok) throw new RuntimeException($name); echo "PASS: $name\n"; }
$_SERVER['SCRIPT_NAME']='/cms/track_edit.php'; $_GET=['id'=>42];
$html=cms_breadcrumb_html('Edit <training>', 'clients', [['client.php?id=7','Client & name'], ['track.php?id=42','Training']], true);
$d=new DOMDocument(); @$d->loadHTML($html);$x=new DOMXPath($d);
check($x->query('//nav[@aria-label="Breadcrumb"]')->length===1, 'Breadcrumb has a named navigation landmark');
check($x->query('//a')->length===4, 'Track edit exposes dashboard, section, client and track parents');
check($x->query('//*[@aria-current="page"]')->length===1 && $x->query('//a[@aria-current]')->length===0, 'Current page is announced and is not a reload link');
check(str_contains($html,'Edit &lt;training&gt;') && str_contains($html,'Client &amp; name'), 'Record names and titles are escaped');
$html=cms_breadcrumb_html('Page','', [['javascript:alert(1)','Unsafe'],['https://other.test','External']],true);
check(!str_contains($html,'javascript:') && !str_contains($html,'other.test'), 'External and executable parent URLs are excluded');
$allowed=false;$html=cms_breadcrumb_html('Page','clients',[],true);
check(!str_contains($html,'clients.php'), 'Section link respects menu permissions');
$_SERVER['SCRIPT_NAME']='/cms/clients.php';$_GET=[];$allowed=true;
$html=cms_breadcrumb_html('Clients','clients',[],true);
check(substr_count($html,'<a ')===1, 'Top-level page does not duplicate itself as a parent');
$_SERVER['SCRIPT_NAME']='/cms/index.php';
check(!str_contains(cms_breadcrumb_html('Dashboard','dashboard',[],true),'<a '), 'Dashboard has no self-link breadcrumb');
check(!str_contains(cms_breadcrumb_html('Referral form','',[],false),'<a '), 'Anonymous page does not advertise staff ancestors');
