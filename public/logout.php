<?php
require __DIR__ . '/inc/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
// An old tab offers a fresh confirmation rather than either signing out an
// unrelated session or leaving the person stuck in a stale-token redirect.
$token = $_POST['csrf'] ?? '';
if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
    require __DIR__ . '/inc/layout.php';
    http_response_code(409);
    page_header('Confirm sign out');
    echo '<h1>Confirm sign out</h1><p>Your sign-in session changed since this page was opened. Choose Sign out to end the current session.</p>'
        . '<form method="post" action="logout.php">' . csrf_field()
        . '<button class="btn btn-primary" type="submit">Sign out</button></form>';
    page_footer();
    exit;
}
csrf_check();
session_unset();
session_destroy();
session_start();
session_regenerate_id(true);
flash('You have been signed out.');
redirect('login.php');
