<?php
require __DIR__ . '/inc/bootstrap.php';
csrf_check();
// Sign-out only via the POST form (a plain GET link from another site
// should not be able to end the session).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
session_unset();
session_destroy();
session_start();
session_regenerate_id(true);
flash('You have been signed out.');
redirect('login.php');
