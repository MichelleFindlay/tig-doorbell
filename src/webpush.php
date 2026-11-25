<?php
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

/**
 * Minimal, dependency-free Web Push sender (RFC 8030 + VAPID RFC 8292).
 *
 * Sends payload-less pushes, so no message encryption (RFC 8291) is needed:
 * the service worker wakes up and fetches the details over HTTPS.
 * Requires the openssl and curl extensions.
 */
final class WebPush
{
    /** Returns ['private_pem' => ..., 'public' => base64url(uncompressed P-256 point)], generating once. */
    public static function keys(): array
    {
        $pem = setting('vapid_private_pem');
        $pub = setting('vapid_public');
        if ($pem && $pub) {
            return ['private_pem' => $pem, 'public' => $pub];
        }

        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$key) {
            throw new RuntimeException('Could not generate VAPID key: ' . openssl_error_string());
        }
        openssl_pkey_export($key, $pem);
        $d = openssl_pkey_get_details($key);
        $raw = "\x04"
            . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)
            . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $pub = self::b64url($raw);

        setting('vapid_private_pem', $pem);
        setting('vapid_public', $pub);
        return ['private_pem' => $pem, 'public' => $pub];
    }

    public static function publicKey(): string
    {
        return self::keys()['public'];
    }

    /**
     * @param array<int|string, string> $endpoints id => endpoint URL
     * @return array<int|string, array{status:int, body:string, error:string}>
     */
    public static function sendMany(array $endpoints, int $ttl = 900): array
    {
        $public = self::publicKey();
        $jwts = [];
        $handles = [];
        $mh = curl_multi_init();

        foreach ($endpoints as $id => $endpoint) {
            $p = parse_url($endpoint);
            $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
            $jwts[$aud] ??= self::jwt($aud);

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => '',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => [
                    'TTL: ' . $ttl,
                    'Urgency: high',
                    'Content-Length: 0',
                    'Authorization: vapid t=' . $jwts[$aud] . ', k=' . $public,
                ],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$id] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $id => $ch) {
            $results[$id] = [
                'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'body'   => (string) curl_multi_getcontent($ch),
                'error'  => curl_error($ch),
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $results;
    }

    private static function jwt(string $audience): string
    {
        $header = self::b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64url(json_encode([
            'aud' => $audience,
            'exp' => time() + 12 * 3600,
            'sub' => config('vapid_subject'),
        ], JSON_UNESCAPED_SLASHES));
        $input = $header . '.' . $claims;

        $key = openssl_pkey_get_private(self::keys()['private_pem']);
        if (!$key || !openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('VAPID signing failed: ' . openssl_error_string());
        }
        return $input . '.' . self::b64url(self::derToRaw($der));
    }

    /** Converts an ASN.1 DER ECDSA signature to the raw 64-byte r||s form JWS needs. */
    private static function derToRaw(string $der): string
    {
        if (ord($der[0]) !== 0x30) {
            throw new RuntimeException('Invalid DER signature');
        }
        $pos = 2;
        if (ord($der[1]) & 0x80) {
            $pos += ord($der[1]) & 0x7f;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$pos]) !== 0x02) {
                throw new RuntimeException('Invalid DER integer');
            }
            $len = ord($der[$pos + 1]);
            $int = ltrim(substr($der, $pos + 2, $len), "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }
        return $out;
    }

    public static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
