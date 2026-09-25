<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Audit\AuditService;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Support\Application;
use App\Support\Db;
use App\Support\Encryptor;
use App\Support\Env;

/**
 * The credentials store behind Admin → Integrations.
 *
 * Reading (`get`): a value saved in the panel wins, then the environment variable named in the registry, else null. Secret
 * fields are AES-256-GCM encrypted at rest with the application key; a secret that cannot be decrypted (the key changed) reads
 * as missing and is flagged so the screen can ask for it again — it never throws and never returns the ciphertext.
 * Writing (`save`, `clear`): validated per field type, all-or-nothing, audited with the names of the fields that changed —
 * never their values. A blank secret field means "keep the current one".
 * The screen only ever gets a masked hint; there is deliberately no way to read a secret back out through the panel.
 *
 * `applyToConfig()` copies saved values into the config paths the registry names, so existing code that reads config('…')
 * (Turnstile, mail, analytics…) sees what the super admin saved. A service switched off in the panel blanks those paths.
 */
final class Credentials
{
    public const ENABLED = '_enabled';

    /** @var array<string,array<string,array{value:string,secret:bool,at:string}>>|null */
    private ?array $stored = null;
    /** @var array<string,true> secrets found but not decryptable */
    private array $unreadable = [];

    public function __construct(
        private readonly Db $db,
        private readonly Encryptor $encryptor,
        private readonly AuditService $audit,
        private readonly Application $app,
    ) {
    }

    // ---- the catalogue ----------------------------------------------------------------------------------------

    /** @return array<string,string> group key => label */
    public function groups(): array
    {
        return (array) $this->app->config()->get('integration_registry.groups', []);
    }

    /** @return array<string,array<string,mixed>> */
    public function services(): array
    {
        return (array) $this->app->config()->get('integration_registry.services', []);
    }

    /** @return array<string,mixed>|null */
    public function service(string $key): ?array
    {
        return $this->services()[$key] ?? null;
    }

    // ---- reading ------------------------------------------------------------------------------------------------

    /** The effective value of one field (saved → environment → null). Empty strings count as unset. */
    public function get(string $service, string $field): ?string
    {
        if (!$this->isEnabled($service)) {
            return null;
        }
        $saved = $this->load()[$service][$field]['value'] ?? null;
        if ($saved !== null && $saved !== '') {
            return $saved;
        }
        $env = (string) ($this->service($service)['fields'][$field]['env'] ?? '');
        $fromEnv = $env !== '' ? trim((string) Env::get($env, '')) : '';

        return $fromEnv !== '' ? $fromEnv : null;
    }

    /** 'saved' | 'env' | 'none' — where the effective value comes from. */
    public function source(string $service, string $field): string
    {
        $saved = $this->load()[$service][$field]['value'] ?? '';
        if ($saved !== '') {
            return 'saved';
        }
        $env = (string) ($this->service($service)['fields'][$field]['env'] ?? '');

        return $env !== '' && trim((string) Env::get($env, '')) !== '' ? 'env' : 'none';
    }

    /** A service is on unless the super admin switched it off. */
    public function isEnabled(string $service): bool
    {
        return ($this->load()[$service][self::ENABLED]['value'] ?? '1') !== '0';
    }

    /** Whether every required field has a value (saved or from the environment). */
    public function isConfigured(string $service): bool
    {
        $def = $this->service($service);
        if ($def === null) {
            return false;
        }
        foreach ($def['fields'] as $name => $field) {
            if (!empty($field['required']) && $this->source($service, $name) === 'none') {
                return false;
            }
        }

        return true;
    }

    /** @return array{state:string,label:string} configured | off | incomplete | empty */
    public function status(string $service): array
    {
        $any = false;
        foreach (array_keys($this->service($service)['fields'] ?? []) as $name) {
            $any = $any || $this->source($service, $name) !== 'none';
        }

        return match (true) {
            !$this->isEnabled($service) => ['state' => 'off', 'label' => 'Switched off'],
            $this->isConfigured($service) => ['state' => 'configured', 'label' => 'Configured'],
            $any => ['state' => 'incomplete', 'label' => 'Incomplete'],
            default => ['state' => 'empty', 'label' => 'Not set up'],
        };
    }

