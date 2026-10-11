<?php
// Session keepalive for the inactivity-timeout warning dialog (WCAG 2.2.1).
// Extends the session only while it is still valid; 401 once it has expired.
require __DIR__ . '/inc/bootstrap.php';

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
$timeout = ((int)cfg('session_timeout_minutes')) * 60;
if (($u = current_user()) && isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) <= $timeout) {
    $_SESSION['last_activity'] = time();
    header('X-CSRF-Token: ' . csrf_token());
    // session-forms.js compares this with the id baked into the page, so a form
    // held open across a sign-out and another person's sign-in is not resubmitted
    // under the new account.
    header('X-User-Id: ' . (int)$u['id']);
    echo 'ok';
} else {
    http_response_code(401);
    echo 'expired';
}
