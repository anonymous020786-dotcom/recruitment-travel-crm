<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Application;
use App\Support\Config;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    private function app(string $env, bool $debug): Application
    {
        $app = new Application(sys_get_temp_dir());
        $app->instance(Config::class, $this->config(['app' => ['env' => $env, 'debug' => $debug, 'timezone' => 'UTC']]));

        return $app;
    }

    private function config(array $items): Config
    {
        $dir = sys_get_temp_dir() . '/crm_app_' . bin2hex(random_bytes(4));
        mkdir($dir);
        foreach ($items as $name => $value) {
            file_put_contents($dir . "/{$name}.php", '<?php return ' . var_export($value, true) . ';');
        }
        $cfg = new Config($dir);
        array_map('unlink', glob($dir . '/*.php') ?: []);
        @rmdir($dir);

        return $cfg;
    }

    public function test_debug_is_forced_off_in_production(): void
    {
        self::assertFalse($this->app('production', true)->isDebug());
        self::assertTrue($this->app('production', true)->isProduction());
    }

    public function test_debug_on_outside_production_when_enabled(): void
    {
        self::assertTrue($this->app('local', true)->isDebug());
        self::assertFalse($this->app('local', false)->isDebug());
    }

    public function test_paths(): void
    {
        $app = $this->app('local', false);
        self::assertStringEndsWith('/config/app.php', str_replace('\\', '/', $app->configPath('app.php')));
        self::assertStringEndsWith('/storage/logs', str_replace('\\', '/', $app->storagePath('logs')));
    }
}
