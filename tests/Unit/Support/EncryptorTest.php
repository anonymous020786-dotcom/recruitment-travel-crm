<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Encryptor;
use PHPUnit\Framework\TestCase;

final class EncryptorTest extends TestCase
{
    private function enc(): Encryptor
    {
        return new Encryptor('base64:' . base64_encode(random_bytes(32)));
    }

    public function test_roundtrip(): void
    {
        $e = $this->enc();
        $secret = 'JBSWY3DPEHPK3PXP';
        self::assertSame($secret, $e->decrypt($e->encrypt($secret)));
    }

    public function test_ciphertext_is_not_plaintext_and_varies(): void
    {
        $e = $this->enc();
        $a = $e->encrypt('same');
        $b = $e->encrypt('same');
        self::assertNotSame('same', $a);
        self::assertNotSame($a, $b, 'random nonce per call');
    }

    public function test_tamper_is_detected(): void
    {
        $e = $this->enc();
        $payload = $e->encrypt('secret');
        $raw = base64_decode($payload);
        $raw[20] = $raw[20] === 'A' ? 'B' : 'A';
        self::assertNull($e->decrypt(base64_encode($raw)));
    }

    public function test_wrong_key_cannot_decrypt(): void
    {
        $payload = $this->enc()->encrypt('secret');
        self::assertNull($this->enc()->decrypt($payload));
    }

    public function test_garbage_returns_null(): void
    {
        self::assertNull($this->enc()->decrypt('not-base64!!'));
        self::assertNull($this->enc()->decrypt(base64_encode('short')));
    }
}
