<?php
declare(strict_types=1);
// File exchange (4.24.0): documents shared with the client's portal or with their VR counselor, files
// attached to messages in either portal and from staff, and a check that each upload's content is what
// its name says (an extension alone can be renamed). Files are stored as before: outside the web folder,
// under a random name, served only to whoever may see them.

/** The file types people outside the agency may send (no macros: no .doc or .xls). */
const EXCHANGE_TYPES = ['pdf', 'docx', 'xlsx', 'txt', 'rtf', 'png', 'jpg', 'jpeg'];
/** The file types staff may upload. */
const STAFF_UPLOAD_TYPES = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'csv', 'png', 'jpg', 'jpeg'];

/**
 * Whether a stored upload's first bytes match its extension (a renamed program or page is refused). $staff
 * also accepts what staff legitimately have: a .doc saved as RTF, a .xls that is really a web-page or text
 * export from an agency system, and Unicode (UTF-16) text. Staff files are only ever sent as downloads.
 */
function upload_content_ok(string $path, string $ext, bool $staff = false): bool {
    $head = (string)@file_get_contents($path, false, null, 0, 8192);
    if ($head === '') return in_array($ext, ['txt', 'csv'], true) && is_file($path); // an empty text file
    if ($staff) {
        $utf16 = str_starts_with($head, "\xFF\xFE") || str_starts_with($head, "\xFE\xFF");
        if ($ext === 'doc' && str_starts_with($head, '{\\rtf')) return true;
        if ($ext === 'xls' && ($utf16 || !str_contains($head, "\0"))) return true;
        if (in_array($ext, ['txt', 'csv'], true) && $utf16) return true;
    }
    switch ($ext) {
        case 'heic': case 'heif': return substr($head, 4, 4) === 'ftyp';
        case 'pdf': return str_starts_with($head, '%PDF-');
        case 'png': return str_starts_with($head, "\x89PNG\r\n\x1a\n");
        case 'jpg': case 'jpeg': return str_starts_with($head, "\xFF\xD8\xFF");
        case 'rtf': return str_starts_with($head, '{\\rtf');
        case 'doc': case 'xls': case 'ppt': return str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
        case 'docx': case 'xlsx': case 'pptx':
            if (!str_starts_with($head, "PK\x03\x04")) return false;
            if (!class_exists('ZipArchive')) return true;
            $z = new ZipArchive();
            if ($z->open($path) !== true) return false;
            $ok = $z->locateName('[Content_Types].xml') !== false && $z->locateName(['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml'][$ext]) !== false;
            $z->close();
            return $ok;
        case 'txt': case 'csv':
            // Text: no NUL bytes, and nothing a browser would take for a page or a script.
            return !str_contains($head, "\0") && ($staff || !preg_match('/^\s*<(?:!doctype|html|script|svg|\?xml)/i', $head));
    }
    return false;
}

/**
 * Store one uploaded file ($_FILES entry) as a client document. $via is 'portal', 'counselor' or 'staff':
 * the types, size and daily and total limits follow from it. A portal upload is shared with the client,
 * a counselor's with the counselor. Returns ['id' => ?int, 'error' => ?string] (error in plain words).
 */
