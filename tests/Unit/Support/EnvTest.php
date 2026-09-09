<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/crm_env_' . bin2hex(random_bytes(4)) . '.env';
        file_put_contents($this->file, <<<ENV
        # comment
        APP_NAME="CRM Test"
        APP_DEBUG=true
        EMPTY=
        QUOTED_HASH="a#b"
        UNQUOTED=value # trailing comment
        LIST=a, b ,c
        NUM=42
        ENV);
        Env::load($this->file);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function test_reads_quoted_and_unquoted_values(): void
    {
        self::assertSame('CRM Test', Env::get('APP_NAME'));
        self::assertSame('a#b', Env::get('QUOTED_HASH'));
        self::assertSame('value', Env::get('UNQUOTED'));
    }

    public function test_bool_casting(): void
    {
        self::assertTrue(Env::bool('APP_DEBUG'));
        self::assertFalse(Env::bool('MISSING', false));
        self::assertTrue(Env::bool('MISSING', true));
    }

    public function test_int_and_list(): void
    {
        self::assertSame(42, Env::int('NUM'));
        self::assertSame(['a', 'b', 'c'], Env::list('LIST'));
        self::assertSame([], Env::list('EMPTY'));
    }

    public function test_server_env_wins_over_file(): void
    {
        $_SERVER['APP_NAME'] = 'From Server';
        self::assertSame('From Server', Env::get('APP_NAME'));
        unset($_SERVER['APP_NAME']);
    }

    public function test_required_throws_when_missing(): void
    {
        $this->expectException(\RuntimeException::class);
        Env::required('DEFINITELY_NOT_SET_' . uniqid());
    }
}
