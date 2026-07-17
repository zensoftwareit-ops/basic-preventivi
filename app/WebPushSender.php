<?php

declare(strict_types=1);

final class WebPushSender
{
    private string $publicKey;
    private string $privateKey;
    private string $subject;
    private int $ttl;
    private int $timeout;

    public function __construct(array $config)
    {
        $this->publicKey = trim((string) ($config['vapid_public_key'] ?? ''));
        $this->privateKey = trim((string) ($config['vapid_private_key'] ?? ''));
        $this->subject = trim((string) ($config['vapid_subject'] ?? ''));
        $this->ttl = max(60, min(2419200, (int) ($config['ttl'] ?? 86400)));
        $this->timeout = max(5, min(60, (int) ($config['timeout'] ?? 20)));

        if (!$this->isConfigured()) {
            throw new RuntimeException('Configurazione Web Push/VAPID incompleta.');
        }
        $publicRaw = self::base64UrlDecode($this->publicKey);
        if (strlen($publicRaw) !== 65 || $publicRaw[0] !== "\x04") {
            throw new RuntimeException('Chiave pubblica VAPID non valida.');
        }
        if (!str_starts_with($this->subject, 'mailto:') && !str_starts_with($this->subject, 'https://')) {
            throw new RuntimeException('Il soggetto VAPID deve iniziare con mailto: oppure https://.');
        }
        if (!function_exists('curl_init') || !function_exists('openssl_pkey_derive')) {
            throw new RuntimeException('Le estensioni PHP cURL e OpenSSL sono necessarie per Web Push.');
        }
    }

    public function isConfigured(): bool
    {
        return $this->publicKey !== '' && $this->privateKey !== '' && $this->subject !== '';
    }

    public function send(array $subscription, array $payload): array
    {
        $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
        $this->assertEndpoint($endpoint);
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 3500) {
            throw new RuntimeException('Payload push troppo grande.');
        }

        $body = $this->encrypt(
            $json,
            (string) ($subscription['p256dh'] ?? ''),
            (string) ($subscription['auth_token'] ?? '')
        );
        $authorization = $this->vapidAuthorization($endpoint);

        $curl = curl_init($endpoint);
        if ($curl === false) {
            throw new RuntimeException('Impossibile inizializzare la richiesta push.');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $authorization,
                'Content-Encoding: aes128gcm',
                'Content-Type: application/octet-stream',
                'TTL: ' . $this->ttl,
                'Urgency: high',
            ],
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($response === false) {
            throw new RuntimeException('Invio push non riuscito: ' . ($error ?: 'errore di rete.'));
        }

