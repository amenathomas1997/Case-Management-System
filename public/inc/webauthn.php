<?php
declare(strict_types=1);
// Minimal WebAuthn (passkey) support — no dependencies.
// Registration uses attestation "none"; assertions are verified with openssl.
// Supports ES256 (-7) and RS256 (-257), which covers Apple, Google, Windows
// Hello, and every mainstream password manager.

// Exception codes that tell passkey.php which plain-language message to show.
// The exception text itself is technical, for the server log only.
const WEBAUTHN_ERR_MALFORMED = 0;   // a response that could not be read or checked
const WEBAUTHN_ERR_CHALLENGE = 1;   // expired, or replaced by a newer attempt (another tab)
const WEBAUTHN_ERR_ORIGIN = 2;      // made for another web address
const WEBAUTHN_ERR_KEY_TYPE = 3;    // a key algorithm this system does not support

function b64url_encode(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function b64url_decode(string $s): string {
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return (string)base64_decode(strtr($s, '-_', '+/'), true);
}

/** The relying-party id: the host of base_url (no scheme/port). */
function webauthn_rp_id(): string {
    return (string)parse_url((string)cfg('base_url'), PHP_URL_HOST);
}

/**
 * Decode one CBOR item starting at $off (advanced past it on return).
 * Handles the subset WebAuthn uses: ints, byte/text strings, arrays, maps.
 */
function cbor_decode(string $data, int &$off = 0, int $depth = 0) {
    if ($depth > 8) throw new RuntimeException('CBOR: nesting too deep');
    if ($off >= strlen($data)) throw new RuntimeException('CBOR: unexpected end');
    $byte = ord($data[$off++]);
    $major = $byte >> 5;
    $info = $byte & 0x1F;

    $need = [24 => 1, 25 => 2, 26 => 4, 27 => 8][$info] ?? 0;
    if ($need > 0 && $off + $need > strlen($data)) throw new RuntimeException('CBOR: truncated length');
    $len = $info;
    if ($info === 24) { $len = ord($data[$off]); $off += 1; }
    elseif ($info === 25) { $len = unpack('n', substr($data, $off, 2))[1]; $off += 2; }
    elseif ($info === 26) { $len = unpack('N', substr($data, $off, 4))[1]; $off += 4; }
    elseif ($info === 27) { $len = unpack('J', substr($data, $off, 8))[1]; $off += 8; }
    elseif ($info > 27) throw new RuntimeException('CBOR: unsupported length encoding');
    if ($major >= 2 && $major <= 5 && ($len < 0 || $len > 100000)) {
        throw new RuntimeException('CBOR: implausible length');
    }

    switch ($major) {
        case 0: return $len;                    // unsigned int
        case 1: return -1 - $len;               // negative int
        case 2:                                 // byte string
        case 3:                                 // text string
            $s = substr($data, $off, $len);
            if (strlen($s) !== $len) throw new RuntimeException('CBOR: truncated string');
            $off += $len;
            return $s;
        case 4:                                 // array
            $out = [];
            for ($i = 0; $i < $len; $i++) $out[] = cbor_decode($data, $off, $depth + 1);
            return $out;
        case 5:                                 // map
            $out = [];
            for ($i = 0; $i < $len; $i++) {
                $k = cbor_decode($data, $off, $depth + 1);
                $out[is_int($k) || is_string($k) ? $k : json_encode($k)] = cbor_decode($data, $off, $depth + 1);
            }
            return $out;
        case 7:                                 // simple values
            if ($info === 20) return false;
            if ($info === 21) return true;
            if ($info === 22) return null;
            throw new RuntimeException('CBOR: unsupported simple value');
        default:
            throw new RuntimeException('CBOR: unsupported major type ' . $major);
    }
}

/**
 * Parse authenticatorData into its parts. Attested credential data is only
 * decoded when the caller expects it (registration); assertions never need it.
 */
function webauthn_parse_auth_data(string $authData, bool $expectAttested = false): array {
    if (strlen($authData) < 37) throw new RuntimeException('authData too short');
    $out = [
        'rpIdHash' => substr($authData, 0, 32),
        'flags' => ord($authData[32]),
        'signCount' => unpack('N', substr($authData, 33, 4))[1],
        'credentialId' => null,
        'cosePublicKey' => null,
    ];
    if ($expectAttested && ($out['flags'] & 0x40)) { // attested credential data present
        $aaguidEnd = 37 + 16;
        if (strlen($authData) < $aaguidEnd + 2) throw new RuntimeException('authData truncated');
        $credLen = unpack('n', substr($authData, $aaguidEnd, 2))[1];
        $out['credentialId'] = substr($authData, $aaguidEnd + 2, $credLen);
        if (strlen($out['credentialId']) !== $credLen) throw new RuntimeException('authData truncated');
        $off = $aaguidEnd + 2 + $credLen;
        $out['cosePublicKey'] = cbor_decode($authData, $off);
    }
    return $out;
}

/** Convert a COSE public key map to [PEM, 'ES256'|'RS256']. */
function cose_key_to_pem(array $cose): array {
    $kty = $cose[1] ?? null;
    $alg = $cose[3] ?? null;
    if ($kty === 2 && $alg === -7) { // EC2 / P-256 / ES256
        $x = $cose[-2] ?? '';
        $y = $cose[-3] ?? '';
        if (strlen($x) !== 32 || strlen($y) !== 32) throw new RuntimeException('Bad EC key');
        // SubjectPublicKeyInfo for id-ecPublicKey + prime256v1 with the
        // uncompressed point 04||X||Y.
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
        return [webauthn_der_to_pem($der), 'ES256'];
    }
    if ($kty === 3 && $alg === -257) { // RSA / RS256
        $n = $cose[-1] ?? '';
        $e = $cose[-2] ?? '';
        if ($n === '' || $e === '') throw new RuntimeException('Bad RSA key');
        $rsa = webauthn_asn1_seq(webauthn_asn1_int($n) . webauthn_asn1_int($e));
        $der = webauthn_asn1_seq(
            webauthn_asn1_seq(hex2bin('06092a864886f70d0101010500'))
            . webauthn_asn1_bitstring($rsa)
        );
        return [webauthn_der_to_pem($der), 'RS256'];
    }
    throw new RuntimeException('Unsupported key type/algorithm (kty=' . var_export($kty, true) . ', alg=' . var_export($alg, true) . ')', WEBAUTHN_ERR_KEY_TYPE);
}
function webauthn_asn1_len(int $len): string {
    if ($len < 0x80) return chr($len);
    $bytes = ltrim(pack('N', $len), "\x00");
    return chr(0x80 | strlen($bytes)) . $bytes;
}
function webauthn_asn1_seq(string $body): string { return "\x30" . webauthn_asn1_len(strlen($body)) . $body; }
function webauthn_asn1_int(string $bin): string {
    $bin = ltrim($bin, "\x00");
    if ($bin === '' || (ord($bin[0]) & 0x80)) $bin = "\x00" . $bin;
    return "\x02" . webauthn_asn1_len(strlen($bin)) . $bin;
}
function webauthn_asn1_bitstring(string $body): string { return "\x03" . webauthn_asn1_len(strlen($body) + 1) . "\x00" . $body; }
function webauthn_der_to_pem(string $der): string {
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/**
 * Verify a WebAuthn assertion signature.
 * The signed data is authenticatorData || SHA256(clientDataJSON).
 */
function webauthn_verify_signature(string $pem, string $alg, string $authData, string $clientDataJSON, string $signature): bool {
    $data = $authData . hash('sha256', $clientDataJSON, true);
    $key = openssl_pkey_get_public($pem);
    if ($key === false) return false;
    return openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
}

/**
 * The web origin of base_url: scheme://host[:port], without any path. A
 * browser's clientDataJSON origin never carries a path, so comparing against
 * the full base_url would reject every passkey on a subdirectory install.
 * A default port written into base_url is dropped, as browsers drop it.
 */
function webauthn_origin(): string {
    $p = parse_url((string)cfg('base_url'));
    if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) return '';
    $scheme = strtolower((string)$p['scheme']);
    $port = isset($p['port']) ? (int)$p['port'] : null;
    if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) $port = null;
    return $scheme . '://' . strtolower((string)$p['host']) . ($port !== null ? ':' . $port : '');
}

/** Shared clientDataJSON checks. Returns the decoded array or throws. */
function webauthn_check_client_data(string $clientDataJSON, string $expectedType, string $expectedChallenge): array {
    $cd = json_decode($clientDataJSON, true);
    if (!is_array($cd)) throw new RuntimeException('Bad clientDataJSON');
    if (($cd['type'] ?? '') !== $expectedType) throw new RuntimeException('Wrong ceremony type');
    if (!hash_equals($expectedChallenge, b64url_decode((string)($cd['challenge'] ?? '')))) {
        throw new RuntimeException('Challenge mismatch', WEBAUTHN_ERR_CHALLENGE);
    }
    $origin = rtrim((string)($cd['origin'] ?? ''), '/');
    $expected = webauthn_origin();
    if ($expected === '' || $origin !== $expected) throw new RuntimeException('Origin mismatch', WEBAUTHN_ERR_ORIGIN);
    return $cd;
}
