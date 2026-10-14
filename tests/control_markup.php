<?php
// Check synthetic full_schema snapshots, not live client pages.
declare(strict_types=1);
$files = glob(rtrim($argv[1] ?? '', '/') . '/*.html');
if (!$files) throw new RuntimeException('Provide a nonempty synthetic snapshot directory.');
$count = 0;
foreach ($files as $file) {
    $dom = new DOMDocument(); @$dom->loadHTML((string)file_get_contents($file)); $xp = new DOMXPath($dom);
    foreach ($xp->query('//select | //input[@type="date" or @type="datetime-local" or @type="time"]') as $field) {
        $count++; $id = $field->getAttribute('id'); $name = $field->getAttribute('aria-label');
        foreach (preg_split('/\s+/', trim($field->getAttribute('aria-labelledby'))) as $ref) {
            if ($ref !== '') $name .= $dom->getElementById($ref)?->textContent ?? '';
        }
        foreach ($xp->query('//label') as $label) {
            if (($id !== '' && $label->getAttribute('for') === $id) || $label->contains($field)) $name .= $label->textContent;
        }
        if (trim($name) === '') throw new RuntimeException(basename($file) . ': missing label for ' . $id);
        if ($field->tagName === 'select') {
            $options = $field->getElementsByTagName('option');
            if (!$options->length) throw new RuntimeException(basename($file) . ': empty list ' . $id);
            foreach ($options as $option) if (trim($option->textContent) === '') throw new RuntimeException(basename($file) . ': unnamed option in ' . $id);
        }
    }
}
echo "PASS: $count controls in " . count($files) . " synthetic pages have labels; selects have named choices.\n";
