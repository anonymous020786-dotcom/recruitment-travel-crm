<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Logger;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crm_log_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*.log') ?: []);
        @rmdir($this->dir);
    }

    private function contents(): string
    {
        return implode('', array_map('file_get_contents', glob($this->dir . '/*.log') ?: []));
    }

    public function test_writes_line_and_interpolates(): void
    {
        (new Logger($this->dir))->info('hello {who}', ['who' => 'world']);
        self::assertStringContainsString('INFO: hello world', $this->contents());
    }

    public function test_redacts_secret_keys(): void
    {
        (new Logger($this->dir))->error('login', ['password' => 'hunter2', 'token' => 'abc', 'user' => 'jane']);
        $out = $this->contents();
        self::assertStringNotContainsString('hunter2', $out);
        self::assertStringNotContainsString('abc', $out);
        self::assertStringContainsString('[redacted]', $out);
        self::assertStringContainsString('jane', $out);
    }

    public function test_threshold_filters_below_min_level(): void
    {
        (new Logger($this->dir, 'warning'))->info('should not appear');
        self::assertSame([], glob($this->dir . '/*.log') ?: []);
    }

    public function test_formats_throwable_context(): void
    {
        (new Logger($this->dir))->critical('boom', ['exception' => new \RuntimeException('kaboom')]);
        self::assertStringContainsString('RuntimeException: kaboom', $this->contents());
    }
}
