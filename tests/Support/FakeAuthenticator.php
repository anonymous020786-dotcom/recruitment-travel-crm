<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Auth\WebAuthn\WebAuthnService;

/**
 * A minimal in-process WebAuthn authenticator for tests: holds one EC P-256
 * key pair, emits attestation ("none") and assertion responses shaped exactly
 * like the browser's PublicKeyCredential JSON.
 */
final class FakeAuthenticator
{
    public string $credentialId;
    private \OpenSSLAsymmetricKey $key;
    private string $x;
    private string $y;
    private int $signCount = 0;

    public function __construct(private string $rpId = 'localhost', private string $origin = 'http://localhost')
    {
        $this->credentialId = random_bytes(24);
        $this->key = self::newKey(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $details = openssl_pkey_get_details($this->key);
        $this->x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $this->y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    }

    /**
     * Create a key pair, working around Windows/XAMPP builds that ship PHP
     * without a resolvable openssl.cnf. Skips the test when key generation is
     * genuinely unavailable.
     *
     * @param array<string,mixed> $args
     */
    public static function newKey(array $args): \OpenSSLAsymmetricKey
    {
        $configs = array_filter([
            getenv('OPENSSL_CONF') ?: null,
            'C:/xampp/apache/conf/openssl.cnf',
            'C:/xampp/php/extras/openssl/openssl.cnf',
            '/usr/lib/ssl/openssl.cnf',
            '/etc/ssl/openssl.cnf',
        ], static fn (?string $p): bool => $p !== null && is_file($p));

        foreach ([null, ...array_values($configs)] as $config) {
            $key = openssl_pkey_new($config === null ? $args : $args + ['config' => $config]);
            if ($key !== false) {
                return $key;
            }
        }

        \PHPUnit\Framework\TestCase::markTestSkipped('openssl key generation unavailable on this host');
    }

    /** @return array<string,mixed> PublicKeyCredential-shaped attestation response */
    public function register(string $challengeB64Url, bool $userVerified = true): array
    {
        $clientData = $this->clientData('webauthn.create', $challengeB64Url);
        $flags = 0x01 | 0x40 | ($userVerified ? 0x04 : 0x00); // UP | AT | UV?
        $authData = $this->rpIdHash()
            . chr($flags)
            . pack('N', 0)
            . str_repeat("\x00", 16)                       // aaguid
            . pack('n', strlen($this->credentialId))
            . $this->credentialId
            . $this->coseKey();

        $attestationObject = $this->cborMap([
            [$this->tstr('fmt'), $this->tstr('none')],
            [$this->tstr('attStmt'), $this->cborMap([])],
            [$this->tstr('authData'), $this->bstr($authData)],
        ]);

        return [
            'id'    => WebAuthnService::b64urlEncode($this->credentialId),
            'rawId' => WebAuthnService::b64urlEncode($this->credentialId),
            'type'  => 'public-key',
            'response' => [
                'clientDataJSON'    => WebAuthnService::b64urlEncode($clientData),
                'attestationObject' => WebAuthnService::b64urlEncode($attestationObject),
                'transports'        => ['internal', 'hybrid'],
            ],
        ];
    }

    /** @return array<string,mixed> PublicKeyCredential-shaped assertion response */
    public function assert(string $challengeB64Url, bool $userVerified = true, ?int $signCount = null): array
    {
        $clientData = $this->clientData('webauthn.get', $challengeB64Url);
        $count = $signCount ?? ++$this->signCount;
        $flags = 0x01 | ($userVerified ? 0x04 : 0x00);
        $authenticatorData = $this->rpIdHash() . chr($flags) . pack('N', $count);

        $signed = $authenticatorData . hash('sha256', $clientData, true);
        openssl_sign($signed, $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id'    => WebAuthnService::b64urlEncode($this->credentialId),
            'rawId' => WebAuthnService::b64urlEncode($this->credentialId),
            'type'  => 'public-key',
            'response' => [
                'clientDataJSON'    => WebAuthnService::b64urlEncode($clientData),
                'authenticatorData' => WebAuthnService::b64urlEncode($authenticatorData),
                'signature'         => WebAuthnService::b64urlEncode((string) $signature),
                'userHandle'        => null,
            ],
        ];
    }

    private function clientData(string $type, string $challenge): string
    {
        return (string) json_encode(
            ['type' => $type, 'challenge' => $challenge, 'origin' => $this->origin, 'crossOrigin' => false],
            JSON_UNESCAPED_SLASHES,
        );
    }

    private function rpIdHash(): string
    {
        return hash('sha256', $this->rpId, true);
    }

    private function coseKey(): string
    {
        return $this->cborMap([
            [$this->uint(1), $this->uint(2)],
            [$this->uint(3), $this->nint(-7)],
            [$this->nint(-1), $this->uint(1)],
            [$this->nint(-2), $this->bstr($this->x)],
            [$this->nint(-3), $this->bstr($this->y)],
        ]);
    }

    // ---- tiny CBOR encoder (definite length) --------------------

    private function head(int $major, int $value): string
    {
        $prefix = $major << 5;
        if ($value < 24) {
            return chr($prefix | $value);
        }
        if ($value < 256) {
            return chr($prefix | 24) . chr($value);
        }
        if ($value < 65536) {
            return chr($prefix | 25) . pack('n', $value);
        }

        return chr($prefix | 26) . pack('N', $value);
    }

    private function uint(int $n): string
    {
        return $this->head(0, $n);
    }

    private function nint(int $n): string
    {
        return $this->head(1, -1 - $n);
    }

    private function bstr(string $s): string
    {
        return $this->head(2, strlen($s)) . $s;
    }

    private function tstr(string $s): string
    {
        return $this->head(3, strlen($s)) . $s;
    }

    /** @param list<array{0:string,1:string}> $pairs */
    private function cborMap(array $pairs): string
    {
        $out = $this->head(5, count($pairs));
        foreach ($pairs as [$k, $v]) {
            $out .= $k . $v;
        }

        return $out;
    }
}
