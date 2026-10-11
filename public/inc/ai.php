<?php
declare(strict_types=1);
// Optional AI drafting assistant. Provider-agnostic: supports Anthropic, OpenAI,
// Google Gemini, xAI, Mistral, DeepSeek, Groq, and any OpenAI-compatible endpoint.
// Every call is initiated by a staff member, produces a DRAFT the staff edits
// before saving or sending, and is logged by the caller. The API key lives in
// the settings table (never in code, and left out of database backups), entered by
// an admin on ai.php.

/** Known providers: type drives the request format; endpoint/default_model are hints. */
function ai_providers(): array {
    return [
        'anthropic' => ['label' => 'Anthropic (Claude)', 'type' => 'anthropic',
            'endpoint' => 'https://api.anthropic.com/v1/messages', 'default_model' => 'claude-sonnet-5'],
        'openai' => ['label' => 'OpenAI (GPT)', 'type' => 'openai',
            'endpoint' => 'https://api.openai.com/v1/chat/completions', 'default_model' => 'gpt-4o'],
        'google' => ['label' => 'Google (Gemini)', 'type' => 'google',
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models', 'default_model' => 'gemini-1.5-pro'],
        'xai' => ['label' => 'xAI (Grok)', 'type' => 'openai',
            'endpoint' => 'https://api.x.ai/v1/chat/completions', 'default_model' => 'grok-2-latest'],
        'mistral' => ['label' => 'Mistral', 'type' => 'openai',
            'endpoint' => 'https://api.mistral.ai/v1/chat/completions', 'default_model' => 'mistral-large-latest'],
        'deepseek' => ['label' => 'DeepSeek', 'type' => 'openai',
            'endpoint' => 'https://api.deepseek.com/v1/chat/completions', 'default_model' => 'deepseek-chat'],
        'groq' => ['label' => 'Groq', 'type' => 'openai',
            'endpoint' => 'https://api.groq.com/openai/v1/chat/completions', 'default_model' => 'llama-3.3-70b-versatile'],
        'custom' => ['label' => 'Custom (OpenAI-compatible)', 'type' => 'openai',
            'endpoint' => '', 'default_model' => ''],
    ];
}

/** Config from settings; returns null when not fully enabled/configured. */
function ai_cfg(): ?array {
    static $cfg = null; static $done = false;
    if ($done) return $cfg;
    $done = true;
    if ((setting('ai_enabled') ?? '') !== '1') return $cfg = null;
    $key = trim((string)(setting('ai_api_key') ?? ''));
    if ($key === '') return $cfg = null;
    $providers = ai_providers();
    $pid = (string)(setting('ai_provider') ?? 'anthropic');
    if (!isset($providers[$pid])) $pid = 'anthropic';
    $p = $providers[$pid];
    $endpoint = trim((string)(setting('ai_base_url') ?? '')) ?: $p['endpoint'];
    if ($endpoint === '') return $cfg = null;   // custom provider needs a base URL
    return $cfg = [
        'provider' => $pid,
        'type' => $p['type'],
        'endpoint' => $endpoint,
        'key' => $key,
        'model' => trim((string)(setting('ai_model') ?? '')) ?: $p['default_model'],
    ];
}

function ai_enabled(): bool { return ai_cfg() !== null; }

/**
 * The addresses an AI endpoint's host resolves to, or null when it does not
 * resolve or any address is loopback, private, link-local or reserved. The API
 * key is POSTed from inside the hosting network, so the configured address must
 * never reach an internal service (ai.php refuses such an address when saved).
 */
function ai_endpoint_ips(string $url): ?array {
    if (!preg_match('#^https://#i', $url)) return null;
    $host = trim((string)(parse_url($url, PHP_URL_HOST) ?? ''), '[]');
    if ($host === '') return null;
    if (filter_var($host, FILTER_VALIDATE_IP)) $ips = [$host];
    else {
        $ips = gethostbynamel($host) ?: [];
        if (!$ips) foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $rec) if (!empty($rec['ipv6'])) $ips[] = (string)$rec['ipv6'];
    }
    if (!$ips) return null;
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | (defined('FILTER_FLAG_GLOBAL_RANGE') ? FILTER_FLAG_GLOBAL_RANGE : 0);
    foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) return null;
    return $ips;
}

/** cURL options that keep an AI request on https and on the address just checked,
 * so neither a redirect nor a DNS change can move it to an internal host. Null
 * when the endpoint is refused (ai_endpoint_ips). */
function ai_curl_guard(string $url): ?array {
    $ips = ai_endpoint_ips($url);
    if ($ips === null) return null;
    $opts = [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false];
    $host = trim((string)(parse_url($url, PHP_URL_HOST) ?? ''), '[]');
    if (!filter_var($host, FILTER_VALIDATE_IP)) {
        $ip = $ips[0];
        $opts[CURLOPT_RESOLVE] = [$host . ':' . (int)(parse_url($url, PHP_URL_PORT) ?: 443) . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)];
    }
    return $opts;
}

