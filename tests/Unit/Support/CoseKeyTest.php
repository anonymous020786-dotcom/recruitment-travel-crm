<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\CoseKey;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeAuthenticator;

final class CoseKeyTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('openssl_pkey_new')) {
            self::markTestSkipped('openssl extension not available');
        }
    }

    public function test_es256_round_trip(): void
    {
        $key = FakeAuthenticator::newKey(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $details = openssl_pkey_get_details($key);

        $cose = [
            1 => 2, 3 => CoseKey::ES256, -1 => 1,
            -2 => str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT),
            -3 => str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT),
        ];

        $message = random_bytes(80);
        openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256);

        $coseKey = CoseKey::fromArray($cose);
        self::assertSame(CoseKey::ES256, $coseKey->algorithm);
        self::assertTrue($coseKey->verify($message, $signature));
        self::assertFalse($coseKey->verify($message . "\x00", $signature));
    }

    public function test_rs256_round_trip(): void
    {
        $key = FakeAuthenticator::newKey(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $details = openssl_pkey_get_details($key);

        $cose = [1 => 3, 3 => CoseKey::RS256, -1 => $details['rsa']['n'], -2 => $details['rsa']['e']];

        $message = random_bytes(64);
        openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256);

        self::assertTrue(CoseKey::fromArray($cose)->verify($message, $signature));
    }

    public function test_rejects_unknown_algorithm(): void
    {
        $this->expectException(\RuntimeException::class);
        CoseKey::fromArray([1 => 2, 3 => -99, -1 => 1, -2 => str_repeat('x', 32), -3 => str_repeat('y', 32)]);
    }

    public function test_rejects_bad_ec_point_length(): void
    {
        $this->expectException(\RuntimeException::class);
        CoseKey::fromArray([1 => 2, 3 => CoseKey::ES256, -1 => 1, -2 => 'short', -3 => 'short']);
    }
}
