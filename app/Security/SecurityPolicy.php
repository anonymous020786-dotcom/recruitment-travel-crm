<?php

declare(strict_types=1);

namespace App\Security;

use App\Audit\AuditService;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Support\Application;
use App\Support\Db;

/**
 * The security settings the super admin can change from Admin → Security, on top of config/.env:
 *  - rate limits per bucket (limit + window), with guard rails: bounds for every bucket, and "at most double the default" for
 *    the buckets that protect passwords and codes;
 *  - which roles must use two-factor authentication and how many sign-ins they get to set it up;
 *  - the automatic IP block after repeated failed sign-ins.
 *
 * A missing row means "use the default". `applyToConfig()` (called once at boot) copies the saved values into the config the
 * rest of the application already reads, so RateLimit, Enforce2fa and AuthService need no knowledge of this class.
 * Saving is all-or-nothing and audited (old → new, never anything secret — there is nothing secret here).
 */
final class SecurityPolicy
{
    /** @var array<string,string>|null */
    private ?array $stored = null;

    public function __construct(
        private readonly Db $db,
        private readonly AuditService $audit,
        private readonly Application $app,
    ) {
    }

    // ---- boot ---------------------------------------------------------------------------------------------------

    /** Safe before the table exists (a fresh install before migrations): does nothing then. */
    public function applyToConfig(): void
    {
        try {
            $stored = $this->load();
        } catch (\Throwable) {
            return;
        }
        $config = $this->app->config();
        foreach ($stored as $name => $value) {
            if (str_starts_with($name, 'rate.')) {
                $bucket = substr($name, 5);
                $v = json_decode($value, true);
                if (is_array($v) && $config->get("rate_limits.buckets.{$bucket}") !== null && isset($v['limit'], $v['window'])) {
                    $config->set("rate_limits.buckets.{$bucket}.limit", (int) $v['limit']);
                    $config->set("rate_limits.buckets.{$bucket}.window_seconds", (int) $v['window']);
                }
            }
        }
        if (isset($stored['2fa.roles'])) {
            $config->set('auth.two_factor.required_roles', $this->splitRoles($stored['2fa.roles']));
        }
        if (isset($stored['2fa.grace'])) {
            $config->set('auth.two_factor.grace_logins', (int) $stored['2fa.grace']);
        }
        $config->set('security.autoblock.threshold', (int) ($stored['autoblock.threshold'] ?? $this->autoDefault('threshold')));
        $config->set('security.autoblock.minutes', (int) ($stored['autoblock.minutes'] ?? $this->autoDefault('minutes')));
        $config->set('security.ip_rules_active', (int) ($stored['ip.rules'] ?? 0) > 0);
    }

    // ---- rate limits --------------------------------------------------------------------------------------------

