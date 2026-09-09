<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The application kernel. Owns the base path, environment, and the service
 * container. Created once per request (or per cron invocation) in bootstrap/app.php.
 */
final class Application extends Container
{
    public const VERSION = '0.1.0-phase1';

    private static ?Application $instance = null;

    private string $basePath;
    private bool $booted = false;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
        self::$instance = $this;
        $this->instance(self::class, $this);
        $this->instance(Container::class, $this);
    }

    public static function getInstance(): Application
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Application has not been bootstrapped.');
        }

        return self::$instance;
    }

    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path !== '' ? '/' . ltrim($path, '/\\') : '');
    }

    public function configPath(string $path = ''): string
    {
        return $this->basePath('config') . ($path !== '' ? '/' . ltrim($path, '/\\') : '');
    }

    public function storagePath(string $path = ''): string
    {
        return $this->basePath('storage') . ($path !== '' ? '/' . ltrim($path, '/\\') : '');
    }

    public function config(): Config
    {
        return $this->get(Config::class);
    }

    public function environment(): string
    {
        return (string) $this->config()->get('app.env', 'production');
    }

    public function isProduction(): bool
    {
        return $this->environment() === 'production';
    }

    /**
     * Debug output is only ever enabled outside production, and only when
     * explicitly turned on. Production forces it off regardless of .env.
     */
    public function isDebug(): bool
    {
        return !$this->isProduction() && (bool) $this->config()->get('app.debug', false);
    }

    public function runningInConsole(): bool
    {
        return \PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg';
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $tz = (string) $this->config()->get('app.timezone', 'UTC');
        date_default_timezone_set($tz);

        // Database connections and internal timestamps are always UTC.
        // Display conversion to app.timezone happens in the view/Clock layer.

        $this->booted = true;
    }
}
