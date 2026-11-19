<?php
declare(strict_types=1);
// The AI curriculum generator's lesson plan (4.34.0): its prompt, its limits and how the plan's
// plain text is read back as lessons and skills. Shared by the website (curriculum_ai.php) and the
// app (api.php ai_curriculum), so both draft the same plan for the same request.

/** Most text accepted per request (sent to the AI provider or stored as skills). */
const CURRICULUM_AI_MAX_CHARS = 20000;
/** The reply budget: a whole lesson plan with its steps. */
const CURRICULUM_AI_TOKENS = 4000;
/** The learner levels offered. */
const CURRICULUM_AI_LEVELS = ['Beginner', 'Intermediate', 'Advanced'];

function curriculum_ai_system(): string {
    return ai_system_base() . ' You design training curricula for assistive technology instruction. Produce an ordered lesson plan for the given technology and learner level: '
        . 'a sequence of lessons, each teaching concrete skills a learner can demonstrate. Write it in exactly this plain-text layout, with no preamble or commentary, and a blank line between lessons:'
        . "\nLesson: <short lesson title>"
        . "\nObjectives:\n- <one objective per line, each a single skill the learner can demonstrate, at most 300 characters>"
        . "\nSteps to complete the task:\n1. <numbered, learner-facing steps in order: what the learner does, naming the keystrokes, gestures, settings or app features where relevant (for example the JAWS or VoiceOver command, the braille display key, or the menu in the app)>"
        . "\nHow you know it's done:\n- <one or two success criteria>"
        . ' Keep every objective on one line, and use only those four labels.';
}

function curriculum_ai_prompt(string $tech, string $level, string $focus): string {
    return "Technology: {$tech}\nLevel: {$level}\nFocus / notes: " . (trim($focus) ?: '[none]');
}

/**
 * The plan's plain text as lessons: each "Lesson: <title>" block with its
 * Objectives, Steps to complete the task and How you know it's done lines. Text
 * that does not follow the layout gives no lessons (it is then shown as is).
 */
function curriculum_parse_plan(string $text): array {
    $lessons = []; $cur = null; $sect = '';
    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
        $t = trim($line);
        if ($t === '') continue;
        if (preg_match('/^lesson(?:\s*\d+)?\s*:\s*(.+)$/iu', $t, $m)) {
            if ($cur) $lessons[] = $cur;
            $cur = ['title' => trim($m[1]), 'objectives' => [], 'steps' => [], 'done' => []]; $sect = '';
            continue;
        }
        if ($cur === null) continue;
        if (preg_match('/^objectives?\s*:\s*(.*)$/iu', $t, $m)) { $sect = 'objectives'; $t = trim($m[1]); }
        elseif (preg_match('/^steps(?:\s+to complete the task)?\s*:\s*(.*)$/iu', $t, $m)) { $sect = 'steps'; $t = trim($m[1]); }
        elseif (preg_match('/^how you know it\W?s done\s*:\s*(.*)$/iu', $t, $m)) { $sect = 'done'; $t = trim($m[1]); }
        if ($t === '' || $sect === '') continue;
        $t = preg_replace('/^(?:[-*•]|\d{1,3}[.)])\s+/u', '', $t);
        if ($t !== '') $cur[$sect][] = $t;
    }
    if ($cur) $lessons[] = $cur;
    return $lessons;
}

/** The curriculum's skills from a plan: the objectives, one per line (a lesson with none gives its
 *  title); a reply not in the lesson layout is used line by line. */
function curriculum_skills_text(string $planText): string {
    $lessons = curriculum_parse_plan($planText);
    $lines = [];
    foreach ($lessons as $ls) foreach ($ls['objectives'] ?: [$ls['title']] as $o) $lines[] = $o;
    return $lessons ? implode("\n", $lines) : $planText;
}
