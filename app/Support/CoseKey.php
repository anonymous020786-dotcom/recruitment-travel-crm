<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A COSE_Key (RFC 8152) public key as produced by a WebAuthn authenticator,
 * converted to a PEM SubjectPublicKeyInfo so OpenSSL can verify signatures.
 *
 * Supports ES256 (EC P-256) and RS256 (RSA) — the two algorithms we advertise
 * in `pubKeyCredParams`. Both sign over SHA-256; the WebAuthn assertion
 * signature is already in the encoding OpenSSL expects (DER ECDSA / PKCS#1).
 */
final class CoseKey
{
    public const ES256 = -7;
    public const RS256 = -257;

    // COSE_Key labels.
    private const LABEL_KTY = 1;
    private const LABEL_ALG = 3;
    private const EC2_CRV = -1;
    private const EC2_X = -2;
    private const EC2_Y = -3;
    private const RSA_N = -1;
    private const RSA_E = -2;

    private const KTY_EC2 = 2;
    private const KTY_RSA = 3;
    private const CRV_P256 = 1;

    private function __construct(
        public readonly int $algorithm,
        private readonly string $pem,
    ) {
    }

    /** @param array<int|string,mixed> $cose decoded COSE_Key map */
    public static function fromArray(array $cose): self
    {
        $kty = $cose[self::LABEL_KTY] ?? null;
        $alg = $cose[self::LABEL_ALG] ?? null;

        if ($kty === self::KTY_EC2 && $alg === self::ES256) {
            if (($cose[self::EC2_CRV] ?? null) !== self::CRV_P256) {
                throw new \RuntimeException('COSE: unsupported EC curve (expected P-256)');
            }
            $x = (string) ($cose[self::EC2_X] ?? '');
            $y = (string) ($cose[self::EC2_Y] ?? '');
            if (strlen($x) !== 32 || strlen($y) !== 32) {
                throw new \RuntimeException('COSE: invalid EC point length');
            }

            return new self(self::ES256, self::ecP256Pem($x, $y));
        }

        if ($kty === self::KTY_RSA && $alg === self::RS256) {
            $n = (string) ($cose[self::RSA_N] ?? '');
            $e = (string) ($cose[self::RSA_E] ?? '');
            if ($n === '' || $e === '') {
                throw new \RuntimeException('COSE: incomplete RSA key');
            }

            return new self(self::RS256, self::rsaPem($n, $e));
        }

        throw new \RuntimeException('COSE: unsupported key type / algorithm combination');
    }

    /** Verify $signature over $message (raw bytes) with SHA-256. */
    public function verify(string $message, string $signature): bool
    {
        $key = openssl_pkey_get_public($this->pem);
        if ($key === false) {
            throw new \RuntimeException('COSE: could not load public key');
        }

        return openssl_verify($message, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    public function pem(): string
    {
        return $this->pem;
    }

    // ---- DER / PEM construction ----------------------------------

    private static function ecP256Pem(string $x, string $y): string
    {
        // Fixed SubjectPublicKeyInfo prefix for id-ecPublicKey + prime256v1,
        // followed by the uncompressed point (0x04 || X || Y).
        $prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420004');

        return self::wrapPem((string) $prefix . $x . $y);
    }

    private static function rsaPem(string $n, string $e): string
    {
        $rsaPublicKey = self::derSequence(self::derInteger($n) . self::derInteger($e));
        $bitString = "\x03" . self::derLength(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;
        $algorithmId = (string) hex2bin('300d06092a864886f70d0101010500'); // rsaEncryption, NULL

        return self::wrapPem(self::derSequence($algorithmId . $bitString));
    }

    private static function wrapPem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        // Prepend a zero byte if the high bit is set, to keep it positive.
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derSequence(string $contents): string
    {
        return "\x30" . self::derLength(strlen($contents)) . $contents;
    }
}
