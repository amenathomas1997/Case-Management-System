<?php
// Text-to-speech proxy: returns human-voice audio for a short piece of text.
// When the provider can't do TTS (or it's off), returns 409 JSON so the caller
// falls back to the browser's built-in voice. Governed by ai_speak(), which sends
// only to the configured endpoint and logs the call against this user.
require __DIR__ . '/inc/bootstrap.php';
csrf_check();
$user = require_login();

if (!ai_feature_enabled('readaloud') || !ai_speak_capable()) {
    http_response_code(409);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Provider voice unavailable; use the browser voice.']);
    exit;
}

$in = json_decode((string)file_get_contents('php://input'), true);
$text = trim((string)($in['text'] ?? ''));
$voice = (string)($in['voice'] ?? 'nova');
if ($text === '') { http_response_code(400); exit; }

// Rate-limit TTS (it costs money): 60 calls / 10 min / user.
$rl = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
$rl->execute(['tts-' . (int)$user['id']]);
if ((int)$rl->fetchColumn() >= 60) { http_response_code(429); exit; }
db()->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?,?,1)")->execute([$_SERVER['REMOTE_ADDR'] ?? '', 'tts-' . (int)$user['id']]);

$res = ai_speak($text, $voice, (int)$user['id']);
if (!$res['ok']) {
    http_response_code(409);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $res['error']]);
    exit;
}
header('Content-Type: ' . $res['mime']);
header('Content-Length: ' . strlen($res['bytes']));
header('Cache-Control: private, max-age=600');
echo $res['bytes'];
