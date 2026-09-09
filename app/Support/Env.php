<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal .env loader and typed accessor.
 *
 * - Does NOT overwrite real environment variables already set by the server.
 * - Values are read once into a static map; call Env::load() during bootstrap.
 * - Supports: KEY=value, quoted values, # comments, blank lines, \n in double quotes.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        self::$loaded = true;

        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            // Strip surrounding quotes; expand escapes only inside double quotes.
            if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
                $value = substr($value, 1, -1);
                $value = str_replace(['\n', '\r', '\"', '\\\\'], ["\n", "\r", '"', '\\'], $value);
            } elseif (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
                $value = substr($value, 1, -1);
            } else {
                // Trailing inline comment on unquoted values.
                if (($hash = strpos($value, ' #')) !== false) {
                    $value = rtrim(substr($value, 0, $hash));
                }
            }

            if ($name === '') {
                continue;
            }

            self::$vars[$name] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $server = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
        if (is_string($server) && $server !== '') {
            return $server;
        }

        return self::$vars[$key] ?? $default;
    }

    public static function required(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Missing required environment variable: {$key}");
        }

        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on'  => true,
            '0', 'false', 'no', 'off', '' => false,
            default => $default,
        };
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value === null || !is_numeric($value) ? $default : (int) $value;
    }

    /** @return list<string> */
    public static function list(string $key): array
    {
        $value = self::get($key);
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($v) => $v !== ''));
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }
}
