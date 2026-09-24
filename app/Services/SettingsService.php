<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditService;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Repositories\SettingsRepository;
use App\Support\Application;
use App\Support\Db;

/**
 * Reads and edits the administrator-tunable settings declared in config/settings.php.
 *
 * Reading never fails: a missing row, an unreadable database or a value of the wrong shape all fall back to the field's
 * default, so a public page or the error page can always render. Writing validates every field, stores only what differs
 * from the default (an emptied field simply deletes its row), and audits exactly which keys changed.
 */
final class SettingsService
{
    /** @var array<string,mixed>|null key => stored value, loaded once per request */
    private ?array $stored = null;

    public function __construct(
        private readonly SettingsRepository $repo,
        private readonly AuditService $audit,
        private readonly Application $app,
        private readonly Db $db,
    ) {
    }

    /** @return array<string,array<string,mixed>> key => field definition */
    public function fields(): array
    {
        return (array) $this->app->config()->get('settings.fields', []);
    }

    /** @return array<string,array{label:string,help:string}> */
    public function groups(): array
    {
        return (array) $this->app->config()->get('settings.groups', []);
    }

    /** The effective value: what an administrator saved, else the field's default, else null. */
    public function get(string $key, mixed $fallback = null): mixed
    {
        $field = $this->fields()[$key] ?? null;
        if ($field === null) {
            throw new \InvalidArgumentException("Unknown setting “{$key}”.");
        }
        $value = $this->load()[$key] ?? null;
        if (!$this->fits($field, $value)) {
            $value = null;
        }
        if ($value === null || $value === '') {
            $value = $this->defaultFor($field);
        }

        return $value === null || $value === '' ? $fallback : $value;
    }

    /** What the field falls back to when empty (may be null). */
    public function defaultFor(array $field): mixed
    {
        if (array_key_exists('default', $field)) {
            return $field['default'];
        }

        return isset($field['config']) ? $this->app->config()->get((string) $field['config']) : null;
    }

    /** @return array<string,mixed> key => the value an administrator saved (null when the field uses its default) */
    public function saved(): array
    {
        $out = [];
        foreach (array_keys($this->fields()) as $key) {
            $v = $this->load()[$key] ?? null;
            $out[$key] = $this->fits($this->fields()[$key], $v) ? $v : null;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $input key => submitted value (keys not listed in config/settings.php are ignored)
     * @return list<string> the keys that changed
     * @throws ValidationException
     */
    public function update(array $input, User $actor): array
    {
        $fields = $this->fields();
        $errors = [];
        $clean = [];
        foreach ($fields as $key => $field) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            [$value, $error] = $this->normalise($field, $input[$key]);
            if ($error !== null) {
                $errors[$key] = [$error];
            } else {
                $clean[$key] = $value;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $old = $this->saved();
        $changed = [];
        $this->db->transaction(function () use ($clean, $old, $fields, $actor, &$changed): void {
            $before = [];
            $after = [];
            foreach ($clean as $key => $value) {
                if ($value === ($old[$key] ?? null)) {
                    continue;
                }
                $value === null ? $this->repo->forget($key) : $this->repo->put($key, $value, (bool) ($fields[$key]['public'] ?? false), $actor->id);
                $changed[] = $key;
                $before[$key] = $old[$key] ?? null;
                $after[$key] = $value;
            }
            if ($changed !== []) {
                $this->audit->log('settings_updated', 'settings', 'settings', 0, $before, $after, null, $actor);
            }
        });
        $this->stored = null;

        return $changed;
    }

    /** @return array<string,mixed> */
    private function load(): array
    {
        if ($this->stored === null) {
            try {
                $this->stored = $this->repo->values(array_keys($this->fields()));
            } catch (\Throwable) {
                $this->stored = [];   // a public page must still render with the defaults when the database is struggling
            }
        }

        return $this->stored;
    }

    /** @param array<string,mixed> $field */
    private function fits(array $field, mixed $value): bool
    {
        return match ($field['type']) {
            'int'   => $value === null || is_int($value),
            default => $value === null || is_string($value),
        };
    }

    /**
     * @param array<string,mixed> $field
     * @return array{0:int|string|null,1:?string} [value (null = use the default), error]
     */
    private function normalise(array $field, mixed $raw): array
    {
        $label = (string) $field['label'];
        if (!is_scalar($raw) && $raw !== null) {
            return [null, "{$label} is not valid."];
        }
        $text = trim(str_replace("\r\n", "\n", (string) $raw));
        if ($text === '') {
            return [null, null];
        }

        $max = (int) ($field['max'] ?? 255);
        switch ($field['type']) {
            case 'int':
                $min = (int) ($field['min'] ?? 0);
                if (preg_match('/^-?\d{1,9}$/D', $text) !== 1 || (int) $text < $min || (int) $text > $max) {
                    return [null, "{$label} must be a whole number from {$min} to {$max}."];
                }

                return [(int) $text, null];
            case 'email':
                if (mb_strlen($text) > $max || filter_var($text, FILTER_VALIDATE_EMAIL) === false) {
                    return [null, "{$label} must be a valid email address."];
                }

                return [strtolower($text), null];
            case 'phone':
                if (preg_match('/^[0-9+()\-\s]{7,30}$/D', $text) !== 1 || mb_strlen($text) > $max) {
                    return [null, "{$label} must be 7–{$max} digits (spaces, +, - and brackets allowed)."];
                }

                return [$text, null];
            case 'text':
                break;
            default:
                if (str_contains($text, "\n")) {
                    return [null, "{$label} must be a single line."];
                }
        }
        if (mb_strlen($text) > $max) {
            return [null, "{$label} must be at most {$max} characters."];
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text) === 1) {
            return [null, "{$label} contains characters that are not allowed."];
        }

        return [$text, null];
    }
}
