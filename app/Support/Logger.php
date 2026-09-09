<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Small PSR-3-flavoured file logger. One file per day under storage/logs.
 * Shared-hosting friendly: no rotation daemon, cleanup handled by cron/cleanup.php.
 */
final class Logger
{
    private const LEVELS = [
        'debug' => 100, 'info' => 200, 'notice' => 250, 'warning' => 300,
        'error' => 400, 'critical' => 500, 'alert' => 550, 'emergency' => 600,
    ];

    private int $threshold;

    /** @var array<string,scalar> merged into every log line (request id, user id, ...) */
    private array $baseContext = [];

    public function __construct(
        private readonly string $directory,
        string $minLevel = 'debug',
    ) {
        $this->threshold = self::LEVELS[$minLevel] ?? 100;
    }

    /** Add a key that is included in every subsequent log line. */
    public function withContext(string $key, string|int|float|bool|null $value): void
    {
        $this->baseContext[$key] = $value;
    }

    public function debug(string $m, array $c = []): void { $this->log('debug', $m, $c); }
    public function info(string $m, array $c = []): void { $this->log('info', $m, $c); }
    public function notice(string $m, array $c = []): void { $this->log('notice', $m, $c); }
    public function warning(string $m, array $c = []): void { $this->log('warning', $m, $c); }
    public function error(string $m, array $c = []): void { $this->log('error', $m, $c); }
    public function critical(string $m, array $c = []): void { $this->log('critical', $m, $c); }

    public function log(string $level, string $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? 0) < $this->threshold) {
            return;
        }

        if ($this->baseContext !== []) {
            $context = $context + $this->baseContext;
        }

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0770, true);
        }

        $file = $this->directory . '/app-' . gmdate('Y-m-d') . '.log';

        $line = sprintf(
            "[%s] %s: %s%s%s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            strtoupper($level),
            $this->interpolate($message, $context),
            $context !== [] ? ' ' . $this->encodeContext($context) : '',
            ''
        );

        // LOCK_EX keeps concurrent FPM workers from interleaving lines.
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            if (is_scalar($val) || $val instanceof \Stringable) {
                $replace['{' . $key . '}'] = (string) $val;
            }
        }

        return strtr($message, $replace);
    }

    private function encodeContext(array $context): string
    {
        // Scrub obvious secrets before they hit disk.
        $redactKeys = ['password', 'password_hash', 'token', 'secret', 'authorization', 'api_key', 'cookie'];
        array_walk_recursive($context, static function (&$value, $key) use ($redactKeys): void {
            if (is_string($key) && in_array(strtolower($key), $redactKeys, true)) {
                $value = '[redacted]';
            }
        });

        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $e = $context['exception'];
            $context['exception'] = sprintf(
                '%s: %s @ %s:%d',
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );
        }

        return json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }
}
