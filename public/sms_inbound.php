<?php
// A text a client sent (4.17.0): Twilio calls this address (Settings, Text messages, shows it).
// Only a request signed with the account's Auth Token is read; the reply, if any, goes back as TwiML.
require __DIR__ . '/inc/core.php';
header('Content-Type: text/xml; charset=utf-8');
$url = rtrim((string)cfg('base_url'), '/') . '/sms_inbound.php';
$fields = array_filter($_POST, 'is_scalar');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !sms_signature_ok($url, $fields, (string)($_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? ''))) {
    http_response_code(403);
    exit('<?xml version="1.0" encoding="UTF-8"?><Response/>');
}
$reply = null;
try {
    $reply = sms_inbound((string)($fields['From'] ?? ''), (string)($fields['Body'] ?? ''), (string)($fields['MessageSid'] ?? ''));
} catch (Throwable $e) {
    error_log('sms_inbound: ' . get_class($e) . ' ' . $e->getCode());
}
echo '<?xml version="1.0" encoding="UTF-8"?><Response>' . ($reply !== null ? '<Message>' . htmlspecialchars($reply, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Message>' : '') . '</Response>';