/** Low-level HTTP POST returning [httpStatus, decodedBody|null, curlError]. */
function ai_http(string $url, array $headers, string $body): array {
    // A refused address is reported like an unreachable one (status 0).
    $guard = ai_curl_guard($url);
    if ($guard === null) return [0, null, 'endpoint refused'];
    $ch = curl_init($url);
    curl_setopt_array($ch, $guard + [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return [0, null, $err];
    return [$status, json_decode((string)$resp, true), ''];
}

/**
 * Send a system + user prompt to the configured provider and return the text.
 * @return array{ok:bool, text:string, error:string}
 */
function ai_generate(string $system, string $prompt, int $maxTokens = 1500): array {
    $cfg = ai_cfg();
    if (!$cfg) return ['ok' => false, 'text' => '', 'error' => 'The AI assistant is not configured. An administrator can set it up under AI assistant settings.'];
    $maxTokens = max(256, min(4096, $maxTokens));
    $enc = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    if ($cfg['type'] === 'anthropic') {
        [$status, $data, $err] = ai_http($cfg['endpoint'],
            ['content-type: application/json', 'anthropic-version: 2023-06-01', 'x-api-key: ' . $cfg['key']],
            (string)json_encode(['model' => $cfg['model'], 'max_tokens' => $maxTokens, 'system' => $system,
                'messages' => [['role' => 'user', 'content' => $prompt]]], $enc));
        if ($status !== 200) return ai_err($status, $data, $err);
        $text = '';
        foreach ($data['content'] ?? [] as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
        return ai_done($text, ($data['stop_reason'] ?? '') === 'max_tokens');
    }

    if ($cfg['type'] === 'google') {
        // Endpoint is the models base; append /{model}:generateContent?key=
        $url = rtrim($cfg['endpoint'], '/') . '/' . rawurlencode($cfg['model']) . ':generateContent?key=' . rawurlencode($cfg['key']);
        [$status, $data, $err] = ai_http($url, ['content-type: application/json'],
            (string)json_encode([
                'system_instruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => ['maxOutputTokens' => $maxTokens],
            ], $enc));
        if ($status !== 200) return ai_err($status, $data, $err);
        $text = '';
        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $p) $text .= $p['text'] ?? '';
        return ai_done($text, ($data['candidates'][0]['finishReason'] ?? '') === 'MAX_TOKENS');
    }

    // OpenAI-compatible (OpenAI, xAI, Mistral, DeepSeek, Groq, custom)
    [$status, $data, $err] = ai_http($cfg['endpoint'],
        ['content-type: application/json', 'authorization: Bearer ' . $cfg['key']],
        (string)json_encode(['model' => $cfg['model'], 'max_tokens' => $maxTokens,
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]]], $enc));
    if ($status !== 200) return ai_err($status, $data, $err);
    $text = (string)($data['choices'][0]['message']['content'] ?? '');
    return ai_done($text, ($data['choices'][0]['finish_reason'] ?? '') === 'length');
}

/** A failed provider call, as a fixed sentence for whoever clicked: provider and
 * cURL error text can carry endpoint details or a masked fragment of the API key,
 * so it is never shown or logged (only the HTTP status is logged). The two opening
 * phrases are what api_ai_error() and voice_ai_error() recognise. */
function ai_err(int $status, $data, string $curlErr): array {
    if ($status === 0) {
        error_log('AI provider unreachable (connection failed)');
        return ['ok' => false, 'text' => '', 'error' => 'Could not reach the AI service. Please try again later.'];
    }
    error_log('AI provider returned HTTP ' . $status);
    $msg = match (true) {
        $status === 401 || $status === 403 => 'The AI service returned an error: it did not accept the API key. An administrator should check AI assistant settings.',
        $status === 429 => 'The AI service returned an error: too many requests, or the account\'s usage limit is reached. Please try again later.',
        default => 'The AI service returned an error (HTTP ' . $status . '). Please try again later.',
    };
    return ['ok' => false, 'text' => '', 'error' => $msg];
}
/** 'truncated' is true when the provider stopped at the output-token limit, so
 * the text ends early (callers must say so rather than present it as complete). */
function ai_done(string $text, bool $truncated = false): array {
    $text = trim($text);
    return $text === ''
        ? ['ok' => false, 'text' => '', 'error' => 'The AI service returned an empty response.', 'truncated' => false]
        : ['ok' => true, 'text' => $text, 'error' => '', 'truncated' => $truncated];
}

/** Output-token budget for rewriting or translating $content: its input tokens
 * (about 4 characters each) times $factor, within the 4,096-token ceiling that
 * ai_generate() allows for every provider. */
function ai_output_tokens(string $content, float $factor = 1.3): int {
    return (int)max(1000, min(4096, ceil(mb_strlen($content) / 4 * $factor) + 200));
}