function exchange_save_upload(array $file, int $clientId, string $title, string $via, ?int $staffId = null, ?string $category = null): array {
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $orig = is_string($file['name'] ?? null) ? $file['name'] : '';
    if ($err === UPLOAD_ERR_NO_FILE || $orig === '') return ['id' => null, 'error' => 'Please choose a file.'];
    $staff = $via === 'staff';
    $maxMb = $staff ? 25 : 15;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return ['id' => null, 'error' => 'That file is too large (' . $maxMb . ' MB maximum).'];
    if ($err !== UPLOAD_ERR_OK) return ['id' => null, 'error' => 'The upload was interrupted. Please try again.'];
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!$staff && in_array($ext, ['doc', 'xls'], true)) return ['id' => null, 'error' => 'Older Word (.doc) and Excel (.xls) files cannot be accepted. Save the file as .docx, .xlsx or PDF, then send it again.'];
    if (!in_array($ext, $staff ? STAFF_UPLOAD_TYPES : EXCHANGE_TYPES, true)) return ['id' => null, 'error' => 'That file type is not allowed. Use PDF, Word, Excel, text or image files.'];
    $size = (int)($file['size'] ?? 0);
    if ($size > $maxMb * 1024 * 1024) return ['id' => null, 'error' => 'That file is too large (' . $maxMb . ' MB maximum).'];
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) return ['id' => null, 'error' => 'The upload was interrupted. Please try again.'];
    if (!upload_content_ok($tmp, $ext, $staff)) return ['id' => null, 'error' => 'That file is not really the type its name says, so it was not accepted. Save it again, or send it as a PDF.'];
    if (!$staff) {
        // Caps per client for what arrives from outside: 100 MB a day and 500 MB in all, as on the portal.
        $q = db()->prepare("SELECT path, created_at >= ? AS today FROM client_documents WHERE client_id = ? AND (category = 'Client Upload' OR uploaded_via IN ('portal', 'counselor'))");
        $q->execute([date('Y-m-d 00:00:00'), $clientId]);
        $day = 0; $all = 0;
        foreach ($q->fetchAll() as $u) {
            $f = cfg('upload_dir') . '/' . basename((string)$u['path']);
            $b = is_file($f) ? (int)filesize($f) : 0;
            $all += $b; if ($u['today']) $day += $b;
        }
        if ($day + $size > 100 * 1024 * 1024) return ['id' => null, 'error' => 'A lot of files were shared today. Please try again tomorrow.'];
        if ($all + $size > 500 * 1024 * 1024) return ['id' => null, 'error' => 'The storage limit for shared files has been reached. Please contact us.'];
    }
    $safe = (string)preg_replace('/[^A-Za-z0-9._ -]/', '_', $orig);
    if (strlen($safe) > 190) $safe = substr($safe, 0, 189 - strlen($ext)) . '.' . $ext;
    $dir = cfg('upload_dir');
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    // The test harness (command line only) posts files that were not uploaded by a browser: they are copied.
    $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dir . '/' . $stored) : (PHP_SAPI === 'cli' && getenv('CMS_TEST_FILES') !== false && copy($tmp, $dir . '/' . $stored));
    if (!$moved) return ['id' => null, 'error' => 'The file could not be saved. Please try again.'];
    $category ??= $via === 'portal' ? 'Client Upload' : ($via === 'counselor' ? 'From VR counselor' : 'Other');
    try {
        db()->prepare('INSERT INTO client_documents (client_id, category, title, orig_name, path, uploaded_by, portal_shared, counselor_shared, uploaded_via) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$clientId, $category, mb_substr($title !== '' ? $title : mb_scrub($orig, 'UTF-8'), 0, 190), $safe, $stored, $staffId,
                $via === 'portal' ? 1 : 0, $via === 'counselor' ? 1 : 0, $via]);
        return ['id' => (int)db()->lastInsertId(), 'error' => null];
    } catch (Throwable $e) {
        @unlink($dir . '/' . $stored); // never a file on disk with no record
        error_log('Shared file not recorded: ' . get_class($e) . ' (code ' . $e->getCode() . ')');
        return ['id' => null, 'error' => 'The file could not be saved. Please try again.'];
    }
}

/** Whether the client's portal shows this document: resources, the client's own, and anything shared with them. */
function doc_portal_visible(array $doc): bool {
    return in_array($doc['category'] ?? '', ['Resource', 'Client Upload'], true) || (int)($doc['portal_shared'] ?? 0) === 1;
}

/** One of a client's documents, or null. */
function client_document(int $docId, int $clientId): ?array {
    $q = db()->prepare('SELECT * FROM client_documents WHERE id = ? AND client_id = ?');
    $q->execute([$docId, $clientId]);
    return $q->fetch() ?: null;
}

/** Send a stored document as a download and stop (false when its file is gone). */
function exchange_send_file(array $doc): bool {
    $path = cfg('upload_dir') . '/' . basename((string)$doc['path']);
    if (!is_file($path)) return false;
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', (string)$doc['orig_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

/** Attach a document to a message (the message was just posted). */
function message_attach(int $messageId, int $docId): void {
    db()->prepare('UPDATE messages SET document_id = ? WHERE id = ?')->execute([$docId, $messageId]);
}

/** Share or stop sharing one of a client's documents with the client ('portal') or their counselor ('counselor'). */
function document_set_shared(int $docId, int $clientId, string $with, bool $on, int $actor): bool {
    if (!in_array($with, ['portal', 'counselor'], true)) return false;
    $col = $with === 'portal' ? 'portal_shared' : 'counselor_shared';
    $doc = client_document($docId, $clientId);
    if (!$doc) return false;
    if ((int)$doc[$col] === ($on ? 1 : 0)) return true; // already so (a second press, or another tab)
    db()->prepare("UPDATE client_documents SET $col = ? WHERE id = ? AND client_id = ?")->execute([$on ? 1 : 0, $docId, $clientId]);
    audit($actor, 'document.' . ($on ? 'share_' : 'unshare_') . $with, 'client', $clientId, 'document #' . $docId);
    return true;
}
