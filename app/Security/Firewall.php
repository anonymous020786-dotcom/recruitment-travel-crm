<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\Request;
use App\Security\Waf\Signatures;
use App\Support\Application;
use App\Support\Db;
use App\Support\Logger;
use App\Support\RateLimiter;

/**
 * The application firewall — what runs on every request (see FirewallGuard) and what the tester in Admin → Security →
 * Firewall runs on a made-up request.
 *
 * Order of checks for one request:
 *   1. switched off, or the address is on an IP allow rule → let through (a trusted office is never firewalled);
 *   2. lockdown: the staff area only answers allow-listed addresses;
 *   3. country rules (only when the request came through a trusted proxy that sets the country header);
 *   4. the super admin's own rules, by priority — `allow` stops here, `block` blocks, `log` records and continues;
 *   5. the managed rule sets (SQL injection, XSS, traversal, code injection, scanners, probes, protocol, empty user agent).
 * The first `block` wins. In monitor mode nothing is blocked; every would-be block is only logged.
 *
 * Form bodies of signed-in staff are not inspected: they are authenticated and CSRF-protected, and editors legitimately type
 * code into pages. Payment webhooks are not inspected either (every one is verified by its own signature).
 */
final class Firewall
{
    /** Where lockdown and "staff area only" country rules apply. */
    public const STAFF_PREFIXES = ['/login', '/two-factor', '/forgot-password', '/reset-password', '/confirm-password', '/admin', '/account', '/dashboard'];
    public const BODY_EXEMPT_PREFIXES = ['/webhooks/'];
    public const DEFAULTS = ['enabled' => '1', 'monitor' => '0', 'escalate_threshold' => '20', 'escalate_minutes' => '60', 'probe_ban' => '30', 'geo_scope' => 'off', 'geo_mode' => 'block', 'geo_countries' => '', 'lockdown' => '0', 'custom' => '0'];
    public const MAX_EVENTS_PER_MINUTE = 60;

    /** @var list<array<string,mixed>>|null */
    private ?array $customRules = null;

    public function __construct(
        private readonly Application $app,
        private readonly Db $db,
        private readonly RateLimiter $limiter,
        private readonly Logger $logger,
    ) {
    }

    // ---- settings ---------------------------------------------------------------------------------------------

    public function setting(string $key): string
    {
        $saved = (array) $this->app->config()->get('firewall.settings', []);

        return (string) ($saved[$key] ?? self::DEFAULTS[$key] ?? '');
    }

    /** off | log | block for a managed set. */
    public function mode(string $set): string
    {
        $m = $this->setting('mode.' . $set);

        return in_array($m, ['off', 'log', 'block'], true) ? $m : (string) (Signatures::SETS[$set]['default'] ?? 'off');
    }

    public function enabled(): bool
    {
        return $this->setting('enabled') !== '0';
    }

    // ---- reading a request --------------------------------------------------------------------------------------

    /**
     * The parts of a request the firewall looks at.
     *
     * @return array{method:string,path:string,uri:string,query:string,body:string,user_agent:string,referer:string,cookie:string,headers:array<string,string>,ip:string,country:?string,staff:bool}
     */
    public function view(Request $request): array
    {
        $uri = $request->requestUri();
        $path = $request->path();
        $method = strtoupper((string) ($request->realMethod()));
        $body = '';
        $exempt = false;
        foreach (self::BODY_EXEMPT_PREFIXES as $p) {
            $exempt = $exempt || str_starts_with($path, $p);
        }
        if (!$exempt && !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $body = $request->rawBody();
            if ($body === '') {
                $body = $this->flatten($request->all());   // multipart forms: the fields (files themselves are checked by the upload code)
            }
        }
        $headers = $request->headers();
        unset($headers['Cookie']);

        return [
            'method' => $method,
            'path' => $path,
            'uri' => $uri,
            'query' => (string) parse_url($uri, PHP_URL_QUERY),
            'body' => substr($body, 0, Signatures::MAX_INSPECT),
            'user_agent' => (string) $request->header('User-Agent', ''),
            'referer' => (string) $request->header('Referer', ''),
            'cookie' => (string) $request->header('Cookie', ''),
            'headers' => $headers,
            'ip' => $request->ip(),
            'country' => $this->country($request),
            'staff' => $body !== '' && $this->signedIn($request),
        ];
    }