// Translation targets' BCP-47 codes (translate.php and the API), so the result is
// marked with its language (screen readers switch voice) and direction. Keys are
// lower-case; typed "Other" names are looked up through translate_lang_code(),
// which also finds regional variants ("Portuguese (Brazil)", "Moroccan Arabic").
const TRANSLATE_LANG_CODES = [
    'spanish' => 'es', 'español' => 'es', 'arabic' => 'ar', 'العربية' => 'ar', 'chinese (simplified)' => 'zh-Hans',
    'chinese (traditional)' => 'zh-Hant', 'traditional chinese' => 'zh-Hant', 'chinese' => 'zh', 'mandarin' => 'zh', 'cantonese' => 'yue',
    'vietnamese' => 'vi', 'russian' => 'ru', 'french' => 'fr', 'haitian creole' => 'ht', 'somali' => 'so', 'nepali' => 'ne',
    'korean' => 'ko', 'tagalog' => 'tl', 'hebrew' => 'he', 'persian' => 'fa', 'farsi' => 'fa', 'dari' => 'prs', 'urdu' => 'ur',
    'pashto' => 'ps', 'yiddish' => 'yi', 'portuguese' => 'pt', 'german' => 'de', 'italian' => 'it', 'japanese' => 'ja',
    'hindi' => 'hi', 'हिन्दी' => 'hi', 'bengali' => 'bn', 'punjabi' => 'pa', 'gujarati' => 'gu', 'tamil' => 'ta', 'polish' => 'pl',
    'ukrainian' => 'uk', 'amharic' => 'am', 'tigrinya' => 'ti', 'swahili' => 'sw', 'burmese' => 'my', 'thai' => 'th',
    'khmer' => 'km', 'lao' => 'lo', 'hmong' => 'hmn', 'greek' => 'el', 'turkish' => 'tr', 'romanian' => 'ro', 'english' => 'en',
    // Other spellings and regional variants.
    'simplified chinese' => 'zh-Hans', 'chinese simplified' => 'zh-Hans', 'chinese traditional' => 'zh-Hant',
    'portuguese (brazil)' => 'pt-BR', 'portuguese (brazilian)' => 'pt-BR', 'brazilian portuguese' => 'pt-BR',
    'portuguese (portugal)' => 'pt-PT', 'european portuguese' => 'pt-PT', 'spanish (mexico)' => 'es-MX', 'mexican spanish' => 'es-MX',
    'spanish (latin america)' => 'es-419', 'latin american spanish' => 'es-419', 'french (canada)' => 'fr-CA', 'canadian french' => 'fr-CA',
    'haitian' => 'ht', 'kreyol' => 'ht', 'kreyòl' => 'ht', 'filipino' => 'fil', 'cambodian' => 'km', 'laotian' => 'lo',
    'sorani' => 'ckb', 'sorani kurdish' => 'ckb', 'kurmanji' => 'kmr', 'kurmanji kurdish' => 'kmr', 'kurdish' => 'ku',
    'karen' => 'kar', "s'gaw karen" => 'ksw', 'sgaw karen' => 'ksw', 'hakha chin' => 'cnh', 'rohingya' => 'rhg',
    // Further languages common in US refugee, immigrant and VR caseloads.
    'dutch' => 'nl', 'indonesian' => 'id', 'malay' => 'ms', 'kinyarwanda' => 'rw', 'kirundi' => 'rn', 'oromo' => 'om',
    'afaan oromoo' => 'om', 'swedish' => 'sv', 'czech' => 'cs', 'slovak' => 'sk', 'marathi' => 'mr', 'telugu' => 'te',
    'kannada' => 'kn', 'malayalam' => 'ml', 'sinhala' => 'si', 'armenian' => 'hy', 'albanian' => 'sq', 'bosnian' => 'bs',
    'serbian' => 'sr', 'croatian' => 'hr', 'slovenian' => 'sl', 'macedonian' => 'mk', 'bulgarian' => 'bg', 'hungarian' => 'hu',
    'georgian' => 'ka', 'azerbaijani' => 'az', 'uzbek' => 'uz', 'kazakh' => 'kk', 'tajik' => 'tg', 'turkmen' => 'tk',
    'uyghur' => 'ug', 'mongolian' => 'mn', 'tibetan' => 'bo', 'dzongkha' => 'dz', 'marshallese' => 'mh', 'chuukese' => 'chk',
    'samoan' => 'sm', 'tongan' => 'to', 'hawaiian' => 'haw', 'ilocano' => 'ilo', 'cebuano' => 'ceb', 'hausa' => 'ha',
    'yoruba' => 'yo', 'igbo' => 'ig', 'twi' => 'tw', 'ewe' => 'ee', 'wolof' => 'wo', 'fula' => 'ff', 'fulani' => 'ff',
    'lingala' => 'ln', 'luganda' => 'lg', 'shona' => 'sn', 'zulu' => 'zu', 'xhosa' => 'xh', 'afrikaans' => 'af',
    'malagasy' => 'mg', 'tigre' => 'tig', 'cape verdean creole' => 'kea', 'jamaican patois' => 'jam', 'navajo' => 'nv',
    'finnish' => 'fi', 'norwegian' => 'no', 'danish' => 'da', 'icelandic' => 'is', 'estonian' => 'et', 'latvian' => 'lv',
    'lithuanian' => 'lt', 'belarusian' => 'be', 'irish' => 'ga', 'welsh' => 'cy', 'catalan' => 'ca', 'basque' => 'eu',
    'galician' => 'gl',
    // Names written in the language itself.
    'português' => 'pt', 'français' => 'fr', 'deutsch' => 'de', 'italiano' => 'it', 'polski' => 'pl', 'română' => 'ro',
    'türkçe' => 'tr', 'tiếng việt' => 'vi', 'soomaali' => 'so', 'kiswahili' => 'sw', 'nederlands' => 'nl',
    'bahasa indonesia' => 'id', 'русский' => 'ru', 'українська' => 'uk', 'ελληνικά' => 'el', 'עברית' => 'he',
    'فارسی' => 'fa', 'دری' => 'prs', 'اردو' => 'ur', 'پښتو' => 'ps', 'کوردی' => 'ckb', 'नेपाली' => 'ne', 'বাংলা' => 'bn',
    'ਪੰਜਾਬੀ' => 'pa', 'ગુજરાતી' => 'gu', 'தமிழ்' => 'ta', 'मराठी' => 'mr', 'తెలుగు' => 'te', 'አማርኛ' => 'am',
    'ትግርኛ' => 'ti', 'မြန်မာ' => 'my', 'ไทย' => 'th', 'ខ្មែរ' => 'km', 'ລາວ' => 'lo', '中文' => 'zh',
    '简体中文' => 'zh-Hans', '繁體中文' => 'zh-Hant', '日本語' => 'ja', '한국어' => 'ko', 'հայերեն' => 'hy', 'ქართული' => 'ka',
];
// Right-to-left scripts among the codes above.
const TRANSLATE_RTL = ['ar', 'he', 'fa', 'prs', 'ur', 'ps', 'yi', 'ckb', 'ug'];

