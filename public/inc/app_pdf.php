<?php
declare(strict_types=1);
require_once __DIR__ . '/fpdf/fpdf.php';

// Visual PDFs have a language; the accompanying HTML supplies semantic structure.
// Readers show and announce the document Title (set with SetTitle) rather than the
// file name.
//
// FPDF cannot tag a PDF (no structure tree), so (4.30.0):
//  - every letterhead PDF says what it is in its properties (title, subject, author,
//    language), and prints its text in reading order: one column at a time, never two
//    side-by-side cells per line, so text extraction and screen readers read each block whole;
//  - its first line gives a read-only link to the accessible HTML copy that anyone can open
//    without a staff sign-in (doc_link_create() below), valid for DOC_LINK_DAYS days.
class AppPdf extends FPDF {
    /** The document language, as a BCP 47 tag. */
    public string $docLang = 'en-US';

    protected function _putcatalog() {
        parent::_putcatalog();
        $this->_put('/Lang ' . $this->_textstring($this->docLang));
        $this->_put('/ViewerPreferences << /DisplayDocTitle true >>');
    }

    /** Title, subject, author and creator, in UTF-8 (so a dash or accent is kept as written). */
    public function describe(string $title, string $subject): void {
        $this->SetTitle($title, true);
        $this->SetSubject($subject, true);
        $org = function_exists('brand') ? (string)brand('org') : '';
        if ($org !== '') $this->SetAuthor($org, true);
        if (function_exists('cfg')) $this->SetCreator((string)cfg('name'), true);
    }

    /**
     * The first line of the PDF: where its accessible copy is. $link is what doc_link_create()
     * returned (a link that needs no sign-in), or null when none could be made, in which case
     * the signed-in copy at $staffUrl is named instead.
     */
    public function accessibleCopyLine(?array $link, string $staffUrl): void {
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(0, 0, 0);
        if ($link) {
            $this->MultiCell(0, 5, doc_pdf_text('Accessible version of this document (HTML), no sign-in needed, until ' . date('F j, Y', $link['expires']) . ':'), 0, 'L');
            // Written out in full, so it can be typed from a printed copy too; Write() also makes it a link.
            $this->SetTextColor(0, 0, 160);
            $this->Write(5, $link['url'], $link['url']);
            $this->SetTextColor(0, 0, 0);
            $this->Ln(7);
        } else {
            $this->Cell(0, 6, 'Accessible HTML version (staff sign-in required)', 0, 1, 'L', false, $staffUrl);
        }
    }
}

/** Core PDF fonts are Latin-1: UTF-8 punctuation is transliterated (never a failure). */
function doc_pdf_text(?string $s): string {
    $r = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s ?? '');
    return $r === false ? '' : $r;
}

/* ---------- Accessible copies outside readers can open (4.30.0) ----------
   An invoice or report PDF goes to people without a staff account (a VR agency's fiscal
   reviewer, a funder). When the PDF is made, the accessible HTML copy of exactly what it
   holds is kept as a file in the private upload directory, under a random id, and the PDF
   prints a link to it: accessible_copy.php?k=<id>.<expiry>.<signature>. The signature is an
   HMAC over the id and the expiry, with a key derived from the server's key file, so a link
   cannot be made up, extended or pointed at another copy. The copy is read-only, holds only
   what the PDF holds, and is removed once its DOC_LINK_DAYS days are over. */

const DOC_LINK_DAYS = 90;

/** The folder the copies are kept in, inside the private upload directory. */
function doc_link_dir(): string {
    return rtrim((string)cfg('upload_dir'), '/') . '/accessible_copies';
}

/** The signing key (derived, so it is never the key file's own value), or null when the key file cannot be used. */
function doc_link_key(): ?string {
    try {
        return hash_hmac('sha256', 'accessible-document-link-v1', meeting_key(), true);
    } catch (Throwable $e) {
        error_log('doc_link_key: ' . get_class($e) . ' ' . $e->getMessage());
        return null;
    }
}

function doc_link_sign(string $id, int $expires, string $key): string {
    return rtrim(strtr(base64_encode(substr(hash_hmac('sha256', 'doc|' . $id . '|' . $expires, $key, true), 0, 16)), '+/', '-_'), '=');
}

/** A copy made within this many days is given out again for the same document and content,
 *  rather than a new public copy for every download (4.30.0). */
const DOC_LINK_REUSE_DAYS = 30;

/** The kept copies' details: id => meta (entity, entity_id, client_ids, sha, expires). */
function doc_link_all(): array {
    $out = [];
    foreach (glob(doc_link_dir() . '/*.json') ?: [] as $f) {
        if (!preg_match('/\/([0-9a-f]{24})\.json$/', $f, $m)) continue;
        $meta = json_decode((string)@file_get_contents($f), true);
        if (is_array($meta)) $out[$m[1]] = $meta;
    }
    return $out;
}