    /**
     * Every rule that matches, in the order they were checked, and the verdict. Has no side effects (the tester uses it too).
     *
     * @param array<string,mixed> $v a view() array (the tester builds one by hand)
     * @return array{verdict:string,matches:list<array{key:string,label:string,action:string,part:string,sample:string,custom_id:?int}>}
     */
    public function inspect(array $v, ?string $ipVerdict = null): array
    {
        $matches = [];
        $verdict = 'pass';
        $add = static function (string $key, string $label, string $action, string $part, string $sample, ?int $customId = null) use (&$matches, &$verdict): void {
            $matches[] = ['key' => $key, 'label' => $label, 'action' => $action, 'part' => $part, 'sample' => mb_substr($sample, 0, 200), 'custom_id' => $customId];
            if ($action === 'block' && $verdict === 'pass') {
                $verdict = 'block';
            }
        };

        if (!$this->enabled()) {
            return ['verdict' => 'pass', 'matches' => []];
        }
        $ipVerdict ??= $this->ipVerdict((string) $v['ip']);
        if ($ipVerdict === 'allow') {
            return ['verdict' => 'allow', 'matches' => [['key' => 'ip_allow', 'label' => 'Address on an IP allow rule', 'action' => 'allow', 'part' => 'ip', 'sample' => (string) $v['ip'], 'custom_id' => null]]];
        }
        $staffArea = $this->isStaffPath((string) $v['path']);

        // 2. lockdown
        if ($this->setting('lockdown') === '1' && $staffArea) {
            $add('lockdown', 'Lockdown: staff area limited to allow-listed addresses', 'block', 'ip', (string) $v['ip']);
        }

        // 3. countries
        $scope = $this->setting('geo_scope');
        $country = $v['country'] ?? null;
        if ($scope !== 'off' && is_string($country) && ($scope === 'site' || $staffArea)) {
            $list = array_filter(explode(',', $this->setting('geo_countries')));
            $listed = in_array($country, $list, true);
            if (($this->setting('geo_mode') === 'allow' && !$listed && $list !== []) || ($this->setting('geo_mode') !== 'allow' && $listed)) {
                $add('geo', 'Country rule (' . $country . ')', 'block', 'country', $country);
            }
        }

        // 4. custom rules
        foreach ($this->customRules() as $rule) {
            $subject = $this->subject($rule, $v);
            if ($subject === null) {
                continue;
            }
            $hit = $this->ruleMatches($rule, $subject);
            if ((bool) $rule['negate']) {
                $hit = !$hit;
            }
            if (!$hit) {
                continue;
            }
            if ($rule['action'] === 'allow') {
                $matches[] = ['key' => 'custom:' . $rule['id'], 'label' => (string) $rule['name'], 'action' => 'allow', 'part' => (string) $rule['part'], 'sample' => mb_substr($subject, 0, 200), 'custom_id' => (int) $rule['id']];

                return ['verdict' => $verdict === 'block' ? 'block' : 'allow', 'matches' => $matches];
            }
            $add('custom:' . $rule['id'], (string) $rule['name'], (string) $rule['action'], (string) $rule['part'], $subject, (int) $rule['id']);
        }

        // 5. managed sets
        $this->protocol($v, $add);
        foreach (Signatures::SETS as $set => $def) {
            $mode = $this->mode($set);
            if ($mode === 'off' || $def['patterns'] === []) {
                continue;
            }
            foreach ($def['parts'] as $part) {
                $raw = match ($part) {
                    'headers' => implode("\n", array_diff_key((array) $v['headers'], ['User-Agent' => 1, 'Referer' => 1])),
                    'body' => (bool) $v['staff'] ? '' : (string) $v['body'],
                    default => (string) ($v[$part] ?? ''),
                };
                if ($raw === '') {
                    continue;
                }
                $found = Signatures::match($set, Signatures::normalize($raw));
                if ($found !== null) {
                    $add($set, (string) $def['label'], $mode, $part, $found);
                    break;   // one match per set is enough
                }
            }
        }
        if ($this->mode('empty_ua') !== 'off' && trim((string) $v['user_agent']) === '') {
            $add('empty_ua', (string) Signatures::SETS['empty_ua']['label'], $this->mode('empty_ua'), 'user_agent', '');
        }

        return ['verdict' => $verdict, 'matches' => $matches];
    }

    /**
     * Inspect a live request and act on it: log what matched (throttled), count strikes, ban persistent attackers.
     *
     * @return array{blocked:bool,reference:string}
     */
    public function guard(Request $request, ?string $ipVerdict = null): array
    {
        $v = $this->view($request);
        $result = $this->inspect($v, $ipVerdict);
        $reference = (string) ($request->attribute('request_id') ?? bin2hex(random_bytes(6)));
        if ($result['matches'] === [] || $result['verdict'] === 'allow') {
            return ['blocked' => false, 'reference' => $reference];
        }
        $monitor = $this->setting('monitor') === '1';
        $blocked = $result['verdict'] === 'block' && !$monitor;

        foreach ($result['matches'] as $m) {
            if ($m['custom_id'] !== null) {
                $this->db->affectingStatement('UPDATE firewall_rules SET hits = hits + 1, last_hit_at = UTC_TIMESTAMP() WHERE id = :id', ['id' => $m['custom_id']]);
            }
            $action = $m['action'] === 'block' && !$monitor ? 'block' : 'log';
            $this->record($v, $m['key'], $m['label'] . ($m['action'] === 'block' && $monitor ? ' (monitor mode)' : ''), $action, $m['part'], $m['sample'], $reference);
            if ($action === 'block') {
                break;   // the first block is what happened; later matches add nothing
            }
        }
        if ($blocked) {
            $this->escalate($v, $result['matches'], $reference);
        }

        return ['blocked' => $blocked, 'reference' => $reference];
    }

