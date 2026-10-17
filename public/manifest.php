<?php
// The installable web app's manifest (4.3.0), from the branding on Settings, so a change of
// name or colour needs no file edit. Public: it names the system only.
require __DIR__ . '/inc/core.php';   // not bootstrap.php: no session, so a cached reply carries no cookie
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode([
    'name' => brand('name'), 'short_name' => brand('short'),
    'description' => 'Case management for ' . brand('org'),
    // Relative, so a site installed in a folder opens that folder, not the domain's root.
    'start_url' => 'index.php', 'scope' => './', 'display' => 'standalone',
    'background_color' => brand('color_dark'), 'theme_color' => brand('color_dark'),
    'icons' => [
        ['src' => 'icon.php?s=192', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => 'icon.php?s=512', 'sizes' => '512x512', 'type' => 'image/png'],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