        return [
            'success' => $status >= 200 && $status < 300,
            'expired' => in_array($status, [404, 410], true),
            'status' => $status,
            'error' => $status >= 200 && $status < 300 ? null : 'Il servizio push ha risposto HTTP ' . $status . '.',
        ];
    }

    private function encrypt(string $payload, string $clientPublicEncoded, string $authEncoded): string
    {
        $clientPublic = self::base64UrlDecode($clientPublicEncoded);
        $authSecret = self::base64UrlDecode($authEncoded);
        if (strlen($clientPublic) !== 65 || $clientPublic[0] !== "\x04" || strlen($authSecret) < 16) {
            throw new RuntimeException('Chiavi della sottoscrizione push non valide.');
        }

        $serverPrivate = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($serverPrivate === false) {
            throw new RuntimeException('Impossibile generare la chiave ECDH temporanea.');
        }
        $details = openssl_pkey_get_details($serverPrivate);
        $x = $details['ec']['x'] ?? null;
        $y = $details['ec']['y'] ?? null;
        if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
            throw new RuntimeException('Chiave ECDH temporanea non valida.');
        }
        $serverPublic = "\x04" . $x . $y;
        $sharedSecret = openssl_pkey_derive(self::ecPublicKeyPem($clientPublic), $serverPrivate);
        if ($sharedSecret === false || strlen($sharedSecret) < 32) {
            throw new RuntimeException('Impossibile derivare il segreto ECDH.');
        }

        $authPrk = hash_hmac('sha256', $sharedSecret, $authSecret, true);
        $keyInfo = "WebPush: info\x00" . $clientPublic . $serverPublic;
        $ikm = hash_hmac('sha256', $keyInfo . "\x01", $authPrk, true);
        $salt = random_bytes(16);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01", $prk, true), 0, 12);

        $plaintext = $payload . "\x02";
        $recordSize = 4096;
        if (strlen($plaintext) + 16 >= $recordSize) {
            throw new RuntimeException('Payload push oltre il limite del record cifrato.');
        }
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16
        );
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('Cifratura del payload push non riuscita.');
        }

        return $salt . pack('N', $recordSize) . chr(strlen($serverPublic)) . $serverPublic . $ciphertext . $tag;
    }

    private function vapidAuthorization(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $host = (string) ($parts['host'] ?? '');
        $scheme = (string) ($parts['scheme'] ?? '');
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $audience = $scheme . '://' . $host . $port;
        $header = self::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_THROW_ON_ERROR));
        $claims = self::base64UrlEncode(json_encode([
            'aud' => $audience,
            'exp' => time() + 43200,
            'sub' => $this->subject,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $unsigned = $header . '.' . $claims;
        $key = openssl_pkey_get_private($this->privateKey);
        if ($key === false || !openssl_sign($unsigned, $derSignature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Firma VAPID non riuscita.');
        }
        $signature = self::derToJose($derSignature);
        return 'vapid t=' . $unsigned . '.' . self::base64UrlEncode($signature) . ', k=' . $this->publicKey;
    }

    private function assertEndpoint(string $endpoint): void
    {
        $parts = parse_url($endpoint);
        if ($endpoint === '' || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new RuntimeException('Endpoint push non valido: è richiesto HTTPS.');
        }
        $host = (string) $parts['host'];
        if (filter_var($host, FILTER_VALIDATE_IP)
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException('Endpoint push su rete privata non consentito.');
        }
    }

    private static function ecPublicKeyPem(string $raw): string
    {
        $algorithm = hex2bin('301306072a8648ce3d020106082a8648ce3d030107');
        $bitString = "\x03" . self::derLength(strlen($raw) + 1) . "\x00" . $raw;
        $der = "\x30" . self::derLength(strlen($algorithm . $bitString)) . $algorithm . $bitString;
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function derToJose(string $der): string
    {
        $offset = 0;
        if (($der[$offset++] ?? '') !== "\x30") {
            throw new RuntimeException('Firma ECDSA DER non valida.');
        }
        self::readDerLength($der, $offset);
        $parts = [];
        for ($index = 0; $index < 2; $index++) {
            if (($der[$offset++] ?? '') !== "\x02") {
                throw new RuntimeException('Firma ECDSA DER non valida.');
            }
            $length = self::readDerLength($der, $offset);
            $integer = substr($der, $offset, $length);
            $offset += $length;
            $integer = ltrim($integer, "\x00");
            if (strlen($integer) > 32) {
                throw new RuntimeException('Componente firma ECDSA non valida.');
            }
            $parts[] = str_pad($integer, 32, "\x00", STR_PAD_LEFT);
        }
        return $parts[0] . $parts[1];
    }

    private static function readDerLength(string $der, int &$offset): int
    {
        $length = ord($der[$offset++] ?? "\x00");
        if (($length & 0x80) === 0) {
            return $length;
        }
        $bytes = $length & 0x7f;
        if ($bytes < 1 || $bytes > 2) {
            throw new RuntimeException('Lunghezza DER non valida.');
        }
        $length = 0;
        for ($index = 0; $index < $bytes; $index++) {
            $length = ($length << 8) | ord($der[$offset++] ?? "\x00");
        }
        return $length;
    }

    private static function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        return "\x81" . chr($length);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Valore base64url non valido.');
        }
        return $decoded;
    }
}
