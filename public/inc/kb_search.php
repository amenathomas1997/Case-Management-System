<?php
declare(strict_types=1);
// Knowledge-base retrieval (4.34.0): the words a question is searched by and the articles that
// mention them, ranked. Shared by the website's assistant (kb_ask.php) and the app's (api.php
// ai_kb), so both find the same articles for the same question.

/** Words too common to say which article a question is about. */
const KB_STOP_WORDS = ['the', 'and', 'for', 'are', 'but', 'not', 'you', 'your', 'our', 'can', 'could', 'should', 'would', 'will',
    'what', 'when', 'where', 'which', 'who', 'whom', 'whose', 'why', 'how', 'does', 'did', 'doing', 'done', 'has', 'have', 'had',
    'was', 'were', 'been', 'being', 'this', 'that', 'these', 'those', 'with', 'from', 'into', 'onto', 'about', 'after', 'before',
    'there', 'here', 'then', 'than', 'them', 'they', 'their', 'its', 'his', 'her', 'him', 'she', 'any', 'all', 'some', 'more',
    'most', 'other', 'such', 'only', 'also', 'just', 'very', 'get', 'got', 'set', 'use', 'using', 'need', 'make', 'way', 'want',
    'like', 'please', 'tell', 'know', 'one', 'out', 'off', 'via', 'per'];

/** The question's search terms: its words (letters and digits only, so they hold no LIKE
 *  wildcards), minus short and common words, up to 8 distinct ones. A plural's final "s" is
 *  dropped so "profiles" also finds "profile". */
function kb_search_terms(string $q): array {
    $terms = [];
    foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
        if (mb_strlen($w) < 3 || in_array($w, KB_STOP_WORDS, true)) continue;
        if (mb_strlen($w) > 4 && str_ends_with($w, 's')) $w = mb_substr($w, 0, -1);
        $terms[(string)$w] = true;
    }
    return array_map('strval', array_slice(array_keys($terms), 0, 8));
}

/** The 6 articles that mention the most terms (a term in the title counts twice), then the most
 *  recently updated. $staff false keeps to the articles shared publicly. COALESCE: a NULL category
 *  or body counts as "no match" and never turns the score NULL. */
function kb_rank_articles(array $terms, bool $staff = true): array {
    if (!$terms) return [];
    $score = []; $args = [];
    foreach ($terms as $w) {
        $score[] = '(CASE WHEN title LIKE ? THEN 2 WHEN COALESCE(category, \'\') LIKE ? OR COALESCE(body, \'\') LIKE ? THEN 1 ELSE 0 END)';
        $l = '%' . $w . '%'; array_push($args, $l, $l, $l);
    }
    $st = db()->prepare('SELECT id, title, category, body FROM (SELECT id, title, category, body, updated_at, ' . implode(' + ', $score) . ' AS score FROM kb_articles'
        . ($staff ? '' : ' WHERE is_public = 1') . ') k WHERE k.score > 0 ORDER BY k.score DESC, k.updated_at DESC LIMIT 6');
    $st->execute($args);
    return $st->fetchAll();
}

/** The sentence when a question has no words to search by. */
const KB_NO_WORDS = 'That question has no words to search the articles for. Include the key words, for example the product or task name.';

/** The sentence when no article mentions the terms. */
function kb_no_match_message(array $terms): string {
    return 'No article in the Staff knowledge base mentions ' . implode(', ', array_map(fn($t) => '“' . $t . '”', $terms)) . '. The assistant answers only from your own articles.';
}

/** One article as the assistant's context gives it: title, category, and the body cut at 2,500 characters. */
function kb_context_line(array $a): string {
    return "Article: " . $a['title'] . ($a['category'] ? " ({$a['category']})" : '') . "\n" . mb_strimwidth((string)$a['body'], 0, 2500, '…') . "\n\n";
}
