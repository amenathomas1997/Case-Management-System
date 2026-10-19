<?php
// Connect your Zoom or Microsoft (Teams) account (4.10.0): sends you to Zoom or Microsoft to
// sign in and allow the app to make meetings, then keeps the connection (inc/meetings.php).
require __DIR__ . '/inc/bootstrap.php';
csrf_check();
$user = require_login();
$provider = is_string($_GET['provider'] ?? null) && isset(MEETING_PROVIDERS[$_GET['provider']]) ? $_GET['provider'] : '';
$back = 'account.php#meetings-h';
$returning = isset($_GET['code']) || isset($_GET['error']);
// Starting a connection is a button on My Account (a form with its check), never a plain link.
if (!$returning && $_SERVER['REQUEST_METHOD'] !== 'POST') redirect($back);
$app = $provider !== '' ? meeting_app($provider) : null;
if (!$app) { flash('That meeting service is not set up yet. An administrator sets it up on Settings, Online meetings.', 'error'); redirect($back); }

// Back from Zoom or Microsoft.
if ($returning) {
    $expected = $_SESSION['meeting_oauth'] ?? null;
    unset($_SESSION['meeting_oauth']);
    if (!is_array($expected) || !hash_equals((string)$expected['state'], (string)($_GET['state'] ?? '')) || $expected['provider'] !== $provider) {
        flash('That sign-in could not be matched to your request, so nothing was connected. Try Connect again.', 'error');
        redirect($back);
    }
    if (isset($_GET['error'])) { flash(MEETING_PROVIDERS[$provider] . ' was not connected (it was cancelled or refused).', 'error'); redirect($back); }
    $tok = meeting_token_request($app, ['grant_type' => 'authorization_code', 'code' => (string)$_GET['code'], 'redirect_uri' => $app['redirect']]
        + ($app['scope'] !== '' ? ['scope' => $app['scope']] : []));
    if ($tok === null) { flash(MEETING_PROVIDERS[$provider] . ' did not accept the sign-in, so nothing was connected. Try again, or ask an administrator to check the app settings.', 'error'); redirect($back); }
    // Which account it is, so My Account can say so.
    [$code, $me] = $provider === 'zoom'
        ? meeting_http('GET', 'https://api.zoom.us/v2/users/me', ['Authorization: Bearer ' . $tok['access_token']], null)
        : meeting_http('GET', 'https://graph.microsoft.com/v1.0/me', ['Authorization: Bearer ' . $tok['access_token']], null);
    $email = (string)($me['email'] ?? $me['mail'] ?? $me['userPrincipalName'] ?? '');
    try {
        meeting_store((int)$user['id'], $provider, $tok, $email);
    } catch (MeetingKeyUnavailable $e) {
        // 4.29.0 (P14): the key file is the problem, not the database.
        error_log('meeting_connect: ' . $e->getMessage());
        flash(MEETING_PROVIDERS[$provider] . ' could not be connected: ' . MEETING_KEY_LOST_MSG, 'error');
        redirect($back);
    } catch (Throwable $e) {
        error_log('meeting_connect: ' . get_class($e) . ' ' . $e->getMessage());
        flash(MEETING_PROVIDERS[$provider] . ' could not be connected: the connection could not be stored. Ask an administrator to run Database upgrade.', 'error');
        redirect($back);
    }
    audit((int)$user['id'], 'meeting.connect', 'user', (int)$user['id'], $provider . ($email !== '' ? ' (' . $email . ')' : ''));
    flash(MEETING_PROVIDERS[$provider] . ' is connected' . ($email !== '' ? ' as ' . $email : '') . '. Remote sessions booked for you can now get a ' . MEETING_PROVIDERS[$provider] . ' link.');
    redirect($back);
}

// Not sent off to sign in when the connection could not be stored afterwards (4.29.0).
try { meeting_key(); } catch (MeetingKeyUnavailable $e) { flash(MEETING_PROVIDERS[$provider] . ' could not be connected: ' . MEETING_KEY_LOST_MSG, 'error'); redirect($back); }
// Going to Zoom or Microsoft: a one-time value proves the reply belongs to this request.
$state = bin2hex(random_bytes(16));
$_SESSION['meeting_oauth'] = ['state' => $state, 'provider' => $provider];
$q = ['response_type' => 'code', 'client_id' => $app['id'], 'redirect_uri' => $app['redirect'], 'state' => $state];
if ($app['scope'] !== '') { $q['scope'] = $app['scope']; $q['prompt'] = 'select_account'; }
header('Location: ' . $app['authorize'] . '?' . http_build_query($q));
exit;
