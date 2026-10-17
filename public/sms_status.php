<?php
// A delivery report for a text (4.17.0): Twilio calls this address with each change of status.
// Only a request signed with the account's Auth Token is read.
require __DIR__ . '/inc/core.php';
$url = rtrim((string)cfg('base_url'), '/') . '/sms_status.php';
$fields = array_filter($_POST, 'is_scalar');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !sms_signature_ok($url, $fields, (string)($_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? ''))) {
    http_response_code(403);
    exit;
}
try {
    sms_status_update((string)($fields['MessageSid'] ?? ''), (string)($fields['MessageStatus'] ?? ''), isset($fields['ErrorCode']) ? 'Error ' . $fields['ErrorCode'] : null);
} catch (Throwable $e) {
    error_log('sms_status: ' . get_class($e));
}
http_response_code(204);
