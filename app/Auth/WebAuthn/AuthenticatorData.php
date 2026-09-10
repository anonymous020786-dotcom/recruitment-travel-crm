<?php

declare(strict_types=1);

namespace App\Auth\WebAuthn;

use App\Support\Cbor;

/**
 * Parsed authenticator data (§6.1 of the WebAuthn spec).
 *
 *   rpIdHash          32 bytes
 *   flags              1 byte   (UP, UV, AT, ED)
 *   signCount          4 bytes  big-endian
 *   [attestedCredentialData]  present when AT is set
 *       aaguid        16 bytes
 *       credIdLen      2 bytes
 *       credId        credIdLen bytes
 *       credPublicKey COSE_Key (CBOR)
 *   [extensions]       present when ED is set (ignored)
 */
final class AuthenticatorData
{
    public const FLAG_UP = 0x01; // user present
    public const FLAG_UV = 0x04; // user verified
    public const FLAG_AT = 0x40; // attested credential data included
    public const FLAG_ED = 0x80; // extension data included

    private function __construct(
        public readonly string $rpIdHash,
        public readonly int $flags,
        public readonly int $signCount,
        public readonly ?string $aaguid,
        public readonly ?string $credentialId,
        /** @var array<int|string,mixed>|null decoded COSE_Key map */
        public readonly ?array $credentialPublicKey,
        public readonly ?string $credentialPublicKeyBytes,
        public readonly string $raw,
    ) {
    }

    public static function parse(string $bytes): self
    {
        if (strlen($bytes) < 37) {
            throw new WebAuthnException('Authenticator data is too short.');
        }

        $rpIdHash = substr($bytes, 0, 32);
        $flags = ord($bytes[32]);
        /** @var array{1:int} $unpacked */
        $unpacked = unpack('N', substr($bytes, 33, 4));
        $signCount = $unpacked[1];

        $aaguid = $credentialId = $coseBytes = null;
        $cose = null;

        if (($flags & self::FLAG_AT) !== 0) {
            if (strlen($bytes) < 55) {
                throw new WebAuthnException('Attested credential data is truncated.');
            }
            $aaguid = substr($bytes, 37, 16);
            /** @var array{1:int} $lenUnpacked */
            $lenUnpacked = unpack('n', substr($bytes, 53, 2));
            $credIdLen = $lenUnpacked[1];

            $credIdStart = 55;
            $coseStart = $credIdStart + $credIdLen;
            if (strlen($bytes) < $coseStart) {
                throw new WebAuthnException('Credential id is truncated.');
            }
            $credentialId = substr($bytes, $credIdStart, $credIdLen);

            [$decoded, $consumed] = Cbor::decodeFirst(substr($bytes, $coseStart));
            if (!is_array($decoded)) {
                throw new WebAuthnException('Credential public key is not a COSE map.');
            }
            $cose = $decoded;
            $coseBytes = substr($bytes, $coseStart, $consumed);
        }

        return new self($rpIdHash, $flags, $signCount, $aaguid, $credentialId, $cose, $coseBytes, $bytes);
    }

    public function userPresent(): bool
    {
        return ($this->flags & self::FLAG_UP) !== 0;
    }

    public function userVerified(): bool
    {
        return ($this->flags & self::FLAG_UV) !== 0;
    }

    public function hasAttestedCredential(): bool
    {
        return ($this->flags & self::FLAG_AT) !== 0
            && $this->credentialId !== null
            && $this->credentialPublicKey !== null;
    }
}