    /**
     * @return list<array{bucket:string,label:string,help:string,by:string,strict:bool,limit:int,window:int,default_limit:int,default_window:int,custom:bool,max_limit:int}>
     */
    public function rateLimits(): array
    {
        $defaults = $this->defaultBuckets();
        $labels = (array) $this->app->config()->get('security_console.buckets', []);
        $rows = [];
        foreach ($defaults as $bucket => $d) {
            $saved = $this->savedRate($bucket);
            $strict = (bool) ($labels[$bucket]['strict'] ?? false);
            $rows[] = [
                'bucket' => $bucket,
                'label' => (string) ($labels[$bucket]['label'] ?? $bucket),
                'help' => (string) ($labels[$bucket]['help'] ?? ''),
                'by' => implode(' + ', (array) ($d['by'] ?? [])),
                'strict' => $strict,
                'limit' => $saved['limit'] ?? (int) $d['limit'],
                'window' => $saved['window'] ?? (int) $d['window_seconds'],
                'default_limit' => (int) $d['limit'],
                'default_window' => (int) $d['window_seconds'],
                'custom' => $saved !== null,
                'max_limit' => $this->maxLimit((int) $d['limit'], $strict),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $input bucket => ['limit' => n, 'window' => s]; buckets not present are left alone
     * @return list<string> the buckets that actually changed
     * @throws ValidationException
     */
    public function saveRateLimits(array $input, ?User $actor): array
    {
        $defaults = $this->defaultBuckets();
        $bounds = (array) $this->app->config()->get('security_console.bounds', []);
        $labels = (array) $this->app->config()->get('security_console.buckets', []);
        $errors = [];
        $plan = [];
        foreach ($input as $bucket => $v) {
            if (!is_string($bucket) || !isset($defaults[$bucket]) || !is_array($v)) {
                continue;
            }
            $label = (string) ($labels[$bucket]['label'] ?? $bucket);
            $limit = $this->whole($v['limit'] ?? '');
            $window = $this->whole($v['window'] ?? '');
            $d = $defaults[$bucket];
            $strict = (bool) ($labels[$bucket]['strict'] ?? false);
            $max = $this->maxLimit((int) $d['limit'], $strict);
            if ($limit === null || $limit < (int) $bounds['limit']['min'] || $limit > (int) $bounds['limit']['max']) {
                $errors["{$bucket}.limit"] = ["{$label}: the number of requests must be a whole number from {$bounds['limit']['min']} to {$bounds['limit']['max']}."];
                continue;
            }
            if ($window === null || $window < (int) $bounds['window']['min'] || $window > (int) $bounds['window']['max']) {
                $errors["{$bucket}.window"] = ["{$label}: the time window must be from {$bounds['window']['min']} to {$bounds['window']['max']} seconds."];
                continue;
            }
            if ($strict && $limit > $max) {
                $errors["{$bucket}.limit"] = ["{$label} protects passwords and codes: it can be raised to {$max} at most (double the default of {$d['limit']})."];
                continue;
            }
            if ($strict && $window < intdiv((int) $d['window_seconds'], (int) $this->app->config()->get('security_console.strict_factor', 2))) {
                $errors["{$bucket}.window"] = ["{$label} protects passwords and codes: the window cannot be shortened below " . intdiv((int) $d['window_seconds'], 2) . ' seconds.'];
                continue;
            }
            $plan[$bucket] = ['limit' => $limit, 'window' => $window];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $changed = [];
        $old = [];
        $new = [];
        foreach ($plan as $bucket => $want) {
            $d = $defaults[$bucket];
            $current = $this->savedRate($bucket) ?? ['limit' => (int) $d['limit'], 'window' => (int) $d['window_seconds']];
            if ($current === $want) {
                continue;
            }
            $old[$bucket] = "{$current['limit']} / {$current['window']}s";
            $new[$bucket] = "{$want['limit']} / {$want['window']}s";
            $changed[] = $bucket;
            if ($want === ['limit' => (int) $d['limit'], 'window' => (int) $d['window_seconds']]) {
                $this->delete("rate.{$bucket}");
            } else {
                $this->put("rate.{$bucket}", (string) json_encode($want), $actor);
            }
        }
        $this->stored = null;
        if ($changed !== []) {
            $this->audit->log('rate_limits_changed', 'security', 'security_settings', 0, $old, $new, null, $actor);
        }

        return $changed;
    }

    /** Back to the default for one bucket, or (null) all of them. @return int buckets reset */
    public function resetRateLimits(?string $bucket, ?User $actor): int
    {
        $n = 0;
        foreach (array_keys($this->defaultBuckets()) as $b) {
            if (($bucket === null || $bucket === $b) && $this->delete("rate.{$b}") > 0) {
                $n++;
            }
        }
        $this->stored = null;
        if ($n > 0) {
            $this->audit->log('rate_limits_reset', 'security', 'security_settings', 0, null, ['buckets' => $bucket ?? 'all', 'count' => $n], null, $actor);
        }

        return $n;
    }

    // ---- two-factor policy --------------------------------------------------------------------------------------

    /** @return array{roles:list<string>,grace:int,source:string} */
    public function twoFactor(): array
    {
        $stored = $this->load();

        return [
            'roles' => $this->splitRoles($stored['2fa.roles'] ?? implode(',', (array) $this->app->config()->get('auth.two_factor.required_roles', []))),
            'grace' => (int) ($stored['2fa.grace'] ?? $this->app->config()->get('auth.two_factor.grace_logins', 3)),
            'source' => isset($stored['2fa.roles']) || isset($stored['2fa.grace']) ? 'panel' : 'env',
        ];
    }

    /**
     * @param list<string> $roles role names that must use two-factor
     * @throws ValidationException
     */
    public function saveTwoFactor(array $roles, mixed $grace, ?User $actor): void
    {
        $known = array_column($this->db->select('SELECT name FROM roles'), 'name');
        $roles = array_values(array_unique(array_filter(array_map('strval', $roles), static fn (string $r): bool => $r !== '')));
        $errors = [];
        foreach ($roles as $r) {
            if (!in_array($r, $known, true)) {
                $errors['roles'] = ['One of the chosen roles does not exist.'];
            }
        }
        $g = $this->whole($grace);
        if ($g === null || $g > 30) {
            $errors['grace'] = ['The grace period must be a whole number of sign-ins from 0 to 30.'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $before = $this->twoFactor();
        sort($roles);
        $this->put('2fa.roles', implode(',', $roles), $actor);
        $this->put('2fa.grace', (string) $g, $actor);
        $this->stored = null;
        $this->audit->log('two_factor_policy_changed', 'security', 'security_settings', 0, ['roles' => $before['roles'], 'grace' => $before['grace']], ['roles' => $roles, 'grace' => $g], null, $actor);
    }

    /** Roles that must use two-factor and how many of their active users have not enrolled yet. @return array<string,int> */
    public function twoFactorGaps(): array
    {
        $out = [];
        foreach ($this->twoFactor()['roles'] as $role) {
            $out[$role] = (int) $this->db->selectValue(
                'SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = :n AND u.is_active = 1 AND u.two_factor_enabled = 0',
                ['n' => $role],
                0,
            );
        }

        return $out;
    }

    // ---- automatic IP block -------------------------------------------------------------------------------------

    /** @return array{threshold:int,minutes:int} */
    public function autoBlock(): array
    {
        $stored = $this->load();

        return [
            'threshold' => (int) ($stored['autoblock.threshold'] ?? $this->autoDefault('threshold')),
            'minutes' => (int) ($stored['autoblock.minutes'] ?? $this->autoDefault('minutes')),
        ];
    }

    /** @throws ValidationException */
    public function saveAutoBlock(mixed $threshold, mixed $minutes, ?User $actor): void
    {
        $cfg = (array) $this->app->config()->get('security_console.autoblock', []);
        $t = $this->whole($threshold);
        $m = $this->whole($minutes);
        $errors = [];
        if ($t === null || ($t !== 0 && ($t < (int) $cfg['threshold']['min'] || $t > (int) $cfg['threshold']['max']))) {
            $errors['threshold'] = ["Use 0 to switch it off, or a number of failed sign-ins from {$cfg['threshold']['min']} to {$cfg['threshold']['max']}."];
        }
        if ($m === null || $m < (int) $cfg['minutes']['min'] || $m > (int) $cfg['minutes']['max']) {
            $errors['minutes'] = ["The block lasts {$cfg['minutes']['min']} to {$cfg['minutes']['max']} minutes."];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $before = $this->autoBlock();
        $this->put('autoblock.threshold', (string) $t, $actor);
        $this->put('autoblock.minutes', (string) $m, $actor);
        $this->stored = null;
        $this->audit->log('autoblock_changed', 'security', 'security_settings', 0, $before, ['threshold' => $t, 'minutes' => $m], null, $actor);
    }

    // ---- shared with the IP rules service -----------------------------------------------------------------------

    /** Keep the cheap "are there any IP rules?" flag right. */
    public function setIpRuleCount(int $count): void
    {
        $this->put('ip.rules', (string) max(0, $count), null);
        $this->stored = null;
        $this->app->config()->set('security.ip_rules_active', $count > 0);
    }

    // ---- internals ----------------------------------------------------------------------------------------------

    /** @return array<string,array<string,mixed>> the defaults, read from the file so a panel override never hides them */
    private function defaultBuckets(): array
    {
        static $file = null;
        $file ??= (array) (require $this->app->configPath('rate_limits.php'));

        return (array) ($file['buckets'] ?? []);
    }

    /** @return array{limit:int,window:int}|null */
    private function savedRate(string $bucket): ?array
    {
        $v = json_decode($this->load()["rate.{$bucket}"] ?? '', true);

        return is_array($v) && isset($v['limit'], $v['window']) ? ['limit' => (int) $v['limit'], 'window' => (int) $v['window']] : null;
    }

    private function maxLimit(int $default, bool $strict): int
    {
        return $strict ? $default * (int) $this->app->config()->get('security_console.strict_factor', 2) : (int) $this->app->config()->get('security_console.bounds.limit.max', 100000);
    }

    private function autoDefault(string $key): int
    {
        return (int) $this->app->config()->get("security_console.autoblock.{$key}.default", 0);
    }

    /** @return list<string> */
    private function splitRoles(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv)), static fn (string $r): bool => $r !== ''));
    }

    private function whole(mixed $v): ?int
    {
        $s = is_int($v) ? (string) $v : (is_string($v) ? trim($v) : '');

        return preg_match('/^\d{1,9}$/D', $s) === 1 ? (int) $s : null;
    }

    /** @return array<string,string> */
    private function load(): array
    {
        if ($this->stored === null) {
            $this->stored = [];
            foreach ($this->db->select('SELECT name, value FROM security_settings') as $row) {
                $this->stored[(string) $row['name']] = (string) $row['value'];
            }
        }

        return $this->stored;
    }

    private function put(string $name, string $value, ?User $actor): void
    {
        $this->db->affectingStatement(
            'INSERT INTO security_settings (name, value, updated_by) VALUES (:n, :v, :u) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)',
            ['n' => $name, 'v' => $value, 'u' => $actor?->id],
        );
    }

    private function delete(string $name): int
    {
        return $this->db->affectingStatement('DELETE FROM security_settings WHERE name = :n', ['n' => $name]);
    }
}
