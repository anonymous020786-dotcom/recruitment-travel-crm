<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Loads config/*.php into a single tree and exposes dot-notation access.
 *
 * Each file returns an array; its basename becomes the top-level key
 * (config/app.php -> config('app.debug')).
 */
final class Config
{
    /** @var array<string,mixed> */
    private array $items = [];

    public function __construct(string $configDir)
    {
        foreach (glob(rtrim($configDir, '/\\') . '/*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            /** @psalm-suppress UnresolvableInclude */
            $this->items[$key] = require $file;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return $default;
            }
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $ref = &$this->items;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $ref[$segment] = $value;
                return;
            }
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->items;
    }
}
