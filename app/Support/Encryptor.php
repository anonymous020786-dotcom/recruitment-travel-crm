<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Authenticated symmetric encryption (AES-256-GCM) using the application key.
 * For data at rest that must be reversible — TOTP secrets, future API tokens.
 *
 * Output layout (base64): version(1) | nonce(12) | ciphertext | tag(16)
 */
final class Encryptor
{
    private const CIPHER = 'aes-256-gcm';
    private const VERSION = "\x01";

    private string $key;

    public function __construct(string $appKey)
    {
        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            $this->key = $decoded !== false ? $decoded : '';
        } else {
            $this->key = $appKey;
        }

        if (strlen($this->key) < 32) {
            // Derive a 32-byte key from whatever we were given (dev fallback).
            $this->key = hash('sha256', $this->key, true);
        } else {
            $this->key = substr($this->key, 0, 32);
        }
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return base64_encode(self::VERSION . $nonce . $ciphertext . $tag);
    }

    public function decrypt(string $payload): ?string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 1 + 12 + 16) {
            return null;
        }

        $version = $raw[0];
        if ($version !== self::VERSION) {
            return null;
        }

        $nonce = substr($raw, 1, 12);
        $tag = substr($raw, -16);
        $ciphertext = substr($raw, 13, -16);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $nonce, $tag);

        return $plaintext === false ? null : $plaintext;
    }
}
