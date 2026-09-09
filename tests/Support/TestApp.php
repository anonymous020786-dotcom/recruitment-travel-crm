<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Application;
use App\Support\Config;
use App\Support\Logger;

/**
 * Builds a minimal Application for middleware / service unit tests: real config
 * files (defaults, no .env needed) with per-test overrides, logger pointed at a
 * temp dir.
 */
final class TestApp
{
    public static function make(array $overrides = [], string $env = 'testing'): Application
    {
        $app = new Application(TEST_ROOT);

        $config = new Config(TEST_ROOT . '/config');
        $config->set('app.env', $env);
        $config->set('app.debug', $env !== 'production');
        foreach ($overrides as $key => $value) {
            $config->set($key, $value);
        }

        $app->instance(Config::class, $config);
        $app->instance(Logger::class, new Logger(sys_get_temp_dir() . '/crm_test_logs'));
        $app->boot();

        return $app;
    }
}