/**
 * Keep $html as an accessible copy and return ['url' => the link, 'expires' => unix time],
 * or null when it cannot be kept (no key file, or the folder cannot be written): the PDF then
 * names the signed-in copy as before. $entity / $entityId / $detail name the record in the
 * audit trail when the copy is opened. $clientId is the client the copy is about (an invoice
 * or statement), so a permanent purge of that client removes it (doc_link_forget_client()).
 * The same document with the same content, made in the last DOC_LINK_REUSE_DAYS days, is given
 * the link it already has.
 */
function doc_link_create(string $html, string $entity, ?int $entityId, string $detail, ?int $clientId = null): ?array {
    $key = doc_link_key();
    if ($key === null || (string)cfg('upload_dir') === '') return null;
    $dir = doc_link_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return null;
    doc_link_prune();
    $sha = hash('sha256', $html);
    $base = rtrim((string)cfg('base_url'), '/') . '/accessible_copy.php?k=';
    foreach (doc_link_all() as $oldId => $old) {
        $oldExp = (int)($old['expires'] ?? 0);
        if (($old['sha'] ?? '') === $sha && ($old['entity'] ?? '') === $entity && ($old['entity_id'] ?? null) === $entityId
            && $oldExp - time() > (DOC_LINK_DAYS - DOC_LINK_REUSE_DAYS) * 86400 && is_file($dir . '/' . $oldId . '.html')) {
            return ['url' => $base . $oldId . '.' . $oldExp . '.' . doc_link_sign((string)$oldId, $oldExp, $key), 'expires' => $oldExp];
        }
    }
    $id = bin2hex(random_bytes(12));
    $expires = time() + DOC_LINK_DAYS * 86400;
    $meta = json_encode(['entity' => $entity, 'entity_id' => $entityId, 'client_ids' => $clientId ? [$clientId] : [],
        'sha' => $sha, 'detail' => mb_substr($detail, 0, 200), 'expires' => $expires]);
    if (@file_put_contents($dir . '/' . $id . '.html', $html) === false || @file_put_contents($dir . '/' . $id . '.json', (string)$meta) === false) {
        @unlink($dir . '/' . $id . '.html');
        return null;
    }
    $token = $id . '.' . $expires . '.' . doc_link_sign($id, $expires, $key);
    return ['url' => $base . $token, 'expires' => $expires];
}

/**
 * The copy a link opens: ['html' => ..., 'meta' => [...]] or ['error' => 'expired'|'unknown'].
 * Constant-time signature check; an expired copy is removed when it is asked for.
 */
function doc_link_open(string $token): array {
    if (!preg_match('/^([0-9a-f]{24})\.(\d{9,11})\.([A-Za-z0-9_-]{22})$/', $token, $m)) return ['error' => 'unknown'];
    $key = doc_link_key();
    if ($key === null || !hash_equals(doc_link_sign($m[1], (int)$m[2], $key), $m[3])) return ['error' => 'unknown'];
    $file = doc_link_dir() . '/' . $m[1];
    if ((int)$m[2] < time()) {
        @unlink($file . '.html'); @unlink($file . '.json');
        return ['error' => 'expired'];
    }
    $html = is_file($file . '.html') ? @file_get_contents($file . '.html') : false;
    if ($html === false || $html === '') return ['error' => 'unknown'];
    $meta = json_decode((string)@file_get_contents($file . '.json'), true);
    return ['html' => $html, 'meta' => is_array($meta) ? $meta : []];
}

/** Removes copies past their time (the file's age; each copy lives DOC_LINK_DAYS days). */
function doc_link_prune(): void {
    $limit = time() - (DOC_LINK_DAYS + 1) * 86400;
    foreach (glob(doc_link_dir() . '/*') ?: [] as $f) {
        if (preg_match('/\/[0-9a-f]{24}\.(html|json)$/', $f) && ($t = @filemtime($f)) !== false && $t < $limit) @unlink($f);
    }
}

/** A client's records were merged into another (inc/client_merge.php): their copies now also
 *  belong to the kept client, so purging either removes them. The old id stays listed, so a
 *  merge that is rolled back still leaves the copies with their own client. */
function doc_link_add_client(int $fromClient, int $toClient): void {
    foreach (doc_link_all() as $id => $meta) {
        $ids = array_map('intval', (array)($meta['client_ids'] ?? []));
        if (!in_array($fromClient, $ids, true) || in_array($toClient, $ids, true)) continue;
        $meta['client_ids'] = array_values(array_merge($ids, [$toClient]));
        @file_put_contents(doc_link_dir() . '/' . $id . '.json', (string)json_encode($meta));
    }
}

/** A client was purged permanently (client.php): every accessible copy about them is removed,
 *  so their links stop working. Returns how many copies were removed. */
function doc_link_forget_client(int $clientId): int {
    $n = 0;
    foreach (doc_link_all() as $id => $meta) {
        if (!in_array($clientId, array_map('intval', (array)($meta['client_ids'] ?? [])), true)) continue;
        @unlink(doc_link_dir() . '/' . $id . '.html');
        @unlink(doc_link_dir() . '/' . $id . '.json');
        $n++;
    }
    return $n;
}
