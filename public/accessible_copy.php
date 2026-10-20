<?php
// The accessible HTML copy of an invoice, statement, funder report or outcome report (4.30.0),
// for the people the PDF is sent to, who have no staff sign-in. The PDF's first line links here
// with a signed, expiring key (inc/app_pdf.php, doc_link_create()). Read-only: it shows the copy
// kept when the PDF was made, nothing else, and each opening is listed in the audit trail.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/app_pdf.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$token = is_string($_GET['k'] ?? null) ? trim($_GET['k']) : '';
$copy = doc_link_open($token);

if (isset($copy['html'])) {
    $meta = $copy['meta'];
    audit(null, 'document.link_view', (string)($meta['entity'] ?? 'document'), isset($meta['entity_id']) ? (int)$meta['entity_id'] : null,
        'Accessible copy opened by link' . (($meta['detail'] ?? '') !== '' ? ': ' . $meta['detail'] : ''));
    header('Content-Type: text/html; charset=UTF-8');
    echo $copy['html'];
    exit;
}

$expired = ($copy['error'] ?? '') === 'expired';
http_response_code($expired ? 410 : 404);
header('Content-Type: text/html; charset=UTF-8');
$title = $expired ? 'This link has expired' : 'This link does not open a document';
$org = (string)brand('org');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<style>body{font:1rem/1.6 system-ui,sans-serif;color:#1a1a2e;background:#fff;max-width:44rem;margin:auto;padding:1rem}p{max-width:75ch}</style>
</head><body><main aria-label="Main content">
<h1><?= h($title) ?></h1>
<p><?= $expired
    ? 'Links to accessible copies work for ' . DOC_LINK_DAYS . ' days after the document is made. Ask ' . h($org !== '' ? $org : 'the sender') . ' to send the document again, and its new link will work.'
    : 'Check that the whole link was copied, including the part after k=. If it still does not open, ask ' . h($org !== '' ? $org : 'the sender') . ' to send the document again.' ?></p>
</main></body></html>
