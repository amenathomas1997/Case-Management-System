<?php
// The app icon (4.3.0): the one an administrator uploaded on Settings, else the icons in
// assets/ (the installer's, in the brand colours). Public: an icon names no one.
require __DIR__ . '/inc/core.php';   // not bootstrap.php: no session, so a cached reply carries no cookie
$sizes = [180 => 'apple-touch-icon.png', 192 => 'icon-192.png', 512 => 'icon-512.png', 1024 => 'icon-1024.png'];
$s = (int)($_GET['s'] ?? 192);
if (!isset($sizes[$s])) $s = 192;
$file = brand_icon_path($s);
if ($file === null || !is_file($file)) $file = __DIR__ . '/assets/' . $sizes[$s];
if (!is_file($file)) $file = __DIR__ . '/assets/icon-512.png';   // a checkout without the installer's 1024 icon
if (!is_file($file)) { http_response_code(404); exit; }
header('Content-Type: image/png');
header('Cache-Control: public, max-age=3600');
header('Content-Length: ' . filesize($file));
readfile($file);
