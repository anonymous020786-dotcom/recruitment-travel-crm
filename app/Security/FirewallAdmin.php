<?php

declare(strict_types=1);

namespace App\Security;

use App\Audit\AuditService;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Security\Waf\Signatures;
use App\Support\Db;

/**
 * Everything the super admin changes in Admin → Security → Firewall: the switches and managed-rule modes, escalation, country
 * rules, lockdown, custom rules, and the reports on the event log. Guard rails: you cannot lock yourself out (lockdown needs an
 * allow rule for your own address; a country rule may not block the country you are in), custom regular expressions must
 * compile, and every change is audited (module `security`).
 */
final class FirewallAdmin
{
    public const MAX_RULES = 200;
    public const PARTS = ['path' => 'Path', 'query' => 'Query string', 'body' => 'Form / body', 'user_agent' => 'User agent', 'referer' => 'Referer', 'method' => 'Method', 'ip' => 'IP address', 'country' => 'Country', 'header' => 'A header'];
    public const OPERATORS = ['contains' => 'contains', 'equals' => 'equals', 'starts_with' => 'starts with', 'ends_with' => 'ends with', 'regex' => 'matches regex', 'in_cidr' => 'is in IP range(s)', 'in_list' => 'is one of'];
    public const ACTIONS = ['block' => 'Block', 'log' => 'Log only', 'allow' => 'Allow (skip the firewall)'];
    public const SPECIAL_COUNTRIES = ['T1' => 'Tor network', 'XX' => 'Unknown'];

    public function __construct(
        private readonly Db $db,
        private readonly SecurityPolicy $policy,
        private readonly Firewall $firewall,
        private readonly IpRules $ipRules,
        private readonly AuditService $audit,
    ) {
    }

    // ---- switches, modes and escalation -------------------------------------------------------------------------

