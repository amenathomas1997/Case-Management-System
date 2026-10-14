<?php
declare(strict_types=1);

/** Self-contained HTML counterparts can be saved, emailed, and read offline. */
final class AccessibleDocument {
    private string $body = '';
    public function __construct(private string $title) {}
    /** A section heading: level 2 by default; 3 or 4 for an entry inside a section
     *  (a note, an assessment, a compared option), so the outline matches the PDF. */
    public function heading(string $text, int $level = 2): void {
        $level = max(2, min(4, $level));
        $this->body .= '<h' . $level . '>' . h($text) . '</h' . $level . '>';
    }
    public function subheading(string $text): void { $this->heading($text, 3); }
    public function paragraph(string $text): void { $this->body .= '<p>' . nl2br(h($text)) . '</p>'; }
    public function properties(array $pairs): void {
        $this->body .= '<dl>';
        foreach ($pairs as $label => $value) $this->body .= '<dt>' . h((string)$label) . '</dt><dd>' . nl2br(h((string)$value)) . '</dd>';
        $this->body .= '</dl>';
    }
    public function table(string $caption, array $headers, array $rows): void {
        $this->body .= '<div class="scroll" role="region" aria-label="' . h($caption) . '" tabindex="0"><table><caption>' . h($caption) . '</caption><thead><tr>';
        foreach ($headers as $header) $this->body .= '<th scope="col">' . h($header) . '</th>';
        $this->body .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $this->body .= '<tr>';
            foreach ($row as $cell) $this->body .= '<td>' . nl2br(h((string)$cell)) . '</td>';
            $this->body .= '</tr>';
        }
        $this->body .= '</tbody></table></div>';
    }
    public function html(): string {
        $base = function_exists('cfg') ? rtrim((string)cfg('base_url'), '/') . '/' : '';
        $navigation = '<nav aria-label="CMS navigation"><a href="' . h($base . 'index.php') . '">Dashboard / return to CMS</a> · <a href="' . h($base . 'index.php#main-nav') . '">Main navigation</a></nav>';
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . h($this->title) . '</title><style>dd,li{max-width:72ch}body{font:1rem/1.6 system-ui,sans-serif;color:#1a1a2e;background:#fff;max-width:70rem;margin:auto;padding:1rem}h1,h2,h3,h4,p,dd,td,th{overflow-wrap:anywhere}p{max-width:75ch}dt{font-weight:bold}dd{margin:0 0 1rem}table{border-collapse:collapse;width:100%}th,td{padding:.6rem;text-align:left;border:1px solid #555;vertical-align:top}caption{text-align:left;font-weight:bold;padding:.6rem}th{background:#eee}.scroll{overflow:auto}:focus-visible{outline:3px solid #1e40af;outline-offset:3px}@media print{.scroll{overflow:visible}}</style></head><body>' . $navigation . '<main aria-label="Main content"><h1>'
            . h($this->title) . '</h1>' . $this->body . '</main></body></html>';
    }
    public function download(string $name): never {
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) . '"');
        echo $this->html();
        exit;
    }
}