    // ---- helpers used by the admin side ------------------------------------------------------------------------

    public function isStaffPath(string $path): bool
    {
        foreach (self::STAFF_PREFIXES as $p) {
            if ($path === $p || str_starts_with($path, $p . '/')) {
                return true;
            }
        }

        return false;
    }

    /** Forget the per-request cache of custom rules (after the admin changed them). */
    public function forgetRules(): void
    {
        $this->customRules = null;
    }

    // ---- internals --------------------------------------------------------------------------------------------

    /** @param array<string,mixed> $v @param callable(string,string,string,string,string):void $add */
    private function protocol(array $v, callable $add): void
    {
        $mode = $this->mode('protocol');
        if ($mode === 'off') {
            return;
        }
        $label = (string) Signatures::SETS['protocol']['label'];
        if (!in_array($v['method'], Signatures::ALLOWED_METHODS, true)) {
            $add('protocol', $label . ': method ' . mb_substr((string) $v['method'], 0, 10), $mode, 'method', (string) $v['method']);
        } elseif (strlen((string) $v['uri']) > Signatures::MAX_URI) {
            $add('protocol', $label . ': address too long', $mode, 'path', substr((string) $v['uri'], 0, 80));
        } elseif (str_contains(strtolower((string) $v['uri']), '%00') || str_contains((string) $v['uri'], "\0") || str_contains((string) $v['path'], "\0")) {
            $add('protocol', $label . ': null byte', $mode, 'path', substr((string) $v['uri'], 0, 80));
        } else {
            foreach ((array) $v['headers'] as $name => $value) {
                if (strlen((string) $value) > Signatures::MAX_HEADER) {
                    $add('protocol', $label . ': oversized header', $mode, 'headers', (string) $name);
                    break;
                }
            }
        }
    }

    /** @param array<string,mixed> $rule @param array<string,mixed> $v */
    private function subject(array $rule, array $v): ?string
    {
        return match ($rule['part']) {
            'header' => (string) (((array) $v['headers'])[$this->headerKey((string) $rule['header_name'])] ?? ''),
            'body' => (bool) $v['staff'] ? null : (string) $v['body'],
            'country' => $v['country'] === null ? null : (string) $v['country'],
            'query' => rawurldecode((string) $v['query']),
            default => (string) ($v[(string) $rule['part']] ?? ''),
        };
    }

    /** @param array<string,mixed> $rule */
    public function ruleMatches(array $rule, string $subject): bool
    {
        $value = (string) $rule['value'];
        $s = strtolower($subject);
        $needle = strtolower($value);

        return match ($rule['operator']) {
            'contains' => $needle !== '' && str_contains($s, $needle),
            'equals' => $s === $needle,
            'starts_with' => $needle !== '' && str_starts_with($s, $needle),
            'ends_with' => $needle !== '' && str_ends_with($s, $needle),
            'regex' => @preg_match('~' . str_replace('~', '\~', $value) . '~iu', $subject) === 1,
            'in_list' => in_array($s, array_map('trim', explode(',', $needle)), true),
            'in_cidr' => $this->inAnyCidr($subject, $value),
            default => false,
        };
    }

    private function inAnyCidr(string $ip, string $list): bool
    {
        $bytes = IpRules::pack($ip);
        if ($bytes === null) {
            return false;
        }
        foreach (explode(',', $list) as $cidr) {
            $r = IpRules::parse(trim($cidr));
            if ($r !== null && strcmp($bytes, $r['from']) >= 0 && strcmp($bytes, $r['to']) <= 0) {
                return true;
            }
        }

        return false;
    }

    private function headerKey(string $name): string
    {
        return str_replace(' ', '-', ucwords(strtolower(str_replace(['-', '_'], ' ', $name))));
    }

    /** @return list<array<string,mixed>> */
    private function customRules(): array
    {
        if ($this->customRules === null) {
            $this->customRules = [];
            if ((int) $this->setting('custom') > 0) {
                try {
                    $this->customRules = $this->db->select(
                        'SELECT id, name, part, header_name, operator, value, negate, action FROM firewall_rules
                         WHERE is_active = 1 AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) ORDER BY priority, id',
                    );
                } catch (\Throwable $e) {
                    $this->logger->warning('firewall rules could not be loaded: {m}', ['m' => $e->getMessage()]);
                }
            }
        }

