<?php
declare(strict_types=1);
// RFC 6238 TOTP (SHA-1, 6 digits, 30-second steps) — compatible with all
// standard authenticator apps (Microsoft/Google Authenticator, Authy, 1Password).

function base32_encode(string $bytes): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function base32_decode_str(string $s): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
    $bits = '';
    for ($i = 0; $i < strlen($s); $i++) {
        $v = strpos($alphabet, $s[$i]);
        if ($v === false) continue;
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) $out .= chr(bindec($chunk));
    }
    return $out;
}

function totp_generate_secret(): string {
    return base32_encode(random_bytes(20)); // 160-bit secret
}

function totp_code(string $base32Secret, ?int $time = null): string {
    $key = base32_decode_str($base32Secret);
    $counter = (int)floor(($time ?? time()) / 30);
    $binCounter = pack('N2', 0, $counter);
    $hash = hash_hmac('sha1', $binCounter, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $code = (unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF) % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

/** Verify with ±1 step tolerance for clock drift. */
function totp_verify(string $base32Secret, string $code): bool {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return false;
    foreach ([-1, 0, 1] as $drift) {
        if (hash_equals(totp_code($base32Secret, time() + $drift * 30), $code)) return true;
    }
    return false;
}

/**
 * Verify a sign-in TOTP code for a user AND consume its time step, so the
 * same code cannot be replayed within its validity window. Use this for
 * sign-ins; plain totp_verify() stays for enrollment confirmation.
 */
function totp_verify_once(string $base32Secret, string $code, int $userId): bool {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return false;
    $matched = null;
    foreach ([-1, 0, 1] as $drift) {
        $step = (int)floor(time() / 30) + $drift;
        if (hash_equals(totp_code($base32Secret, $step * 30), $code)) { $matched = $step; break; }
    }
    if ($matched === null) return false;
    // Atomically claim the step: fails if an equal-or-later step was already used.
    $u = db()->prepare('UPDATE users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)');
    $u->execute([$matched, $userId, $matched]);
    return $u->rowCount() === 1;
}

function totp_provisioning_uri(string $secret, string $accountEmail): string {
    return 'otpauth://totp/' . rawurlencode(brand('name') . ':' . $accountEmail)
        . '?secret=' . $secret . '&issuer=' . rawurlencode(brand('name')) . '&digits=6&period=30';
}