/** The BCP-47 code for a target language name as listed or typed, or '' when it
 * is not known (the result is then marked lang="" — language unknown — rather
 * than inheriting English). Case, extra spaces and a trailing "language" are
 * ignored; "Portuguese (Brazil)" or "Moroccan Arabic" without an entry of their
 * own fall back to the language they name. */
function translate_lang_code(string $name): string {
    $k = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
    $k = preg_replace('/\s+language$/u', '', $k) ?? $k;
    if (isset(TRANSLATE_LANG_CODES[$k])) return TRANSLATE_LANG_CODES[$k];
    $base = trim(preg_replace('/\s*\([^)]*\)\s*/u', ' ', $k) ?? $k);
    if ($base !== '' && isset(TRANSLATE_LANG_CODES[$base])) return TRANSLATE_LANG_CODES[$base];
    $words = preg_split('/[\s-]+/u', $base, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($words) > 1) {
        $last = (string)end($words);
        if (isset(TRANSLATE_LANG_CODES[$last])) return TRANSLATE_LANG_CODES[$last];
    }
    return '';
}

/** Appended to a prompt whose answer is shown on a page: plain text that
 * plain_text_html() can turn into real paragraphs and lists, with no Markdown
 * symbols for a screen reader to read out. */
function ai_plain_text_rule(): string {
    return ' Write plain text with no Markdown or other formatting symbols (no asterisks, pound signs or underscores for emphasis). '
        . 'Put each item of a list on its own line, starting with "- " or with its number and a period, and separate paragraphs with a blank line.';
}

/**
 * Plain text (an AI answer or a knowledge-base article) as accessible HTML, so a
 * screen reader gets real structure instead of symbols: lines starting "- ",
 * "* " or "• " become a bulleted list and "1. " or "1) " a numbered list (a line
 * right after an item, without a blank line between, continues that item); a line
 * starting "# " to "###### " becomes a heading, never above level $topLevel; any
 * other run of lines is a paragraph that keeps its line breaks. With $stripEmphasis
 * (for AI output), Markdown emphasis (**bold**, __bold__, *italic*, `code`) loses
 * its markers. Every piece of text is escaped with h(); nothing in $text ever
 * becomes markup itself.
 */
