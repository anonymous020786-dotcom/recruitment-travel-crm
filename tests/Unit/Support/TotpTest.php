<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    // RFC 6238 Appendix B seed: ASCII "12345678901234567890" (SHA1).
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_matches_rfc6238_vectors(): void
    {
        $totp = new Totp(period: 30, digits: 6);

        // RFC table (8-digit) truncated to 6.
        self::assertSame('287082', $totp->at(self::RFC_SECRET, 59));            // RFC: 94287082
        self::assertSame('081804', $totp->at(self::RFC_SECRET, 1_111_111_109)); // RFC: 07081804
        self::assertSame('005924', $totp->at(self::RFC_SECRET, 1_234_567_890)); // RFC: 89005924
        self::assertSame('279037', $totp->at(self::RFC_SECRET, 2_000_000_000)); // RFC: 69279037
    }

    public function test_verify_accepts_current_and_adjacent_steps(): void
    {
        $totp = new Totp();
        $t = 1_700_000_000;
        $current = $totp->at(self::RFC_SECRET, $t);

        self::assertTrue($totp->verify(self::RFC_SECRET, $current, window: 1, timestamp: $t));
        self::assertTrue($totp->verify(self::RFC_SECRET, $totp->at(self::RFC_SECRET, $t - 30), window: 1, timestamp: $t));
        self::assertTrue($totp->verify(self::RFC_SECRET, $totp->at(self::RFC_SECRET, $t + 30), window: 1, timestamp: $t));
        self::assertFalse($totp->verify(self::RFC_SECRET, $totp->at(self::RFC_SECRET, $t - 90), window: 1, timestamp: $t));
    }

    public function test_verify_tolerates_formatting_and_rejects_wrong_length(): void
    {
        $totp = new Totp();
        $t = 1_700_000_000;
        $code = $totp->at(self::RFC_SECRET, $t);

        self::assertTrue($totp->verify(self::RFC_SECRET, substr($code, 0, 3) . ' ' . substr($code, 3), timestamp: $t));
        self::assertFalse($totp->verify(self::RFC_SECRET, '123', timestamp: $t));
        self::assertFalse($totp->verify(self::RFC_SECRET, '', timestamp: $t));
    }

    public function test_generated_secret_is_valid_base32(): void
    {
        $secret = (new Totp())->generateSecret();
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        self::assertGreaterThanOrEqual(32, strlen($secret));
    }

    public function test_provisioning_uri(): void
    {
        $uri = (new Totp())->provisioningUri('ABC234', 'jane@example.com', 'Acme CRM');
        self::assertStringStartsWith('otpauth://totp/Acme%20CRM:jane%40example.com?', $uri);
        self::assertStringContainsString('secret=ABC234', $uri);
        self::assertStringContainsString('issuer=Acme+CRM', $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
    }

    public function test_roundtrip_secret_generation_and_use(): void
    {
        $totp = new Totp();
        $secret = $totp->generateSecret();
        $t = time();
        self::assertTrue($totp->verify($secret, $totp->at($secret, $t), timestamp: $t));
    }
}
