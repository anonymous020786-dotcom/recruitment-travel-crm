<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Password hashing. Wraps PHP's password_* with configured parameters. Argon2id
 * when the build supports it, bcrypt otherwise. Never rolls its own crypto.
 */
final class Hash
{
    private int|string $algo;
    private array $options;

    /** @param array<string,mixed> $config config('security.hash') */
    public function __construct(array $config = [])
    {
        $this->algo = $config['algo'] ?? (defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);

        $this->options = match ($this->algo) {
            PASSWORD_BCRYPT => ['cost' => (int) ($config['bcrypt']['cost'] ?? 12)],
            default => [
                'memory_cost' => (int) ($config['argon2id']['memory_cost'] ?? 65536),
                'time_cost'   => (int) ($config['argon2id']['time_cost'] ?? 4),
                'threads'     => (int) ($config['argon2id']['threads'] ?? 1),
            ],
        };
    }

    public function make(string $plain): string
    {
        $hash = password_hash($plain, $this->algo, $this->options);
        if (!is_string($hash)) {
            throw new \RuntimeException('Password hashing failed.');
        }

        return $hash;
    }

    public function verify(string $plain, string $hash): bool
    {
        if ($hash === '') {
            // Constant-ish work factor so a missing hash does not become a timing oracle.
            password_verify($plain, '$2y$12$usesomesillystringforsalt.tooshort');

            return false;
        }

        return password_verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algo, $this->options);
    }

    public function info(string $hash): array
    {
        return password_get_info($hash);
    }
}