function plain_text_html(string $text, int $topLevel = 3, bool $stripEmphasis = true): string {
    $topLevel = max(2, min(6, $topLevel));
    $clean = function (string $s) use ($stripEmphasis): string {
        if (!$stripEmphasis) return trim($s);
        $s = preg_replace(['/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/u', '/(?<![\w*])\*(?=\S)([^*\n]+?)(?<=\S)\*(?![\w*])/u', '/`([^`\n]+)`/u'],
            ['$2', '$1', '$1'], $s) ?? $s;
        return trim($s);
    };
    $html = ''; $para = []; $listTag = ''; $items = []; $start = 1;
    $flush = function () use (&$html, &$para, &$listTag, &$items, &$start): void {
        if ($para) { $html .= '<p>' . implode('<br>', array_map('h', $para)) . '</p>'; $para = []; }
        if ($listTag !== '') {
            $html .= '<' . $listTag . ($listTag === 'ol' && $start !== 1 ? ' start="' . $start . '"' : '') . '>';
            foreach ($items as $lines) $html .= '<li>' . implode('<br>', array_map('h', $lines)) . '</li>';
            $html .= '</' . $listTag . '>';
            $listTag = ''; $items = []; $start = 1;
        }
    };
    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
        if (trim($line) === '') { $flush(); continue; }
        if (preg_match('/^\s*([-*_])(?:\s*\1){2,}\s*$/u', $line)) { $flush(); continue; }   // a --- rule
        if (preg_match('/^\s{0,3}(#{1,6})\s+(.+?)[\s#]*$/u', $line, $m) && ($t = $clean($m[2])) !== '') {
            $flush();
            $lv = max($topLevel, min(6, strlen($m[1])));
            $html .= '<h' . $lv . '>' . h($t) . '</h' . $lv . '>';
            continue;
        }
        $tag = ''; $item = '';
        if (preg_match('/^\s*[-*•]\s+(.+)$/u', $line, $m)) { $tag = 'ul'; $item = $m[1]; }
        elseif (preg_match('/^\s*(\d{1,3})[.)]\s+(.+)$/u', $line, $m)) { $tag = 'ol'; $item = $m[2]; }
        if ($tag !== '') {
            if ($para || $listTag !== $tag) {
                $flush();
                $listTag = $tag;
                $start = $tag === 'ol' ? max(1, (int)$m[1]) : 1;
            }
            $items[] = [$clean($item)];
            continue;
        }
        if ($listTag !== '') { $items[count($items) - 1][] = $clean($line); continue; }
        $para[] = $clean($line);
    }
    $flush();
    return $html;
}

/* ============================ Governance layer ============================ */

/** AI feature keys → labels. Each is individually switchable on the AI settings page. */
function ai_features(): array {
    return [
        'draft' => 'Drafting assistant (notes, reports, summaries, goals)',
        'recommend' => 'AT recommendation engine',
        'intake' => 'AI-guided assessment intake',
        'progress' => 'Progress review & goal inference',
        'remediate' => 'Accessible content & remediation',
        'ask' => 'Ask your caseload (natural language)',
        'kb' => 'Knowledge-base assistant',
        'translate' => 'On-demand translation',
        'curriculum' => 'Curriculum generator',
        'risk' => 'Outcome risk flags',
        'voice' => 'Voice assistant (natural-language commands)',
        'readaloud' => 'Human-voice read-aloud (text-to-speech)',
    ];
}

/**
 * The OpenAI-compatible text-to-speech address beside the configured chat
 * endpoint (…/chat/completions → …/audio/speech), so speech and the key go only
 * to the host the admin configured (including an ai_base_url gateway). Null when
 * the provider is not OpenAI or the endpoint is not a chat-completions URL, in
 * which case read-aloud is unavailable and the browser voice is used.
 */
function ai_speech_endpoint(): ?string {
    $cfg = ai_cfg();
    if (!$cfg || $cfg['provider'] !== 'openai') return null;
    $url = preg_replace('#/chat/completions/?(?=\?|$)#', '/audio/speech', $cfg['endpoint'], 1, $n);
    return ($n === 1 && is_string($url) && preg_match('#^https://#i', $url)) ? $url : null;
}

/** True when the configured provider can synthesize speech (OpenAI TTS). */
function ai_speak_capable(): bool {
    return ai_speech_endpoint() !== null;
}

/**
 * Text-to-speech via the provider (OpenAI). Returns raw audio bytes for a
 * natural, human-sounding voice. @return array{ok:bool, bytes?:string, mime?:string, error?:string}
 */
function ai_speak(string $text, string $voice = 'nova', ?int $actorId = null): array {
    if (!ai_feature_enabled('readaloud')) return ['ok' => false, 'error' => 'Read-aloud is turned off.'];
    $url = ai_speech_endpoint();
    if ($url === null) return ['ok' => false, 'error' => 'Human-voice read-aloud needs the OpenAI provider with a chat-completions address (its /audio/speech address is used); the browser voice is used instead.'];
    $cfg = ai_cfg();
    $voices = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];
    if (!in_array($voice, $voices, true)) $voice = 'nova';
    $guard = ai_curl_guard($url);
    if ($guard === null) return ['ok' => false, 'error' => 'The voice service did not respond.'];
    $ch = curl_init($url);
    curl_setopt_array($ch, $guard + [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'authorization: Bearer ' . $cfg['key']],
        CURLOPT_POSTFIELDS => (string)json_encode(['model' => 'tts-1', 'voice' => $voice, 'input' => mb_substr($text, 0, 4000)]),
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    ai_log_usage('readaloud', mb_strlen($text), $status === 200 && $resp !== false ? strlen((string)$resp) : 0, false, $actorId);
    if ($status !== 200 || $resp === false) return ['ok' => false, 'error' => 'The voice service did not respond.'];
    return ['ok' => true, 'bytes' => (string)$resp, 'mime' => $ct ?: 'audio/mpeg'];
}