    /**
     * What the screen may show: per field, whether it has a value, where it comes from, and — for secrets — only a masked hint.
     *
     * @return array<string,array{set:bool,source:string,hint:string,value:string,unreadable:bool,updated_at:?string}>
     */
    public function view(string $service): array
    {
        $out = [];
        foreach (($this->service($service)['fields'] ?? []) as $name => $field) {
            $saved = $this->load()[$service][$name] ?? null;
            $secret = ($field['type'] ?? 'text') === 'secret';
            $value = (string) ($saved['value'] ?? '');
            $out[$name] = [
                'set' => $value !== '' || $this->source($service, $name) === 'env',
                'source' => $this->source($service, $name),
                'hint' => $secret ? self::mask($value) : '',
                'value' => $secret ? '' : $value,   // a secret's value is never handed to a view
                'unreadable' => isset($this->unreadable[$service . '.' . $name]),
                'updated_at' => $saved['at'] ?? null,
            ];
        }

        return $out;
    }

    /** "••••1234" for a long secret, "••••••••" for a short one (a short secret would be mostly revealed by its tail). */
    public static function mask(string $secret): string
    {
        if ($secret === '') {
            return '';
        }

        return strlen($secret) >= 12 ? '••••' . substr($secret, -4) : '••••••••';
    }