        return $this->customRules;
    }

    private function ipVerdict(string $ip): string
    {
        if (!(bool) $this->app->config()->get('security.ip_rules_active', false)) {
            return 'none';
        }
        try {
            return $this->app->get(IpRules::class)->verdict($ip);
        } catch (\Throwable) {
            return 'none';
        }
    }

    private function country(Request $request): ?string
    {
        if (!$request->isFromTrustedProxy()) {
            return null;
        }
        $c = strtoupper(trim((string) $request->header((string) $this->app->config()->get('firewall.country_header', 'CF-IPCountry'), '')));

        return preg_match('/^[A-Z][A-Z0-9]$/D', $c) === 1 ? $c : null;
    }

    /** Does the request carry the cookie of a live signed-in session? One primary-key lookup, only for requests with a body. */
    private function signedIn(Request $request): bool
    {
        $id = (string) $request->cookie((string) $this->app->config()->get('session.cookie', 'crm_session'), '');
        if (preg_match('/^[a-f0-9]{64}$/D', $id) !== 1) {
            return false;
        }
        try {
            return $this->db->exists(
                'SELECT 1 FROM sessions WHERE id = :id AND user_id IS NOT NULL AND last_activity >= :cut',
                ['id' => $id, 'cut' => time() - (int) $this->app->config()->get('session.lifetime_minutes', 480) * 60],
            );
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<mixed> $data */
    private function flatten(array $data): string
    {
        $out = [];
        array_walk_recursive($data, static function ($value, $key) use (&$out): void {
            if ($key !== '_token' && is_scalar($value)) {
                $out[] = $key . '=' . $value;
            }
        });

        return implode('&', $out);
    }

    /** @param array<string,mixed> $v */
    private function record(array $v, string $key, string $label, string $action, string $part, string $sample, string $reference): void
    {
        try {
            if ($this->limiter->hit('fw-log|ip:' . $v['ip'], 60) > self::MAX_EVENTS_PER_MINUTE) {
                return;   // under a flood, one address cannot fill the log
            }
            $this->db->insertRow('firewall_events', [
                'ip_address' => @inet_pton((string) $v['ip']) ?: "\0\0\0\0", 'method' => substr((string) $v['method'], 0, 10), 'path' => mb_substr((string) $v['path'], 0, 300),
                'rule_key' => substr($key, 0, 60), 'rule_label' => mb_substr($label, 0, 120), 'action' => $action, 'part' => substr($part, 0, 40),
                'sample' => $sample === '' ? null : mb_substr($sample, 0, 200), 'user_agent' => mb_substr((string) $v['user_agent'], 0, 255),
                'country' => $v['country'], 'request_id' => substr($reference, 0, 40),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('firewall event not recorded: {m}', ['m' => $e->getMessage()]);
        }
    }

    /**
     * A blocked request is a strike against its address. Probing for other software's secret files bans at once (if
     * switched on); otherwise enough strikes within the window ban the address for a while. Never throws.
     *
     * @param array<string,mixed> $v
     * @param list<array<string,mixed>> $matches
     */
    private function escalate(array $v, array $matches, string $reference): void
    {
        try {
            $ip = (string) $v['ip'];
            $minutes = max(1, (int) $this->setting('escalate_minutes'));
            $probeBan = (int) $this->setting('probe_ban');
            $blockedBy = '';
            foreach ($matches as $m) {
                if ($m['action'] === 'block') {
                    $blockedBy = $m['key'];
                    break;
                }
            }
            $reason = null;
            if ($blockedBy === 'probes' && $probeBan > 0) {
                $reason = ['minutes' => $probeBan, 'note' => 'Automatic: probed for files that do not exist here'];
            } else {
                $threshold = (int) $this->setting('escalate_threshold');
                if ($threshold > 0 && $this->limiter->hit('fw-strike|ip:' . $ip, $minutes * 60) >= $threshold) {
                    $reason = ['minutes' => $minutes, 'note' => "Automatic: {$threshold} requests blocked by the firewall"];
                }
            }
            if ($reason === null) {
                return;
            }
            $range = IpRules::parse($ip);
            $rules = $this->app->get(IpRules::class);
            if ($range === null || $rules->verdict($ip) !== 'none') {
                return;
            }
            $rules->add('block', $range['cidr'], $reason['note'], $reason['minutes'], null, null, 'auto');
            $this->record($v, 'ban', 'Address banned for ' . $reason['minutes'] . ' min', 'ban', 'ip', $ip, $reference);
        } catch (\Throwable $e) {
            $this->logger->warning('firewall escalation failed: {m}', ['m' => $e->getMessage()]);
        }
    }
}