/** A feature is available only when AI is configured AND the feature isn't switched off. */
function ai_feature_enabled(string $key): bool {
    if (!ai_enabled()) return false;
    return (setting('ai_feat_' . $key) ?? '1') !== '0';
}

// Name parts that are also everyday English words: redacted on their own only
// when capitalised ("Mr. Long", "Long called"), so "will practice with the long
// cane" survives. The full name is always redacted, whatever its parts.
const AI_REDACT_COMMON_WORDS = ['may', 'art', 'don', 'joy', 'ray', 'pat', 'rob', 'sue', 'eve', 'ash', 'ivy', 'sky', 'guy',
    'van', 'max', 'bud', 'jay', 'sun', 'red', 'fox', 'win', 'bay', 'kit', 'dot', 'will', 'long', 'grant', 'rose', 'bill', 'mark', 'hope', 'grace', 'young', 'hall', 'read',
    'best', 'white', 'black', 'brown', 'green', 'gray', 'grey', 'king', 'page', 'park', 'rich', 'wood', 'bell', 'cook',
    'hill', 'lane', 'ward', 'west', 'wells', 'faith', 'june', 'april', 'august', 'love', 'major', 'rock', 'summer',
    'little', 'strong', 'short', 'small', 'good', 'sharp', 'early', 'fair', 'field', 'fields', 'gold', 'light', 'power',
    'ready', 'case', 'house', 'moon', 'more', 'just', 'story', 'sweet', 'wise', 'free', 'worth', 'cross', 'head'];

/** Whether identifier redaction is active (default on). */
function ai_redaction_on(): bool { return (setting('ai_redact') ?? '1') !== '0'; }

/**
 * Data minimization: strip direct identifiers before context leaves for the
 * provider, keeping the functional / clinical description that makes the draft
 * useful. When a client row is supplied, that person's own identifiers are
 * replaced by name; generic email/phone/SSN patterns are always caught.
 */
