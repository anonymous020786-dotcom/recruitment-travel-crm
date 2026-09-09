<?php

declare(strict_types=1);

namespace App\Session;

/**
 * Server-side session data + behaviour. Independent of PHP's native session
 * extension so it is fully unit-testable; the StartSession middleware owns the
 * cookie ↔ store ↔ Session bridging.
 *
 * Reserved keys use a leading underscore: _token, _flash, _auth_user_id,
 * _auth_meta, _last_activity, _started_at, _last_regen, _previous_url.
 */
final class Session
{
    /** @param array<string,mixed> $data */
    public function __construct(
        private string $id,
        private array $data = [],
        private bool $justRegenerated = false,
        private ?string $previousId = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data) && $this->data[$key] !== null;
    }

    public function forget(string|array $keys): void
    {
        foreach ((array) $keys as $key) {
            unset($this->data[$key]);
        }
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    public function flush(): void
    {
        $this->data = [];
    }

    // ---- Flash (survives exactly one subsequent request) ----------------

    public function flash(string $key, mixed $value): void
    {
        $this->put($key, $value);
        $new = $this->get('_flash.new', []);
        $new[] = $key;
        $this->put('_flash.new', array_values(array_unique($new)));
        $this->removeFromOldFlash([$key]);
    }

    public function now(string $key, mixed $value): void
    {
        $this->put($key, $value);
        $old = $this->get('_flash.old', []);
        $old[] = $key;
        $this->put('_flash.old', array_values(array_unique($old)));
    }

    public function reflash(): void
    {
        $this->put('_flash.new', array_values(array_unique(array_merge(
            $this->get('_flash.new', []),
            $this->get('_flash.old', []),
        ))));
        $this->put('_flash.old', []);
    }

    /** @param string|list<string> $keys */
    public function keep(string|array $keys): void
    {
        $keys = (array) $keys;
        $this->put('_flash.new', array_values(array_unique(array_merge($this->get('_flash.new', []), $keys))));
        $this->removeFromOldFlash($keys);
    }

    /** Called by StartSession at the beginning of each request. */
    public function ageFlashData(): void
    {
        foreach ($this->get('_flash.old', []) as $key) {
            $this->forget($key);
        }
        $this->put('_flash.old', $this->get('_flash.new', []));
        $this->put('_flash.new', []);
    }

    // ---- CSRF token -------------------------------------------------

    public function token(): string
    {
        if (!$this->has('_token')) {
            $this->regenerateToken();
        }

        return (string) $this->get('_token');
    }

    public function regenerateToken(): void
    {
        $this->put('_token', bin2hex(random_bytes(32)));
    }

    // ---- Lifecycle -------------------------------------------------

    public function regenerate(bool $destroyOld = true): void
    {
        $this->previousId = $destroyOld ? $this->id : null;
        $this->id = self::newId();
        $this->justRegenerated = true;
        $this->put('_last_regen', time());
    }

    public function invalidate(): void
    {
        $this->previousId = $this->id;
        $this->flush();
        $this->id = self::newId();
        $this->justRegenerated = true;
        $this->regenerateToken();
        $this->put('_last_regen', time());
    }

    public function migrateFrom(): ?string
    {
        $old = $this->previousId;
        $this->previousId = null;

        return $old;
    }

    public function wasRegenerated(): bool
    {
        return $this->justRegenerated;
    }

    public function setPreviousUrl(string $url): void
    {
        $this->put('_previous_url', $url);
    }

    public function previousUrl(): ?string
    {
        $url = $this->get('_previous_url');

        return is_string($url) ? $url : null;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(32)); // 64 hex chars
    }

    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', $id);
    }

    /** @param list<string> $keys */
    private function removeFromOldFlash(array $keys): void
    {
        $this->put('_flash.old', array_values(array_diff($this->get('_flash.old', []), $keys)));
    }
}
