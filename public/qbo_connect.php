<?php
// Connect the organisation's QuickBooks Online company (4.21.0, administrators): sends the administrator
// to Intuit to sign in and allow it, then keeps the connection (inc/qbo.php).
require __DIR__ . '/inc/bootstrap.php';
csrf_check();
$user = require_login();
require_perm('admin');
$back = 'qbo.php';
$returning = isset($_GET['code']) || isset($_GET['error']);
// Starting is a button on the QuickBooks page (a form with its check), never a plain link.
if (!$returning && $_SERVER['REQUEST_METHOD'] !== 'POST') redirect($back);
$app = qbo_app();
if (!$app) { flash('QuickBooks Online is not set up yet: enter the app\'s keys on Settings, QuickBooks Online.', 'error'); redirect($back); }

if ($returning) {
    $expected = $_SESSION['qbo_oauth'] ?? null;
    unset($_SESSION['qbo_oauth']);
    if (!is_array($expected) || !hash_equals((string)$expected['state'], (string)($_GET['state'] ?? ''))) {
        flash('That sign-in could not be matched to your request, so nothing was connected. Try Connect again.', 'error');
        redirect($back);
    }
    $realm = (string)($_GET['realmId'] ?? '');
    if (isset($_GET['error']) || !preg_match('/^\d{1,40}$/', $realm)) { flash('QuickBooks was not connected (it was cancelled or refused).', 'error'); redirect($back); }
    $tok = qbo_token_request($app, ['grant_type' => 'authorization_code', 'code' => (string)$_GET['code'], 'redirect_uri' => $app['redirect']]);
    if ($tok === null) { flash('QuickBooks did not accept the sign-in, so nothing was connected. Try again, or check the app\'s keys.', 'error'); redirect($back); }
    try {
        qbo_store($tok, $realm, null, (int)$user['id']);
    } catch (MeetingKeyUnavailable $e) {
        // 4.29.0 (P14): the real reason and the way out, never a server error on the way back from Intuit.
        error_log('qbo_connect: ' . $e->getMessage());
        audit((int)$user['id'], 'qbo.connect_fail', 'settings', null, 'key file unusable: ' . mb_substr($e->getMessage(), 0, 150));
        flash('QuickBooks was not connected: ' . MEETING_KEY_LOST_MSG, 'error');
        redirect($back);
    }
    // The company's name, so the page can say which books these are.
    [$code, $info] = qbo_api('GET', '/companyinfo/' . rawurlencode($realm));
    $name = (string)($info['CompanyInfo']['CompanyName'] ?? '');
    if ($name !== '') db()->prepare('UPDATE qbo_connection SET company_name = ? WHERE id = 1')->execute([mb_substr($name, 0, 190)]);
    audit((int)$user['id'], 'qbo.connect', 'settings', null, $name !== '' ? $name : 'company ' . $realm);
    flash('QuickBooks is connected' . ($name !== '' ? ' to ' . $name : '') . '. Submitted invoices go there each morning, or now with Send invoices now.');
    redirect($back);
}

// Not sent off to sign in when the connection could not be stored afterwards (4.29.0).
try { meeting_key(); } catch (MeetingKeyUnavailable $e) { flash('QuickBooks was not connected: ' . MEETING_KEY_LOST_MSG, 'error'); redirect($back); }
$state = bin2hex(random_bytes(16));
$_SESSION['qbo_oauth'] = ['state' => $state];
header('Location: ' . $app['authorize'] . '?' . http_build_query(['client_id' => $app['id'], 'response_type' => 'code', 'scope' => QBO_SCOPE, 'redirect_uri' => $app['redirect'], 'state' => $state]));
exit;
