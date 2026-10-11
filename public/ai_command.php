<?php
// Voice-assistant intent endpoint (web, session + CSRF). All logic lives in
// inc/voice.php (shared with the native app's api.php?action=voice_command).
// Interprets a natural-language transcript into an app action — navigate,
// search, open a client, read-aloud answers, or CREATE records. Interpretation
// never writes: it returns a confirmation and the client calls back with
// do=<action> to commit. JSON in / out.
require __DIR__ . '/inc/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
// Signed out (here or in another tab) or timed out: a JSON 401 the voice panel
// can explain, not the sign-in redirect, which would also save this endpoint as
// the page to return to after signing in. The page stays open (a reload would
// lose what is typed in its forms): sign in again in a new tab.
$timeout = ((int)cfg('session_timeout_minutes')) * 60;
if (empty($_SESSION['user_id']) || (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > $timeout)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'You have been signed out. What you typed on this page is still here. Sign in again in a new tab, then come back to this page and try again.']);
    exit;
}
csrf_check();
$user = require_login();
// The panel can be open for any signed-in user; its commands work on client
// records. Refused in JSON (not require_perm's redirect and banner).
if (!can('clients')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Your account does not have access to the voice assistant (Clients & case records). Ask an administrator if you need it.']);
    exit;
}

// Commands (and above all confirmed writes) are accepted by POST only, so the
// CSRF check above always applies to them.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Use POST for voice commands.']);
    exit;
}
if (!ai_feature_enabled('voice')) { echo json_encode(['ok' => false, 'error' => 'The voice assistant is turned off.']); exit; }
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
// Every field is read as a single value except the conversation history, the
// page context the browser adds, and a params object. A stray list anywhere
// else is refused rather than cast to "Array" (or to 1) and written to a record.
foreach ($in as $key => $value) {
    if (is_array($value) && !in_array($key, ['history', 'context', 'params'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid input.']);
        exit;
    }
}

try {
    $reply = voice_handle($in, $user);
} catch (Throwable $e) {
    // Class, code and location only — the message can quote record data.
    error_log('ai_command.php: ' . get_class($e) . ' code=' . (string)$e->getCode() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    try { if (db()->inTransaction()) db()->rollBack(); } catch (Throwable $ignored) {}
    http_response_code(($e instanceof TypeError || $e instanceof ValueError) ? 400 : 500);
    $reply = ['ok' => false, 'error' => 'Something went wrong and nothing was saved. Please try again.'];
}
echo json_encode($reply);
exit;
