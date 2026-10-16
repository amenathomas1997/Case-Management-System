<?php
// Static checks, no database: each create form guards against a double submit,
// and no posted field is passed straight to a string function (name[]=x would
// raise a TypeError and a generic 400 page).
declare(strict_types=1);
function check(bool $ok, string $name): void { if (!$ok) throw new RuntimeException($name); echo "PASS: $name\n"; }
$src = fn(string $f): string => (string)file_get_contents(__DIR__ . '/../public/' . $f);
foreach (['incidents.php', 'procurement.php', 'contracts.php', 'track.php', 'messages.php', 'at_services.php', 'equipment.php',
        'client_edit.php', 'intake.php', 'kb.php', 'templates.php', 'curriculum.php'] as $f) {
    $s = $src($f);
    check(str_contains($s, 'web_idem_field()') && str_contains($s, 'web_once_get(') && str_contains($s, 'web_once_set('), "$f guards its create forms against a double submit");
}
foreach (['admin.php', 'users.php', 'programs.php', 'templates.php', 'federation.php'] as $f) {
    check(!preg_match('/(trim|strtolower|mb_strlen)\(\s*\$_POST\[/', $src($f)), "$f reads posted text only when it is a string");
}