    // ---- writing -------------------------------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $input field => submitted value; `_enabled` => '1'|'0'; `clear` => list of field names to remove
     * @return list<string> the fields (and '_enabled') that changed
     * @throws ValidationException
     */
    public function save(string $service, array $input, User $actor): array
    {
        $def = $this->service($service) ?? throw new \InvalidArgumentException("Unknown integration “{$service}”.");
        $clear = array_flip(array_map('strval', (array) ($input['clear'] ?? [])));
        $errors = [];
        $writes = [];
        $deletes = [];

        foreach ($def['fields'] as $name => $field) {
            $secret = ($field['type'] ?? 'text') === 'secret';
            if (isset($clear[$name])) {
                $deletes[] = $name;
                continue;
            }
            if (!array_key_exists($name, $input)) {
                continue;
            }
            $raw = trim((string) $input[$name]);
            if ($raw === '') {
                if (!$secret) {
                    $deletes[] = $name;   // an emptied plain field returns to the environment default
                }
                continue;                 // an empty secret field means "keep what is saved"
            }
            $error = $this->invalid($field, $raw);
            if ($error !== null) {
                $errors[$name] = [$field['label'] . ' ' . $error];
                continue;
            }
            $writes[$name] = $raw;
        }
        if (array_key_exists(self::ENABLED, $input)) {
            $writes[self::ENABLED] = (string) $input[self::ENABLED] === '0' ? '0' : '1';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $stored = $this->load()[$service] ?? [];
        $changed = [];
        $this->db->transaction(function () use ($service, $def, $writes, $deletes, $stored, $actor, &$changed): void {
            foreach ($writes as $name => $value) {
                if (($stored[$name]['value'] ?? null) === $value) {
                    continue;
                }
                $secret = ($def['fields'][$name]['type'] ?? 'text') === 'secret';
                $this->db->affectingStatement(
                    'INSERT INTO integration_credentials (service, field, value, is_secret, updated_by) VALUES (:s, :f, :v, :x, :u)
                     ON DUPLICATE KEY UPDATE value = VALUES(value), is_secret = VALUES(is_secret), updated_by = VALUES(updated_by)',
                    ['s' => $service, 'f' => $name, 'v' => $secret ? $this->encryptor->encrypt($value) : $value, 'x' => $secret ? 1 : 0, 'u' => $actor->id],
                );
                $changed[] = $name;
            }
            foreach ($deletes as $name) {
                if ($this->db->affectingStatement('DELETE FROM integration_credentials WHERE service = :s AND field = :f', ['s' => $service, 'f' => $name]) > 0) {
                    $changed[] = $name;
                }
            }
            if ($changed !== []) {
                // names only — a credential's value never reaches the audit trail
                $this->audit->log('integration_updated', 'integrations', 'integration', 0, null, ['service' => $service, 'fields' => $changed], null, $actor);
            }
        });
        $this->stored = null;

        return $changed;
    }

    /** Remove everything saved for a service (its environment defaults, if any, apply again). */
    public function reset(string $service, User $actor): int
    {
        $this->service($service) ?? throw new \InvalidArgumentException("Unknown integration “{$service}”.");
        $n = $this->db->affectingStatement('DELETE FROM integration_credentials WHERE service = :s', ['s' => $service]);
        if ($n > 0) {
            $this->audit->log('integration_cleared', 'integrations', 'integration', 0, null, ['service' => $service, 'fields' => $n], null, $actor);
        }
        $this->stored = null;

        return $n;
    }

    // ---- config overlay ---------------------------------------------------------------------------------------------

    /**
     * Copy saved values into the config paths the registry names (see class comment). Safe to call when the table does
     * not exist yet (a fresh install before migrations): it does nothing.
     */
    public function applyToConfig(): void
    {
        try {
            $stored = $this->load();
        } catch (\Throwable) {
            return;
        }
        $config = $this->app->config();
        foreach ($this->services() as $key => $def) {
            $off = ($stored[$key][self::ENABLED]['value'] ?? '1') === '0';
            foreach ($def['fields'] as $name => $field) {
                $path = (string) ($field['config'] ?? '');
                if ($path === '') {
                    continue;
                }
                if ($off) {
                    $config->set($path, '');
                    continue;
                }
                $value = $stored[$key][$name]['value'] ?? '';
                if ($value !== '') {
                    $config->set($path, ($field['type'] ?? '') === 'number' ? (int) $value : $value);
                }
            }
            if ($off && $key === 'smtp') {
                $config->set('mail.driver', 'log');
            } elseif (!$off && $key === 'smtp' && ($stored[$key]['host']['value'] ?? '') !== '') {
                $config->set('mail.driver', 'smtp');
            }
        }
    }

    // ---- internals ------------------------------------------------------------------------------------------------

    /** @return array<string,array<string,array{value:string,secret:bool,at:string}>> */
    private function load(): array
    {
        if ($this->stored !== null) {
            return $this->stored;
        }
        $this->stored = [];
        $this->unreadable = [];
        foreach ($this->db->select('SELECT service, field, value, is_secret, updated_at FROM integration_credentials') as $row) {
            $value = (string) $row['value'];
            if ((bool) $row['is_secret']) {
                $plain = $this->encryptor->decrypt($value);
                if ($plain === null) {
                    $this->unreadable[$row['service'] . '.' . $row['field']] = true;
                    continue;
                }
                $value = $plain;
            }
            $this->stored[(string) $row['service']][(string) $row['field']] = ['value' => $value, 'secret' => (bool) $row['is_secret'], 'at' => (string) $row['updated_at']];
        }

        return $this->stored;
    }

    /** @param array<string,mixed> $field @return string|null what is wrong with the value, or null when it is fine */
    private function invalid(array $field, string $value): ?string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return 'contains characters that are not allowed.';
        }
        switch ($field['type'] ?? 'text') {
            case 'secret':
                return strlen($value) > 2000 ? 'is too long.' : null;
            case 'select':
                return isset($field['options'][$value]) ? null : 'must be one of the listed options.';
            case 'number':
                return preg_match('/^\d{1,9}$/D', $value) === 1 ? null : 'must be a whole number.';
            case 'url':
                return preg_match('#^https://[^\s<>"\']{3,500}$#D', $value) === 1 ? null : 'must be a https:// address.';
            default:
                return strlen($value) > 255 ? 'is too long (255 characters at most).' : null;
        }
    }
}