function ai_redact(string $text, ?array $client = null): string {
    if (!ai_redaction_on()) return $text;
    if ($client) {
        $map = ['name' => '[CLIENT]', 'email' => '[EMAIL]', 'phone' => '[PHONE]', 'address' => '[ADDRESS]',
            'guardian_name' => '[GUARDIAN]', 'guardian_email' => '[EMAIL]', 'guardian_phone' => '[PHONE]',
            'counselor_name' => '[COUNSELOR]', 'counselor_email' => '[EMAIL]', 'counselor_phone' => '[PHONE]'];
        foreach ($map as $f => $tag) {
            $v = trim((string)($client[$f] ?? ''));
            if ($v !== '' && mb_strlen($v) >= 3) $text = str_ireplace($v, $tag, $text);
        }
        // Each part of the client's, guardian's and counselor's names on its own
        // ("Mr. Lopez", "her mother rosa"), longest first; the client wins a part
        // shared with the guardian. Parts under 3 characters are skipped so short
        // names do not garble ordinary words. A part is matched in any case, except
        // a part that is also a common word (AI_REDACT_COMMON_WORDS), which is
        // matched only when capitalised (Grace, GRACE), so "will practice with the
        // long cane" survives. A tag already inserted is left alone.
        $parts = [];
        foreach (['name' => '[CLIENT]', 'guardian_name' => '[GUARDIAN]', 'counselor_name' => '[COUNSELOR]'] as $f => $tag) {
            foreach (preg_split('/[\s\-]+/u', (string)($client[$f] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                $part = trim($part, ".,;:()\"'");
                $k = mb_strtolower($part);
                if (mb_strlen($part) >= 3 && !isset($parts[$k])) $parts[$k] = [$part, $tag];
            }
        }
        uasort($parts, fn($a, $b) => mb_strlen($b[0]) <=> mb_strlen($a[0]));
        foreach ($parts as $k => [$part, $tag]) {
            if (in_array((string)$k, AI_REDACT_COMMON_WORDS, true)) {
                // Case-sensitive: the part as stored (unless all lower case), capitalised, and in capitals.
                $first = mb_strtoupper(mb_substr($part, 0, 1));
                $forms = [$first . mb_substr($part, 1), $first . mb_strtolower(mb_substr($part, 1)), mb_strtoupper($part)];
                if ($part !== $k) $forms[] = $part;
                $forms = array_values(array_unique($forms));
                $flags = 'u';
            } else {
                $forms = [$part];
                $flags = 'iu';
            }
            // Skips only a whole "[PART]" (a tag already inserted). Invalid UTF-8 makes
            // a /u pattern fail; fall back to a plain replace rather than skip the part.
            $alt = implode('|', array_map(fn($f) => preg_quote($f, '/'), $forms));
            $text = preg_replace('/(?<!\[)\b(?:' . $alt . ')\b|\b(?:' . $alt . ')\b(?!\])/' . $flags, $tag, $text)
                ?? ($flags === 'iu' ? str_ireplace($forms, $tag, $text) : str_replace($forms, $tag, $text));
        }
        // Date of birth as stored and as it is written on screen and in letters.
        $dob = trim((string)($client['dob'] ?? ''));
        if ($dob !== '') {
            $forms = [$dob];
            if (($ts = strtotime($dob)) !== false) array_push($forms, date('F j, Y', $ts), date('M j, Y', $ts), date('m/d/Y', $ts), date('n/j/Y', $ts));
            $text = str_ireplace(array_values(array_unique($forms)), '[DOB]', $text);
        }
    }
    $text = preg_replace('/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/', '[EMAIL]', $text);
    // SSN: 3-2-4 digits split by the same hyphen, space or dot each time, or a bare
    // 9-digit run. Phone: optional +1, then 3-3-4 digits split only by a space, dot
    // or hyphen (area code may be in parentheses); or "+" and a country code with
    // at least 8 digits in all. None crosses a line break, and the digit/hyphen
    // look-arounds stop them matching inside a longer number or a date such as
    // 2026-09-30, so dates sent to the AI (auth expiry, last note) survive redaction.
    $text = preg_replace('/(?<![\d-])(?:\d{3}([- .])\d{2}\1\d{4}|\d{9})(?![\d-])/', '[SSN]', $text); // before phone
    $text = preg_replace('/(?<![\d-])(?:\+?1[ .-]?)?(?:\(\d{3}\)|\d{3})[ .-]?\d{3}[ .-]?\d{4}(?![\d-])/', '[PHONE]', $text);
    $text = preg_replace_callback('/(?<![\w+])\+\d{1,3}(?:[ .-]?\d{2,5}){2,5}(?![\d-])/',
        fn($m) => preg_match_all('/\d/', $m[0]) >= 8 ? '[PHONE]' : $m[0], $text) ?? $text;
    return $text;
}

/**
 * Governed generation: enforces the feature toggle, redacts the prompt (unless
 * an admin turned redaction off), calls the provider, and logs usage METADATA
 * only (feature, provider, model, char counts, redacted flag — never the prompt
 * or the output). Returns the same shape as ai_generate().
 */
/** Per-user rate limit for paid AI calls (shared bucket), via the login_attempts
 * table. Returns false when the user is over the limit. Fails open on DB error. */
function ai_rate_limit(int $userId, int $max = 60, int $mins = 10): bool {
    if ($userId <= 0) return true;
    try {
        $q = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)");
        $q->execute(['ai-' . $userId, $mins]);
        if ((int)$q->fetchColumn() >= $max) return false;
        db()->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?,?,1)")->execute([$_SERVER['REMOTE_ADDR'] ?? '', 'ai-' . $userId]);
    } catch (Throwable $e) { /* never block AI on a limiter error */ }
    return true;
}

function ai_run(string $feature, string $system, string $prompt, int $maxTokens = 1500, ?array $redactClient = null, ?int $actorId = null): array {
    if (!ai_feature_enabled($feature)) return ['ok' => false, 'text' => '', 'error' => 'That AI feature is turned off. An administrator can enable it under AI assistant settings.'];
    if ($actorId !== null && !ai_rate_limit((int)$actorId)) return ['ok' => false, 'text' => '', 'error' => 'You have reached the AI usage limit for now. Please wait a few minutes and try again.'];
    $redacted = ai_redaction_on();
    $sent = $redacted ? ai_redact($prompt, $redactClient) : $prompt;
    $res = ai_generate($system, $sent, $maxTokens);
    ai_log_usage($feature, mb_strlen($sent), $res['ok'] ? mb_strlen($res['text']) : 0, $redacted, $actorId);
    return $res;
}

/**
 * Case-note generator: turn a clinician's messy, raw session notes directly into
 * a clean structured note in the chosen format (BIRP/PAIP/SOAP), plus clarifying
 * questions and any homework the notes imply. Returns
 * ['ok', 'sections'=>[4 strings], 'questions'=>[strings], 'homework'=>[['title','instructions','due']], 'error'].
 */