    /** @return array<string,mixed> */
    public function settings(): array
    {
        $modes = [];
        foreach (array_keys(Signatures::SETS) as $set) {
            $modes[$set] = $this->firewall->mode($set);
        }
        $out = ['modes' => $modes];
        foreach (array_keys(Firewall::DEFAULTS) as $k) {
            $out[$k] = $this->firewall->setting($k);
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $input enabled, monitor, modes[set], escalate_threshold, escalate_minutes, probe_ban
     * @throws ValidationException
     */
    public function saveSettings(array $input, User $actor): void
    {
        $errors = [];
        $modes = is_array($input['modes'] ?? null) ? $input['modes'] : [];
        foreach (array_keys(Signatures::SETS) as $set) {
            if (isset($modes[$set]) && !in_array($modes[$set], ['off', 'log', 'block'], true)) {
                $errors['modes'] = ['Each rule set is off, log only or block.'];
            }
        }
        $num = static fn (mixed $v, int $min, int $max): ?int => is_scalar($v) && preg_match('/^\d{1,6}$/D', (string) $v) === 1 && (int) $v >= $min && (int) $v <= $max ? (int) $v : null;
        $threshold = $num($input['escalate_threshold'] ?? '', 0, 1000);
        $minutes = $num($input['escalate_minutes'] ?? '', 1, 10080);
        $probe = $num($input['probe_ban'] ?? '', 0, 10080);
        if ($threshold === null || ($threshold > 0 && $threshold < 3)) {
            $errors['escalate_threshold'] = ['Use 0 (off) or 3 to 1000 blocked requests.'];
        }
        if ($minutes === null) {
            $errors['escalate_minutes'] = ['The ban lasts 1 minute to 7 days (10080 minutes).'];
        }
        if ($probe === null) {
            $errors['probe_ban'] = ['Use 0 (off) up to 10080 minutes.'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $before = $this->settings();
        $this->put('enabled', !empty($input['enabled']) ? '1' : '0', $actor);
        $this->put('monitor', !empty($input['monitor']) ? '1' : '0', $actor);
        foreach (array_keys(Signatures::SETS) as $set) {
            if (isset($modes[$set])) {
                $this->put('mode.' . $set, (string) $modes[$set] === Signatures::SETS[$set]['default'] ? null : (string) $modes[$set], $actor);
            }
        }
        $this->put('escalate_threshold', (string) $threshold, $actor);
        $this->put('escalate_minutes', (string) $minutes, $actor);
        $this->put('probe_ban', (string) $probe, $actor);
        $after = $this->settings();
        $this->audit->log('firewall_settings_changed', 'security', 'firewall', 0, $this->diff($before, $after, true), $this->diff($before, $after, false), null, $actor);
    }

    // ---- countries and lockdown ---------------------------------------------------------------------------------

    /** @return list<array{code:string,name:string}> */
    public function countries(): array
    {
        $rows = [];
        foreach (\App\Security\Waf\IsoCountries::ALL as $code => $name) {
            $rows[] = ['code' => $code, 'name' => $name];
        }
        usort($rows, static fn (array $x, array $y): int => strcmp($x['name'], $y['name']));
        foreach (self::SPECIAL_COUNTRIES as $code => $name) {
            $rows[] = ['code' => $code, 'name' => $name];
        }

        return $rows;
    }

    /**
     * @param list<string> $codes
     * @throws ValidationException
     */
    public function saveGeo(string $scope, string $mode, array $codes, ?string $yourCountry, User $actor): void
    {
        $errors = [];
        if (!in_array($scope, ['off', 'staff', 'site'], true)) {
            $errors['geo_scope'] = ['Choose where the country rule applies.'];
        }
        if (!in_array($mode, ['block', 'allow'], true)) {
            $errors['geo_mode'] = ['Choose block these, or allow only these.'];
        }
        $valid = array_column($this->countries(), 'code');
        $codes = array_values(array_unique(array_map(static fn ($c): string => strtoupper(trim((string) $c)), $codes)));
        foreach ($codes as $c) {
            if (!in_array($c, $valid, true)) {
                $errors['geo_countries'] = ['One of the countries is not in the list.'];
            }
        }
        if (count($codes) > 250) {
            $errors['geo_countries'] = ['Too many countries.'];
        }
        if ($scope !== 'off' && $mode === 'allow' && $codes === []) {
            $errors['geo_countries'] = ['"Allow only" needs at least one country.'];
        }
        if ($errors === [] && $scope !== 'off' && $yourCountry !== null) {
            $listed = in_array($yourCountry, $codes, true);
            if (($mode === 'block' && $listed) || ($mode === 'allow' && !$listed)) {
                $errors['geo_countries'] = ['That would block the country you are connecting from (' . $yourCountry . ').'];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        sort($codes);
        $before = ['scope' => $this->firewall->setting('geo_scope'), 'mode' => $this->firewall->setting('geo_mode'), 'countries' => $this->firewall->setting('geo_countries')];
        $this->put('geo_scope', $scope, $actor);
        $this->put('geo_mode', $mode, $actor);
        $this->put('geo_countries', implode(',', $codes), $actor);
        $this->audit->log('firewall_geo_changed', 'security', 'firewall', 0, $before, ['scope' => $scope, 'mode' => $mode, 'countries' => implode(',', $codes)], null, $actor);
    }

    /** @throws ValidationException */
    public function setLockdown(bool $on, string $yourIp, User $actor): void
    {
        if ($on && $this->ipRules->verdict($yourIp) !== 'allow') {
            throw new ValidationException(['lockdown' => ['Add an allow rule for your own address (' . $yourIp . ') under IP rules first — otherwise lockdown would shut you out.']]);
        }
        if ($on && (int) $this->db->selectValue("SELECT COUNT(*) FROM ip_rules WHERE effect = 'allow' AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())", [], 0) === 0) {
            throw new ValidationException(['lockdown' => ['There are no allow rules.']]);
        }
        $this->put('lockdown', $on ? '1' : '0', $actor);
        $this->audit->log($on ? 'firewall_lockdown_on' : 'firewall_lockdown_off', 'security', 'firewall', 0, null, ['by_ip' => $yourIp], null, $actor);
    }

    // ---- custom rules -------------------------------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function rules(): array
    {
        return $this->db->select('SELECT r.*, u.name AS created_by_name, (r.expires_at IS NOT NULL AND r.expires_at <= UTC_TIMESTAMP()) AS expired
            FROM firewall_rules r LEFT JOIN users u ON u.id = r.created_by ORDER BY r.priority, r.id');
    }

    /** @return array<string,mixed>|null */
    public function rule(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM firewall_rules WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array<string,mixed> $input
     * @throws ValidationException|DomainRuleException
     */
    public function saveRule(array $input, User $actor, ?int $id = null): int
    {
        $existing = $id === null ? null : ($this->rule($id) ?? throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'That rule no longer exists.', [], 404));
        $data = $this->validRule($input);
        if ($existing === null) {
            if ((int) $this->db->selectValue('SELECT COUNT(*) FROM firewall_rules', [], 0) >= self::MAX_RULES) {
                throw new ValidationException(['name' => ['There are already ' . self::MAX_RULES . ' rules.']]);
            }
            $id = (int) $this->db->insertRow('firewall_rules', $data + ['created_by' => $actor->id]);
        } else {
            $sets = [];
            $bind = ['id' => $id];
            foreach ($data as $k => $v) {
                $sets[] = \App\Support\Sql::assign($k, 'c_');
                $bind['c_' . $k] = $v;
            }
            $this->db->affectingStatement('UPDATE firewall_rules SET ' . implode(', ', $sets) . ' WHERE id = :id', $bind);
        }
        $this->syncCount($actor);
        $this->audit->log($existing === null ? 'firewall_rule_added' : 'firewall_rule_updated', 'security', 'firewall_rule', (int) $id,
            $existing === null ? null : array_intersect_key($existing, $data), $data, null, $actor);

        return (int) $id;
    }

    public function toggleRule(int $id, User $actor): bool
    {
        $r = $this->rule($id);
        if ($r === null) {
            return false;
        }
        $this->db->affectingStatement('UPDATE firewall_rules SET is_active = 1 - is_active WHERE id = :id', ['id' => $id]);
        $this->syncCount($actor);
        $this->audit->log('firewall_rule_toggled', 'security', 'firewall_rule', $id, ['active' => (int) $r['is_active']], ['active' => 1 - (int) $r['is_active']], null, $actor);

        return true;
    }

    public function deleteRule(int $id, User $actor): bool
    {
        $r = $this->rule($id);
        if ($r === null) {
            return false;
        }
        $this->db->affectingStatement('DELETE FROM firewall_rules WHERE id = :id', ['id' => $id]);
        $this->syncCount($actor);
        $this->audit->log('firewall_rule_removed', 'security', 'firewall_rule', $id, ['name' => $r['name'], 'value' => $r['value']], null, null, $actor);

        return true;
    }

    // ---- the tester ---------------------------------------------------------------------------------------------

    /**
     * Run the firewall on a made-up request. Nothing is logged, counted or banned.
     *
     * @param array<string,mixed> $input method, url, body, user_agent, ip, country
     * @return array{verdict:string,matches:list<array<string,mixed>>}
     */
    public function test(array $input): array
    {
        $url = trim((string) ($input['url'] ?? '/'));
        $url = $url === '' ? '/' : ($url[0] === '/' ? $url : '/' . $url);
        $path = '/' . trim(rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?: '/')), '/');
        $country = strtoupper(trim((string) ($input['country'] ?? '')));
        $ip = trim((string) ($input['ip'] ?? ''));
        $ua = mb_substr((string) ($input['user_agent'] ?? ''), 0, 1000);
        $view = [
            'method' => strtoupper(mb_substr(trim((string) ($input['method'] ?? 'GET')), 0, 10)) ?: 'GET',
            'path' => $path, 'uri' => mb_substr($url, 0, 5000), 'query' => (string) parse_url($url, PHP_URL_QUERY),
            'body' => mb_substr((string) ($input['body'] ?? ''), 0, Signatures::MAX_INSPECT), 'user_agent' => $ua, 'referer' => '', 'cookie' => '',
            'headers' => $ua === '' ? [] : ['User-Agent' => $ua],
            'ip' => filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '203.0.113.1',
            'country' => preg_match('/^[A-Z][A-Z0-9]$/D', $country) === 1 ? $country : null, 'staff' => false,
        ];
        $this->firewall->forgetRules();

        return $this->firewall->inspect($view);
    }

    // ---- reports ------------------------------------------------------------------------------------------------

    /** @return array{block:int,log:int,ban:int,addresses:int} */
    public function counts(int $hours): array
    {
        $r = $this->db->selectOne('SELECT COALESCE(SUM(action = \'block\'), 0) AS b, COALESCE(SUM(action = \'log\'), 0) AS l, COALESCE(SUM(action = \'ban\'), 0) AS n, COUNT(DISTINCT ip_address) AS a
            FROM firewall_events WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL :h HOUR)', ['h' => $hours]) ?? [];

        return ['block' => (int) ($r['b'] ?? 0), 'log' => (int) ($r['l'] ?? 0), 'ban' => (int) ($r['n'] ?? 0), 'addresses' => (int) ($r['a'] ?? 0)];
    }

    /** @return list<array{rule_key:string,rule_label:string,n:int}> */
    public function topRules(int $hours, int $limit = 10): array
    {
        return $this->db->select('SELECT rule_key, MAX(rule_label) AS rule_label, COUNT(*) AS n FROM firewall_events WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL :h HOUR)
            GROUP BY rule_key ORDER BY n DESC LIMIT ' . max(1, min(50, $limit)), ['h' => $hours]);
    }

    /** @return list<array{ip_address:string,n:int,last_at:string}> */
    public function topAddresses(int $hours, int $limit = 10): array
    {
        return $this->db->select('SELECT ip_address, COUNT(*) AS n, MAX(created_at) AS last_at FROM firewall_events WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL :h HOUR)
            GROUP BY ip_address ORDER BY n DESC LIMIT ' . max(1, min(50, $limit)), ['h' => $hours]);
    }

    /** Events per hour for the last 24 hours, oldest first. @return list<array{hour:string,n:int}> */
    public function hourly(): array
    {
        $rows = array_column($this->db->select("SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00') AS h, COUNT(*) AS n FROM firewall_events
            WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR) GROUP BY h"), 'n', 'h');
        $out = [];
        for ($i = 23; $i >= 0; $i--) {
            $h = gmdate('Y-m-d H:00', time() - $i * 3600);
            $out[] = ['hour' => $h, 'n' => (int) ($rows[$h] ?? 0)];
        }

        return $out;
    }

    /**
     * @param array{action?:string,rule?:string,ip?:string} $filters
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function events(array $filters, int $page, int $perPage = 50): array
    {
        $where = ['1 = 1'];
        $bind = [];
        if (in_array($filters['action'] ?? '', ['block', 'log', 'ban'], true)) {
            $where[] = 'action = :a';
            $bind['a'] = $filters['action'];
        }
        if (($filters['rule'] ?? '') !== '' && preg_match('/^[a-z_]+(?::\d+)?$/D', (string) $filters['rule']) === 1) {
            $where[] = 'rule_key = :r';
            $bind['r'] = $filters['rule'];
        }
        if (($filters['ip'] ?? '') !== '' && ($packed = @inet_pton((string) $filters['ip'])) !== false) {
            $where[] = 'ip_address = :ip';
            $bind['ip'] = $packed;
        }
        $condition = implode(' AND ', $where);
        $limit = max(1, min($perPage, 100));
        $offset = (max(1, $page) - 1) * $limit;

        return [
            'total' => (int) $this->db->selectValue("SELECT COUNT(*) FROM firewall_events WHERE {$condition}", $bind),
            'rows' => $this->db->select("SELECT * FROM firewall_events WHERE {$condition} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}", $bind),
        ];
    }

    public function pruneEvents(int $days): int
    {
        return $this->db->affectingStatement('DELETE FROM firewall_events WHERE created_at < (UTC_TIMESTAMP() - INTERVAL :d DAY)', ['d' => $days]);
    }

    // ---- internals --------------------------------------------------------------------------------------------

    /** @param array<string,mixed> $input @return array<string,mixed> @throws ValidationException */
    private function validRule(array $input): array
    {
        $errors = [];
        $str = static fn (string $k): string => trim((string) ($input[$k] ?? ''));
        $name = $str('name');
        if ($name === '' || mb_strlen($name) > 80 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            $errors['name'] = ['Give the rule a name of up to 80 characters.'];
        }
        $part = $str('part');
        $operator = $str('operator');
        $action = $str('action');
        if (!isset(self::PARTS[$part])) {
            $errors['part'] = ['Choose what to look at.'];
        }
        if (!isset(self::OPERATORS[$operator])) {
            $errors['operator'] = ['Choose how to compare.'];
        }
        if (!isset(self::ACTIONS[$action])) {
            $errors['action'] = ['Choose what to do.'];
        }
        $header = $str('header_name');
        if ($part === 'header' && preg_match('/^[A-Za-z0-9-]{1,60}$/D', $header) !== 1) {
            $errors['header_name'] = ['Enter the header name (letters, numbers and hyphens).'];
        }
        $value = $str('value');
        if ($value === '' || mb_strlen($value) > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            $errors['value'] = ['Enter the value to compare with (500 characters at most).'];
        } elseif ($operator === 'regex' && (mb_strlen($value) > 300 || @preg_match('~' . str_replace('~', '\~', $value) . '~iu', '') === false)) {
            $errors['value'] = ['That regular expression is not valid (or longer than 300 characters).'];
        } elseif ($operator === 'in_cidr') {
            $list = array_filter(array_map('trim', explode(',', $value)));
            if ($list === [] || count($list) > 50) {
                $errors['value'] = ['List 1 to 50 addresses or ranges, separated by commas.'];
            }
            foreach ($list as $c) {
                if (IpRules::parse($c) === null) {
                    $errors['value'] = ['“' . mb_substr($c, 0, 50) . '” is not an address or range.'];
                    break;
                }
            }
        } elseif ($operator === 'in_list' && count(explode(',', $value)) > 100) {
            $errors['value'] = ['At most 100 values.'];
        }
        if ($operator === 'in_cidr' && $part !== 'ip') {
            $errors['operator'] = ['"Is in IP range" only works on the IP address.'];
        }
        $priority = $str('priority') === '' ? '100' : $str('priority');
        if (preg_match('/^\d{1,4}$/D', $priority) !== 1 || (int) $priority > 9999) {
            $errors['priority'] = ['Priority is a number from 0 to 9999 (lower runs first).'];
        }
        $expires = null;
        if ($str('expires_in_hours') !== '') {
            if (preg_match('/^\d{1,5}$/D', $str('expires_in_hours')) !== 1 || (int) $str('expires_in_hours') < 1 || (int) $str('expires_in_hours') > 8760) {
                $errors['expires_in_hours'] = ['Expires after 1 to 8760 hours, or leave empty.'];
            } else {
                $expires = gmdate('Y-m-d H:i:s', time() + (int) $str('expires_in_hours') * 3600);
            }
        }
        $note = $str('note');
        if (mb_strlen($note) > 200) {
            $errors['note'] = ['The note is too long (200 characters at most).'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'name' => $name, 'part' => $part, 'header_name' => $part === 'header' ? $header : null, 'operator' => $operator, 'value' => $value,
            'negate' => !empty($input['negate']) ? 1 : 0, 'action' => $action, 'priority' => (int) $priority,
            'is_active' => !array_key_exists('is_active', $input) || !empty($input['is_active']) ? 1 : 0, 'expires_at' => $expires, 'note' => $note === '' ? null : $note,
        ];
    }

    private function syncCount(User $actor): void
    {
        $this->put('custom', (string) (int) $this->db->selectValue('SELECT COUNT(*) FROM firewall_rules WHERE is_active = 1', [], 0), $actor);
        $this->firewall->forgetRules();
    }

    private function put(string $key, ?string $value, User $actor): void
    {
        $this->policy->setSetting('fw.' . $key, $value, $actor);
    }

    /** @param array<string,mixed> $a @param array<string,mixed> $b @return array<string,string> */
    private function diff(array $a, array $b, bool $old): array
    {
        $out = [];
        $flat = static function (array $x): array {
            $f = [];
            foreach ($x as $k => $v) {
                if (is_array($v)) {
                    foreach ($v as $k2 => $v2) {
                        $f["{$k}.{$k2}"] = (string) $v2;
                    }
                } else {
                    $f[$k] = (string) $v;
                }
            }

            return $f;
        };
        $fa = $flat($a);
        $fb = $flat($b);
        foreach ($fb as $k => $v) {
            if (($fa[$k] ?? null) !== $v) {
                $out[$k] = $old ? (string) ($fa[$k] ?? '') : $v;
            }
        }

        return $out;
    }
}
