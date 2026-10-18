<?php
declare(strict_types=1);
// Wording an administrator can change (4.3.0), in English and Spanish, on Public wording
// (wording.php), for the parts of public pages that describe one business: what the
// organisation does on the guardian consent form, what the guardian agrees to, and an extra
// section of the privacy notice. Empty uses the built-in text (translated as before).

/** key => [label, built-in English text, one item per line?] */
const PUBLIC_WORDING = [
    'consent_about' => ['What your organisation does (consent form, after its name)',
        'teaches people to use devices and software that help with vision loss. We also teach everyday living skills, offer counseling, and help students get ready for jobs.', false],
    'consent_points' => ['What the guardian agrees to (consent form, one point per line, after the services)',
        "We may talk with the student's school.\nIf needed, we may also talk with the state agency that helps people with disabilities find work.\nWe share records only to plan services and to send reports the law requires.\nWe may contact you about scheduling and progress.", true],
    'sign_services' => ['What a client agrees to when signing consent to services (one point per line)',
        "We may provide the training and services planned with you and your funding agency.\nWe keep records of your services and share them only to plan services, to bill the agency paying for them, and to send reports the law requires.\nWe may contact you about scheduling and progress.\nYou can stop services at any time by telling us.", true],
    'sign_loan' => ['What a borrower agrees to when signing an equipment loan agreement (one point per line)',
        "The device stays the property of the lender and is lent for the purpose agreed.\nI will take reasonable care of it and tell you at once if it is lost, stolen or damaged.\nI will return it, with what came with it, by the due date or when you ask.\nI will not sell, lend or give it to anyone else.", true],
    'privacy_extra_heading' => ['Heading of an extra privacy notice section (empty for none)', '', false],
    'privacy_extra_body' => ['Text of that section (a blank line starts a new paragraph)', '', true],
];

/** Plain text for a key, as kept with a signature: the language's own, else the English, else the built-in text. */
function wording_text(string $key, string $lang): string {
    if ($lang === 'es' && ($es = wording_stored($key, 'es')) !== '') return $es;
    return wording_stored($key, 'en') ?: (string)(PUBLIC_WORDING[$key][1] ?? '');
}

/** The stored text for a key and language ('' when none). */
function wording_stored(string $key, string $lang): string {
    return trim(str_replace("\r", '', (string)(setting('wording_' . $key . ($lang === 'es' ? '_es' : '')) ?? '')));
}

/**
 * The text for this page's language, escaped for HTML: the administrator's own (Spanish, or
 * their English marked lang="en" on a Spanish page), else the built-in text, translated.
 */
function wording_html(string $key): string {
    $lang = function_exists('page_lang') ? page_lang() : 'en';
    if ($lang === 'es' && ($es = wording_stored($key, 'es')) !== '') return h($es);
    if (($en = wording_stored($key, 'en')) !== '') return $lang === 'es' ? '<span lang="en">' . h($en) . '</span>' : h($en);
    $default = PUBLIC_WORDING[$key][1] ?? '';
    return $default === '' ? '' : (function_exists('t') ? t($default) : h($default));
}

/** The lines of a one-per-line text, each escaped (built-in lines are translated one by one). */
function wording_lines_html(string $key): array {
    $lang = function_exists('page_lang') ? page_lang() : 'en';
    $own = $lang === 'es' && wording_stored($key, 'es') !== '' ? wording_stored($key, 'es') : wording_stored($key, 'en');
    if ($own !== '') {
        $mark = $lang === 'es' && wording_stored($key, 'es') === '';
        return array_map(fn($l) => $mark ? '<span lang="en">' . h($l) . '</span>' : h($l), array_values(array_filter(array_map('trim', explode("\n", $own)), fn($l) => $l !== '')));
    }
    return array_map(fn($l) => function_exists('t') ? t($l) : h($l), array_values(array_filter(explode("\n", PUBLIC_WORDING[$key][1] ?? ''), fn($l) => $l !== '')));
}

/** Paragraphs of a text (split on blank lines), each escaped. */
function wording_paragraphs_html(string $key): array {
    $lang = function_exists('page_lang') ? page_lang() : 'en';
    $es = wording_stored($key, 'es');
    $text = $lang === 'es' && $es !== '' ? $es : wording_stored($key, 'en');
    $mark = $lang === 'es' && $es === '';
    return array_map(fn($p) => $mark ? '<span lang="en">' . nl2br(h($p)) . '</span>' : nl2br(h($p)), array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $text)), fn($p) => $p !== '')));
}