function ai_generate_case_note(string $messy, string $fmt, ?array $client, int $actorId): array {
    $blank = ['ok' => false, 'error' => '', 'sections' => ['', '', '', ''], 'questions' => [], 'homework' => []];
    if (!isset(NOTE_FORMATS[$fmt]) || $fmt === 'free') $fmt = 'BIRP';
    $labels = NOTE_FORMATS[$fmt];
    $sys = ai_system_base()
        . ' Convert the clinician\'s messy, raw assistive-technology session notes directly into a clean, professional ' . $fmt
        . ' case note. Reply with ONE JSON object and nothing else, shaped exactly: '
        . '{"sections":["<' . $labels[0] . '>","<' . $labels[1] . '>","<' . $labels[2] . '>","<' . $labels[3] . '>"],'
        . '"questions":["..."],"homework":[{"title":"...","instructions":"...","due":"YYYY-MM-DD"}]}. '
        . '"sections" is exactly four strings, one each for ' . implode(', ', $labels) . ', in full prose drawn only from the notes. '
        . 'Keep every fact; never invent names, dates, hours, or outcomes — where a detail is missing, leave a [bracketed] placeholder. '
        . '"questions" lists short, specific things the clinician likely left out or that are ambiguous and are needed for a complete note (empty array if the note is already complete). '
        . '"homework" lists any practice, assignment, or task the client is to do before the next session, taken only from the notes (empty array if none); '
        . '"due" is a date written YYYY-MM-DD (today is ' . date('Y-m-d') . ') only when the notes give one, otherwise "".';
    // Room for four full sections, which run about as long as the notes themselves.
    $res = ai_run('draft', $sys, "Messy session notes:\n\n" . $messy, max(1600, ai_output_tokens($messy, 1.6)), $client, $actorId);
    if (!$res['ok']) { $blank['error'] = $res['error']; return $blank; }
    // Cut off at the token limit: the JSON is incomplete, and retrying fails the same way.
    if (!empty($res['truncated'])) { $blank['error'] = 'These notes are too long to convert in one go. Shorten them or split the session into parts, or use "Format with AI".'; return $blank; }
    $txt = $res['text'];
    if (preg_match('/\{.*\}/s', $txt, $m)) $txt = $m[0];
    $j = json_decode($txt, true);
    if (!is_array($j)) { $blank['error'] = 'The AI response could not be read — try again, or use "Format with AI".'; return $blank; }
    // A value the model returned as a list (e.g. a bulleted Plan) becomes one line
    // per item; anything else that is not text is dropped, never cast to "Array".
    $str = function ($v) use (&$str): string {
        if (is_array($v)) return trim(implode("\n", array_filter(array_map($str, array_values($v)), fn($s) => $s !== '')));
        return (is_string($v) || is_int($v) || is_float($v)) ? trim((string)$v) : '';
    };
    $sections = ['', '', '', ''];
    if (isset($j['sections']) && is_array($j['sections'])) {
        $vals = array_values($j['sections']);
        for ($i = 0; $i < 4; $i++) $sections[$i] = isset($vals[$i]) ? $str($vals[$i]) : '';
    }
    $questions = [];
    if (isset($j['questions']) && is_array($j['questions'])) {
        foreach ($j['questions'] as $q) { $q = $str($q); if ($q !== '') $questions[] = mb_substr($q, 0, 300); }
    }
    $homework = [];
    if (isset($j['homework']) && is_array($j['homework'])) {
        foreach ($j['homework'] as $hw) {
            if (!is_array($hw)) continue;
            $title = $str($hw['title'] ?? ''); if ($title === '') continue;
            $due = $str($hw['due'] ?? '');
            // Only a real YYYY-MM-DD from today on; "May" or "a week" is never guessed into a date.
            $due = valid_date($due) ?? '';
            if ($due !== '' && $due < date('Y-m-d')) $due = '';
            $homework[] = ['title' => mb_substr($title, 0, 190), 'instructions' => mb_substr($str($hw['instructions'] ?? ''), 0, 1000), 'due' => $due];
        }
    }
    return ['ok' => true, 'error' => '', 'sections' => $sections, 'questions' => array_slice($questions, 0, 8), 'homework' => array_slice($homework, 0, 10)];
}

function ai_log_usage(string $feature, int $charsIn, int $charsOut, bool $redacted, ?int $actorId = null): void {
    $cfg = ai_cfg();
    try {
        db()->prepare('INSERT INTO ai_usage (user_id, feature, provider, model, chars_in, chars_out, redacted) VALUES (?,?,?,?,?,?,?)')
            ->execute([$actorId, $feature, $cfg['provider'] ?? '', $cfg['model'] ?? '', $charsIn, $charsOut, $redacted ? 1 : 0]);
    } catch (Throwable $e) { /* usage logging is best-effort */ }
}

/** Shared guardrails prepended to every drafting prompt. */
function ai_system_base(): string {
    return "You are a documentation assistant for an assistive-technology vocational rehabilitation (VR) provider. "
        . "You write clear, professional, factual case documentation. Use only the information provided — never invent "
        . "names, dates, hours, diagnoses, or outcomes that are not in the input. When information is missing, leave a "
        . "clearly marked placeholder in square brackets for the staff member to fill in. Write in a respectful, "
        . "person-first, plain-language style appropriate for a VR counselor audience. Produce a draft the staff member "
        . "will review and edit; do not include any preamble like 'Here is the draft' — output only the document text. "
        . "Unless the instructions that follow ask for a specific format (such as JSON or tagged sections) or to keep the "
        . "input's own formatting, write plain text with no Markdown: no # heading marks, no asterisks or underscores for "
        . "emphasis, no tables. Staff read it with screen readers and paste it into case records.";
}
