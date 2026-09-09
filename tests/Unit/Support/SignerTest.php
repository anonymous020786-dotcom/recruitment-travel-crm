<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Signer;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase
{
    private Signer $signer;

    protected function setUp(): void
    {
        $this->signer = new Signer('base64:' . base64_encode(random_bytes(32)));
    }

    public function test_sign_unsign_roundtrip(): void
    {
        $signed = $this->signer->sign('hello world');
        self::assertSame('hello world', $this->signer->unsign($signed));
    }

    public function test_tampered_value_is_rejected(): void
    {
        $signed = $this->signer->sign('amount=100');
        $tampered = str_replace('amount=100', 'amount=999', $signed);
        self::assertNull($this->signer->unsign($tampered));
    }

    public function test_wrong_key_rejects(): void
    {
        $signed = $this->signer->sign('x');
        $other = new Signer('base64:' . base64_encode(random_bytes(32)));
        self::assertNull($other->unsign($signed));
    }

    public function test_timed_token_valid_then_expired(): void
    {
        self::assertTrue($this->signer->verifyTimedToken($this->signer->timedToken(3600)));
        self::assertFalse($this->signer->verifyTimedToken($this->signer->timedToken(-1)));
        self::assertFalse($this->signer->verifyTimedToken('garbage'));
    }

    public function test_missing_key_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        new Signer('');
    }
}
