<?php
declare(strict_types=1);
// Accessible content and remediation (4.37.0: moved from remediate.php, shared with the apps'
// ai_remediate): the modes, each mode's instruction with the client's preferred format, and the
// content's checks, so the page and the apps send the same prompt and refuse with the same words.

const REMEDIATE_MODES = [
    'plain' => 'Plain language (6th–8th grade)',
    'simplify' => 'Simplify (shorter sentences)',
    'summary' => 'Summarize',
    'largeprint' => 'Format for large print / low vision',
    'alt' => 'Alt text for an image (describe it below)',
];

// Most pasted text accepted per request (it is sent to the AI provider).
const REMEDIATE_MAX_CHARS = 20000;

/** The system prompt for a mode, with " The reader prefers this format: X." when a client's preferred
 *  format is targeted (not for alt text). */
function remediate_prompt(string $mode, ?string $format): string {
    $prompts = [
        'plain' => 'Rewrite the following content in plain language at roughly a 6th–8th grade reading level. Preserve every fact and all meaning; do not add or remove information.',
        'simplify' => 'Rewrite the following content with shorter sentences and simpler words, keeping all facts and meaning.',
        'summary' => 'Summarize the following content clearly and faithfully, without adding information.',
        'largeprint' => 'Reformat the following content for large-print / low-vision reading: short lines, clear headings, and bulleted lists where helpful; no tables. Keep all of the content.',
        'alt' => 'Write concise, descriptive alt text (one sentence, under 125 characters) for an image described below. Output only the alt text.',
    ];
    $sys = ai_system_base() . ' ' . ($prompts[$mode] ?? $prompts['plain']);
    if ($format !== null && $format !== '' && $mode !== 'alt') $sys .= ' The reader prefers this format: ' . $format . '.';
    return $sys;
}

/** The content's problem as [field => sentence] (content), or []: required, 20,000 characters at most. */
function remediate_errors(string $content): array {
    if (trim($content) === '') return ['content' => 'Paste some content first.'];
    if (($cLen = mb_strlen($content)) > REMEDIATE_MAX_CHARS) return ['content' => 'Shorten the content to 20,000 characters or fewer (it has ' . number_format($cLen) . '), or split it into smaller parts.'];
    return [];
}

/** The sentence a page or app shows when a client's preferred format is targeted. */
function remediate_target_text(?string $format): ?string {
    return $format !== null && $format !== '' ? 'Targeting the client\'s preferred format: ' . $format . '.' : null;
}
