<?php
// Authenticated download of assessment attachments stored outside the docroot.
// Same gate as every other client-record page: clients permission + caseload scope.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('clients');

// A missing record or file is shown as a normal page (title, language and a
// link back), sent before any file header. The client record is linked only
// once access to it has been checked.
$notAvailable = function (string $message, ?int $clientId = null, string $tab = 'overview') use ($user): never {
    http_response_code(404);
    page_header('File not available', $user, 'clients');
    echo '<h1>File not available</h1>' . error_notice($message)
        . '<p>' . ($clientId ? '<a href="client.php?id=' . $clientId . '&amp;tab=' . h($tab) . '">Back to the client record</a>'
            : '<a href="clients.php">Back to the client list</a>') . '</p>';
    page_footer();
    exit;
};

if (!empty($_GET['sig'])) {
    // Client signature image captured on a work log
    $stmt = db()->prepare('SELECT w.signature_path, w.signed_name, w.track_id, t.client_id FROM work_logs w JOIN training_tracks t ON t.id = w.track_id WHERE w.id = ?');
    $stmt->execute([(int)$_GET['sig']]);
    $row = $stmt->fetch();
    if (!$row || !$row['signature_path']) $notAvailable('Signature not found. The work log may have been deleted or never signed.');
    scope_check_client_id((int)$row['client_id'], $user);
    $path = cfg('upload_dir') . '/' . basename($row['signature_path']);
    if (!is_file($path)) $notAvailable('The signature file is missing on the server. Ask an administrator to check the uploads folder.', (int)$row['client_id'], 'tracks');
    if (empty($_GET['raw'])) {
        // A page around the image, so the signature has a text alternative (the typed name)
        // and a way back (1.1.1, 2.4.2); the image below is fetched with raw=1.
        $who = trim((string)($row['signed_name'] ?? ''));
        page_header('Client signature', $user, 'clients');
        echo '<h1>Client signature</h1>'
            . '<p>' . ($who !== '' ? 'Signed by ' . h($who) : 'Signed on the work log') . ' (work log #' . (int)$_GET['sig'] . ').</p>'
            . '<p><img src="download.php?sig=' . (int)$_GET['sig'] . '&amp;raw=1" alt="Handwritten signature' . ($who !== '' ? ' of ' . h($who) : '') . ', as drawn on the work log" style="max-width:100%; height:auto; border:1px solid var(--border, #ccc); background:#fff;"></p>'
            . '<p><a href="track.php?id=' . (int)$row['track_id'] . '">Back to the training track</a></p>';
        page_footer();
        exit;
    }
    audit($user['id'], 'signature.view', 'client', (int)$row['client_id'], 'work log #' . (int)$_GET['sig']);
    session_write_close(); // the transfer must not hold the session lock for the user's other tabs
    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}
if (!empty($_GET['doc'])) {
    $stmt = db()->prepare('SELECT path AS attachment_path, orig_name AS attachment_name, title AS audit_label, client_id FROM client_documents WHERE id = ?');
    $stmt->execute([(int)$_GET['doc']]);
} else {
    $stmt = db()->prepare("SELECT attachment_path, attachment_name, CONCAT('assessment attachment: ', COALESCE(attachment_name, '')) AS audit_label, client_id FROM tech_assessments WHERE id = ?");
    $stmt->execute([(int)($_GET['assessment'] ?? 0)]);
}
$a = $stmt->fetch();
if (!$a || !$a['attachment_path']) $notAvailable('Attachment not found. It may have been deleted, or moved to the trash.');
scope_check_client_id((int)$a['client_id'], $user);

$path = cfg('upload_dir') . '/' . basename($a['attachment_path']);
if (!is_file($path)) $notAvailable('The file is missing on the server. Ask an administrator to check the uploads folder.', (int)$a['client_id'], !empty($_GET['doc']) ? 'documents' : 'assessments');
// Same trail the mobile app leaves when a client document is opened.
audit($user['id'], 'document.view', 'client', (int)$a['client_id'], (string)$a['audit_label']);

$mime = [
    'pdf' => 'application/pdf', 'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'txt' => 'text/plain', 'rtf' => 'application/rtf', 'csv' => 'text/csv',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt' => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
][strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

// A long download must not hold the session lock for the user's other tabs
// (or the keep-alive); nothing below writes to the session.
session_write_close();
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $a['attachment_name'] ?: 'attachment') . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
