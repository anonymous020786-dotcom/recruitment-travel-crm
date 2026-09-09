<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crm_cfg_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/app.php', '<?php return ["name" => "X", "nested" => ["a" => 1]];');
        file_put_contents($this->dir . '/db.php', '<?php return ["host" => "localhost"];');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*.php') ?: []);
        @rmdir($this->dir);
    }

    public function test_dot_access_and_defaults(): void
    {
        $c = new Config($this->dir);
        self::assertSame('X', $c->get('app.name'));
        self::assertSame(1, $c->get('app.nested.a'));
        self::assertSame('localhost', $c->get('db.host'));
        self::assertNull($c->get('app.missing'));
        self::assertSame('fallback', $c->get('app.missing', 'fallback'));
    }

    public function test_set_and_has(): void
    {
        $c = new Config($this->dir);
        self::assertFalse($c->has('app.nested.b'));
        $c->set('app.nested.b', 2);
        self::assertTrue($c->has('app.nested.b'));
        self::assertSame(2, $c->get('app.nested.b'));
    }
}
