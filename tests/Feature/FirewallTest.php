<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Auth;
use App\Auth\Gate;
use App\Auth\PermissionService;
use App\Exceptions\AuthorizationException;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Http\Kernel;
use App\Http\Middleware\FirewallGuard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Security\Firewall;
use App\Security\FirewallAdmin;
use App\Security\IpRules;
use App\Security\SecurityPolicy;
use App\Session\ArraySessionStore;
use App\Session\SessionStore;
use App\Support\Hash;
use App\Support\Logger;
use App\Support\RateLimiter;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

/** The application firewall: the engine, bans, the middleware and Admin → Security → Firewall. */
final class FirewallTest extends DbTestCase
{
    private Router $router;
    private ArraySessionStore $store;
    private string $sid = '';
    private string $token = '';
    private int $branch;
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];
    /** @var array<string,list<array<string,mixed>>> */
    private array $snapshot = [];
    private ?User $super = null;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        foreach (['security_settings', 'ip_rules', 'firewall_rules', 'firewall_events'] as $t) {
            $this->snapshot[$t] = $this->db->select("SELECT * FROM {$t}");
            $this->db->affectingStatement("DELETE FROM {$t}");
        }
        $this->db->affectingStatement("DELETE FROM rate_limits");
        $this->app->get(SecurityPolicy::class)->setSetting('fw.enabled', null, null);   // reload defaults into config
        $this->app->config()->set('security.ip_rules_active', false);
        $this->app->get(Firewall::class)->forgetRules();
        $this->branch = (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => 'FW branch', 'code' => 'FWX-' . bin2hex(random_bytes(2))]);
        $this->store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $this->store);
        $this->router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($this->router);
        $this->router->finalizeNames();
        $this->app->instance(Router::class, $this->router);
    }

    protected function tearDown(): void
    {
        foreach ($this->snapshot as $t => $rows) {
            $this->db->affectingStatement("DELETE FROM {$t}");
            foreach ($rows as $row) {
                $this->db->insertRow($t, $row);
            }
        }
        $this->db->affectingStatement("DELETE FROM rate_limits");
        $this->app->get(SecurityPolicy::class)->setSetting('fw.enabled', null, null);
        $this->db->affectingStatement("DELETE FROM sessions WHERE user_id IN (SELECT id FROM (SELECT id FROM users WHERE email LIKE 'tfw\\_%') x)");
        $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN (SELECT id FROM (SELECT id FROM users WHERE email LIKE 'tfw\\_%') x)");
        $this->db->affectingStatement("DELETE FROM users WHERE email LIKE 'tfw\\_%'");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module = 'security'");
        $this->db->affectingStatement('DELETE FROM branches WHERE code LIKE ?', ['FWX-%']);
    }

    // ---- helpers ---------------------------------------------------------------------------------------------------

    private function user(string $role): int
    {
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "TFW {$role}", 'email' => 'tfw_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $this->branch, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $this->branch]);
        $this->userIds[] = $id;

        return $id;
    }

    private function super(): User
    {
        return $this->super ??= $this->app->get(UserRepository::class)->findById($this->user('super_admin'));
    }

    private function fw(): Firewall
    {
        return $this->app->get(Firewall::class);
    }

    private function admin(): FirewallAdmin
    {
        return $this->app->get(FirewallAdmin::class);
    }

    /** @param array<string,string> $headers @param array<string,string> $cookies */
    private function request(string $method, string $uri, string $body = '', array $headers = [], string $ip = '198.51.100.7', array $cookies = [], array $proxies = []): Request
    {
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => $ip, 'HTTP_HOST' => 'localhost'];
        $headers += ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/129'];
        foreach ($headers as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        parse_str($body, $post);

        return new Request($query, $method === 'GET' ? [] : $post, $cookies, [], $server, $body, $proxies);
    }

    private function through(Request $r): Response
    {
        return (new FirewallGuard($this->app, $this->app->get(Logger::class)))->handle($r, static fn (Request $x): Response => Response::html('ok'));
    }

    private function verdict(Request $r): string
    {
        return $this->fw()->inspect($this->fw()->view($r))['verdict'];
    }

    private function errorsOf(callable $do, string $label = ''): array
    {
        try {
            $do();
        } catch (ValidationException $e) {
            return $e->errors();
        }
        self::fail("a validation error was expected {$label}");
    }

    private function events(string $action = ''): int
    {
        return (int) $this->db->selectValue('SELECT COUNT(*) FROM firewall_events' . ($action !== '' ? ' WHERE action = :a' : ''), $action !== '' ? ['a' => $action] : []);
    }

    /** @param array<string,mixed> $over */
    private function rule(array $over): int
    {
        return $this->admin()->saveRule($over + ['name' => 'TFW rule', 'part' => 'path', 'operator' => 'contains', 'value' => 'x', 'action' => 'block', 'priority' => '100', 'is_active' => '1'], $this->super());
    }

    private function actAs(int $userId, bool $confirmed = true): void
    {
        $this->sid = bin2hex(random_bytes(32));
        $this->token = bin2hex(random_bytes(32));
        $this->store->sessions[$this->sid] = ['data' => ['_auth_user_id' => $userId, '_auth_at' => time(), '_authenticated_at' => $confirmed ? time() : time() - 7200, '_token' => $this->token, '_started_at' => time(), '_last_regen' => time(), '_last_activity' => time()], 'touched' => time()];
        $auth = new Auth($this->app, new UserRepository($this->db));
        $this->app->instance(Auth::class, $auth);
        $this->app->instance(Gate::class, new Gate($this->app, $this->app->get(PermissionService::class), $auth));
    }

    /** @param array<string,mixed> $post */
    private function send(string $method, string $uri, array $post = []): Response
    {
        if ($post !== [] && !isset($post['_token'])) {
            $post['_token'] = $this->token;
        }
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return $this->router->dispatch(new Request($query, $post, ['crm_session' => $this->sid], [], [
            'REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'http://localhost',
        ], ''));
    }

    /** @param array<string,mixed> $post */
    private function code(string $method, string $uri, array $post = []): int
    {
        try {
            return $this->send($method, $uri, $post)->getStatus();
        } catch (AuthorizationException) {
            return 403;
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }

    // ---- the managed rule sets on real requests --------------------------------------------------------------------

    public function test_attacks_are_blocked_and_normal_traffic_goes_through(): void
    {
        self::assertContains(FirewallGuard::class, (new Kernel())->global);
        $k = (new Kernel())->global;
        self::assertLessThan(array_search(FirewallGuard::class, $k, true), array_search(\App\Http\Middleware\IpFilter::class, $k, true), 'the IP filter runs first');

        foreach (['/overseas-jobs?q=1%27%20UNION%20SELECT%20password%20FROM%20users--', '/blog/x?s=%3Cscript%3Ealert(1)%3C/script%3E', '/.env', '/wp-login.php', '/download?file=../../etc/passwd'] as $uri) {
            $res = $this->through($this->request('GET', $uri));
            self::assertSame(403, $res->getStatus(), $uri);
            self::assertStringContainsString('blocked for security reasons', $res->getBody());
            self::assertStringContainsString('no-store', (string) $res->getHeader('Cache-Control'));
        }
        self::assertSame(403, $this->through($this->request('POST', '/contact', 'name=x&message=' . rawurlencode("' OR 1=1 --")))->getStatus(), 'anonymous form bodies are inspected');
        self::assertSame(403, $this->through($this->request('GET', '/', '', ['User-Agent' => 'sqlmap/1.7']))->getStatus());
        self::assertSame(403, $this->through($this->request('TRACE', '/'))->getStatus(), 'protocol: method');
        self::assertSame(403, $this->through($this->request('GET', '/?x=' . str_repeat('a', 2100)))->getStatus(), 'protocol: long address');
        self::assertSame(403, $this->through($this->request('GET', '/file%00.jpg'))->getStatus(), 'protocol: null byte');

        foreach (['/', '/overseas-jobs?country=AE&q=driver', '/blog/how-to-apply', '/travel-packages/goa?utm_source=fb'] as $uri) {
            self::assertSame(200, $this->through($this->request('GET', $uri))->getStatus(), $uri);
        }
        self::assertSame(200, $this->through($this->request('POST', '/contact', 'name=Ravi&message=' . rawurlencode("Hi, I'm O'Brien; please update me on the visa. Select me!")))->getStatus());
    }

    public function test_a_blocked_request_is_logged_with_a_reference_and_what_matched(): void
    {
        $r = $this->request('GET', '/x?id=1%20AND%20SLEEP(5)');
        $r->setAttribute('request_id', 'req-abc123');
        $res = $this->through($r);
        self::assertStringContainsString('req-abc123', $res->getBody());
        $ev = $this->db->selectOne('SELECT * FROM firewall_events ORDER BY id DESC LIMIT 1');
        self::assertSame('sqli', $ev['rule_key']);
        self::assertSame('block', $ev['action']);
        self::assertSame('query', $ev['part']);
        self::assertStringContainsString('sleep(', (string) $ev['sample']);
        self::assertSame('req-abc123', $ev['request_id']);
        self::assertSame('198.51.100.7', inet_ntop($ev['ip_address']));
    }

    public function test_modes_log_only_off_and_monitor_mode(): void
    {
        $this->admin()->saveSettings(['enabled' => '1', 'modes' => ['sqli' => 'log', 'xss' => 'off'], 'escalate_threshold' => '0', 'escalate_minutes' => '60', 'probe_ban' => '0'], $this->super());
        self::assertSame(200, $this->through($this->request('GET', '/?q=1%20UNION%20SELECT%201'))->getStatus(), 'log only lets it through…');
        self::assertSame(1, $this->events('log'), '…but records it');
        self::assertSame(200, $this->through($this->request('GET', '/?q=%3Cscript%3E'))->getStatus(), 'off');
        self::assertSame(1, $this->events());

        $this->admin()->saveSettings(['enabled' => '1', 'monitor' => '1', 'modes' => ['sqli' => 'block', 'xss' => 'block'], 'escalate_threshold' => '0', 'escalate_minutes' => '60', 'probe_ban' => '0'], $this->super());
        self::assertSame(200, $this->through($this->request('GET', '/.env'))->getStatus(), 'monitor mode never blocks');
        self::assertStringContainsString('monitor mode', (string) $this->db->selectValue("SELECT rule_label FROM firewall_events WHERE rule_key = 'probes'"));

        $this->admin()->saveSettings(['modes' => [], 'escalate_threshold' => '0', 'escalate_minutes' => '60', 'probe_ban' => '0'], $this->super());   // enabled unticked
        self::assertFalse($this->fw()->enabled());
        self::assertSame(200, $this->through($this->request('GET', '/.env'))->getStatus(), 'switched off');
    }

    public function test_requests_without_a_user_agent_are_logged_not_blocked_by_default(): void
    {
        self::assertSame(200, $this->through($this->request('GET', '/', '', ['User-Agent' => '']))->getStatus());
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM firewall_events WHERE rule_key = 'empty_ua' AND action = 'log'"));
    }

    public function test_signed_in_staff_form_content_and_webhooks_are_not_inspected(): void
    {
        $uid = $this->user('manager');
        $sid = bin2hex(random_bytes(32));
        $this->db->insertRow('sessions', ['id' => $sid, 'user_id' => $uid, 'payload' => '', 'last_activity' => time()]);
        $code = 'body=' . rawurlencode("```\n<script>alert(1)</script>\n```\nSELECT * FROM x UNION SELECT 1");
        self::assertSame(200, $this->through($this->request('POST', '/admin/cms', $code, [], '198.51.100.7', ['crm_session' => $sid]))->getStatus(), 'an editor writing about code is not blocked');
        self::assertSame(403, $this->through($this->request('POST', '/admin/cms', $code, [], '198.51.100.7', ['crm_session' => str_repeat('a', 64)]))->getStatus(), 'a made-up session cookie does not help');
        $this->db->affectingStatement('UPDATE sessions SET last_activity = 0 WHERE id = :i', ['i' => $sid]);
        self::assertSame(403, $this->through($this->request('POST', '/admin/cms', $code, [], '198.51.100.7', ['crm_session' => $sid]))->getStatus(), 'nor does an expired one');
        self::assertSame(403, $this->through($this->request('GET', '/admin/cms?q=%27%20UNION%20SELECT%201', '', [], '198.51.100.7', ['crm_session' => $sid]))->getStatus(), 'staff query strings are still inspected');

        self::assertSame(200, $this->through($this->request('POST', '/webhooks/stripe', '{"description":"<script>x</script> UNION SELECT"}'))->getStatus(), 'webhooks are verified by signature instead');
        $this->db->affectingStatement('DELETE FROM sessions WHERE id = :i', ['i' => $sid]);
    }

    public function test_an_allow_listed_address_is_never_firewalled(): void
    {
        $this->app->get(IpRules::class)->add('allow', '198.51.100.0/24', 'office', null, null);
        $r = $this->request('GET', '/.env');
        $r->setAttribute('ip_verdict', 'allow');
        self::assertSame(200, $this->through($r)->getStatus());
        self::assertSame('allow', $this->verdict($this->request('GET', '/.env')), 'looked up when the IP filter did not pass it on');
        self::assertSame(0, $this->events());
    }

    // ---- bans ------------------------------------------------------------------------------------------------------

    public function test_probing_for_secret_files_bans_the_address_at_once(): void
    {
        $this->through($this->request('GET', '/.git/config', '', [], '198.51.100.40'));
        self::assertSame('block', $this->app->get(IpRules::class)->verdict('198.51.100.40'));
        $row = $this->db->selectOne("SELECT source, expires_at FROM ip_rules WHERE cidr = '198.51.100.40'");
        self::assertSame('auto', $row['source']);
        self::assertNotNull($row['expires_at']);
        self::assertSame(1, $this->events('ban'));

        $this->admin()->saveSettings(['enabled' => '1', 'escalate_threshold' => '0', 'escalate_minutes' => '60', 'probe_ban' => '0', 'modes' => []], $this->super());
        $this->through($this->request('GET', '/.git/config', '', [], '198.51.100.41'));
        self::assertSame('none', $this->app->get(IpRules::class)->verdict('198.51.100.41'), 'probe bans can be switched off');
    }

    public function test_repeated_blocks_ban_the_address(): void
    {
        $this->admin()->saveSettings(['enabled' => '1', 'escalate_threshold' => '3', 'escalate_minutes' => '15', 'probe_ban' => '0', 'modes' => []], $this->super());
        for ($i = 0; $i < 2; $i++) {
            $this->through($this->request('GET', '/?q=%3Cscript%3E', '', [], '198.51.100.50'));
        }
        self::assertSame('none', $this->app->get(IpRules::class)->verdict('198.51.100.50'));
        $this->through($this->request('GET', '/?q=%3Cscript%3E', '', [], '198.51.100.50'));
        self::assertSame('block', $this->app->get(IpRules::class)->verdict('198.51.100.50'));
        self::assertGreaterThan(time() + 14 * 60, strtotime((string) $this->db->selectValue("SELECT expires_at FROM ip_rules WHERE cidr = '198.51.100.50'") . ' UTC'));
    }

    public function test_one_address_cannot_flood_the_log(): void
    {
        $this->admin()->saveSettings(['enabled' => '1', 'escalate_threshold' => '0', 'escalate_minutes' => '60', 'probe_ban' => '0', 'modes' => []], $this->super());
        for ($i = 0; $i < Firewall::MAX_EVENTS_PER_MINUTE + 15; $i++) {
            $this->through($this->request('GET', '/?q=%3Cscript%3E', '', [], '198.51.100.60'));
        }
        self::assertSame(Firewall::MAX_EVENTS_PER_MINUTE, $this->events());
        $this->through($this->request('GET', '/?q=%3Cscript%3E', '', [], '198.51.100.61'));
        self::assertSame(Firewall::MAX_EVENTS_PER_MINUTE + 1, $this->events(), 'other addresses are still logged');
    }

    // ---- custom rules ----------------------------------------------------------------------------------------------

    public function test_custom_rules_block_log_and_allow_in_priority_order(): void
    {
        $block = $this->rule(['name' => 'Old admin', 'part' => 'path', 'operator' => 'starts_with', 'value' => '/old-admin', 'priority' => '50']);
        $this->rule(['name' => 'Watch partner', 'part' => 'user_agent', 'operator' => 'contains', 'value' => 'PartnerBot', 'action' => 'log']);
        $this->rule(['name' => 'Trust monitor', 'part' => 'header', 'header_name' => 'X-Monitor-Key', 'operator' => 'equals', 'value' => 's3cret-key', 'action' => 'allow', 'priority' => '10']);

        self::assertSame(403, $this->through($this->request('GET', '/OLD-ADMIN/login'))->getStatus(), 'comparisons ignore case');
        self::assertSame(200, $this->through($this->request('GET', '/', '', ['User-Agent' => 'PartnerBot/2']))->getStatus());
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM firewall_events WHERE rule_label = 'Watch partner' AND action = 'log'"));
        self::assertSame(200, $this->through($this->request('GET', '/.env', '', ['X-Monitor-Key' => 's3cret-key']))->getStatus(), 'an allow rule skips everything after it');
        self::assertSame(403, $this->through($this->request('GET', '/.env', '', ['X-Monitor-Key' => 'wrong']))->getStatus());
        self::assertSame(1, (int) $this->db->selectValue('SELECT hits FROM firewall_rules WHERE id = :i', ['i' => $block]));
    }

    public function test_custom_rule_operators_negation_and_expiry(): void
    {
        $fw = $this->fw();
        $r = static fn (string $op, string $value, bool $neg = false): array => ['operator' => $op, 'value' => $value, 'negate' => $neg ? 1 : 0];
        self::assertTrue($fw->ruleMatches($r('regex', '^/api/v[0-9]+/'), '/api/v2/users'));
        self::assertTrue($fw->ruleMatches($r('regex', 'a~b'), 'xa~by'), 'the delimiter in a pattern is safe');
        self::assertTrue($fw->ruleMatches($r('in_list', 'GET, head'), 'HEAD'));
        self::assertTrue($fw->ruleMatches($r('in_cidr', '10.0.0.0/8, 2001:db8::/32'), '2001:db8::5'));
        self::assertFalse($fw->ruleMatches($r('in_cidr', '10.0.0.0/8'), 'not-an-ip'));
        self::assertTrue($fw->ruleMatches($r('ends_with', '.php'), '/x/SHELL.PHP'));
        self::assertFalse($fw->ruleMatches($r('contains', ''), 'anything'));

        // only allow the payment dashboard from the office range
        $this->rule(['name' => 'Office only', 'part' => 'ip', 'operator' => 'in_cidr', 'value' => '192.0.2.0/24', 'negate' => '1']);
        self::assertSame(403, $this->through($this->request('GET', '/', '', [], '198.51.100.9'))->getStatus());
        self::assertSame(200, $this->through($this->request('GET', '/', '', [], '192.0.2.20'))->getStatus());

        $id = (int) $this->db->selectValue("SELECT id FROM firewall_rules WHERE name = 'Office only'");
        $this->db->affectingStatement('UPDATE firewall_rules SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = :i', ['i' => $id]);
        $this->fw()->forgetRules();
        self::assertSame(200, $this->through($this->request('GET', '/', '', [], '198.51.100.9'))->getStatus(), 'an expired rule stops applying');
    }

    public function test_custom_rules_are_validated_and_counted(): void
    {
        $cases = [
            'name' => ['name' => ''], 'part' => ['part' => 'cookie_jar'], 'operator' => ['operator' => 'like'], 'action' => ['action' => 'drop'],
            'value' => ['operator' => 'regex', 'value' => '(unclosed'], 'header_name' => ['part' => 'header', 'header_name' => 'Bad Header!'],
            'priority' => ['priority' => '99999'], 'expires_in_hours' => ['expires_in_hours' => '0'], 'note' => ['note' => str_repeat('n', 201)],
        ];
        foreach ($cases as $field => $over) {
            self::assertArrayHasKey($field, $this->errorsOf(fn () => $this->rule($over), $field), $field);
        }
        self::assertArrayHasKey('value', $this->errorsOf(fn () => $this->rule(['part' => 'ip', 'operator' => 'in_cidr', 'value' => '10.0.0.0/8, nonsense'])));
        self::assertArrayHasKey('operator', $this->errorsOf(fn () => $this->rule(['part' => 'path', 'operator' => 'in_cidr', 'value' => '10.0.0.0/8'])));
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM firewall_rules'));

        $id = $this->rule(['name' => 'Temp', 'expires_in_hours' => '2']);
        self::assertSame('1', $this->fw()->setting('custom'), 'the fast-path flag follows the number of active rules');
        $this->admin()->toggleRule($id, $this->super());
        self::assertSame('0', $this->fw()->setting('custom'));
        $this->admin()->saveRule(['name' => 'Temp 2', 'part' => 'path', 'operator' => 'contains', 'value' => 'y', 'action' => 'log', 'is_active' => '1'], $this->super(), $id);
        self::assertSame('Temp 2', $this->admin()->rule($id)['name']);
        self::assertTrue($this->admin()->deleteRule($id, $this->super()));
        self::assertFalse($this->admin()->deleteRule($id, $this->super()));
        self::assertSame('0', $this->fw()->setting('custom'));
    }

    // ---- countries and lockdown ------------------------------------------------------------------------------------

    public function test_country_rules_apply_only_behind_a_trusted_proxy(): void
    {
        $this->admin()->saveGeo('site', 'block', ['RU', 'T1'], null, $this->super());
        $proxied = fn (string $country, string $path = '/'): Request => $this->request('GET', $path, '', ['CF-IPCountry' => $country], '10.0.0.1', [], ['10.0.0.1']);
        self::assertSame(403, $this->through($proxied('RU'))->getStatus());
        self::assertSame(403, $this->through($proxied('t1'))->getStatus(), 'Tor exit nodes');
        self::assertSame(200, $this->through($proxied('IN'))->getStatus());
        self::assertSame(200, $this->through($this->request('GET', '/', '', ['CF-IPCountry' => 'RU']))->getStatus(), 'a country header from a random visitor is ignored');

        $this->admin()->saveGeo('staff', 'allow', ['IN', 'AE'], null, $this->super());
        self::assertSame(200, $this->through($proxied('US', '/overseas-jobs'))->getStatus(), 'the public site stays open');
        self::assertSame(403, $this->through($proxied('US', '/login'))->getStatus(), 'sign-in only from the allowed countries');
        self::assertSame(200, $this->through($proxied('AE', '/login'))->getStatus());
    }

    public function test_country_rules_are_validated_and_cannot_block_yourself(): void
    {
        self::assertArrayHasKey('geo_scope', $this->errorsOf(fn () => $this->admin()->saveGeo('everywhere', 'block', [], null, $this->super())));
        self::assertArrayHasKey('geo_countries', $this->errorsOf(fn () => $this->admin()->saveGeo('site', 'block', ['ZZ'], null, $this->super())));
        self::assertArrayHasKey('geo_countries', $this->errorsOf(fn () => $this->admin()->saveGeo('site', 'allow', [], null, $this->super())));
        self::assertArrayHasKey('geo_countries', $this->errorsOf(fn () => $this->admin()->saveGeo('site', 'block', ['IN'], 'IN', $this->super())));
        self::assertArrayHasKey('geo_countries', $this->errorsOf(fn () => $this->admin()->saveGeo('staff', 'allow', ['AE'], 'IN', $this->super())));
        $this->admin()->saveGeo('off', 'block', ['IN'], 'IN', $this->super());   // off never blocks anyone
        self::assertSame('IN', $this->fw()->setting('geo_countries'));
    }

    public function test_lockdown_keeps_the_staff_area_for_allow_listed_addresses_only(): void
    {
        $errors = $this->errorsOf(fn () => $this->admin()->setLockdown(true, '192.0.2.5', $this->super()));
        self::assertStringContainsString('192.0.2.5', $errors['lockdown'][0], 'you cannot lock yourself out');

        $this->app->get(IpRules::class)->add('allow', '192.0.2.5', 'me', null, null);
        $this->admin()->setLockdown(true, '192.0.2.5', $this->super());
        self::assertSame(403, $this->through($this->request('GET', '/login', '', [], '198.51.100.70'))->getStatus());
        self::assertSame(403, $this->through($this->request('GET', '/admin/users', '', [], '198.51.100.70'))->getStatus());
        self::assertSame(200, $this->through($this->request('GET', '/overseas-jobs', '', [], '198.51.100.70'))->getStatus(), 'the public site is untouched');
        self::assertSame(200, $this->through($this->request('GET', '/login', '', [], '192.0.2.5'))->getStatus());

        $this->admin()->setLockdown(false, '192.0.2.5', $this->super());
        self::assertSame(200, $this->through($this->request('GET', '/login', '', [], '198.51.100.70'))->getStatus());
    }

    // ---- robustness ------------------------------------------------------------------------------------------------

    public function test_the_firewall_fails_open(): void
    {
        $this->rule(['name' => 'Any', 'value' => 'zzz']);
        $this->fw()->forgetRules();
        $this->db->affectingStatement('RENAME TABLE firewall_events TO firewall_events_gone');
        try {
            self::assertSame(403, $this->through($this->request('GET', '/.env'))->getStatus(), 'blocking works even if the log cannot be written');
        } finally {
            $this->db->affectingStatement('RENAME TABLE firewall_events_gone TO firewall_events');
        }
        $this->db->affectingStatement('RENAME TABLE firewall_rules TO firewall_rules_gone');
        try {
            $this->fw()->forgetRules();
            self::assertSame(200, $this->through($this->request('GET', '/'))->getStatus(), 'a broken rules table never takes the site down');
        } finally {
            $this->db->affectingStatement('RENAME TABLE firewall_rules_gone TO firewall_rules');
        }
    }

    public function test_settings_are_validated_and_saving_the_default_removes_the_override(): void
    {
        foreach ([['escalate_threshold' => '2'], ['escalate_threshold' => 'x'], ['escalate_minutes' => '0'], ['probe_ban' => '99999'], ['modes' => ['sqli' => 'maybe']]] as $bad) {
            self::assertNotSame([], $this->errorsOf(fn () => $this->admin()->saveSettings($bad + ['enabled' => '1', 'escalate_threshold' => '20', 'escalate_minutes' => '60', 'probe_ban' => '30'], $this->super())));
        }
        $this->admin()->saveSettings(['enabled' => '1', 'modes' => ['empty_ua' => 'block'], 'escalate_threshold' => '20', 'escalate_minutes' => '60', 'probe_ban' => '30'], $this->super());
        self::assertSame('block', $this->fw()->mode('empty_ua'));
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM security_settings WHERE name = 'fw.mode.empty_ua'"));
        $this->admin()->saveSettings(['enabled' => '1', 'modes' => ['empty_ua' => 'log'], 'escalate_threshold' => '20', 'escalate_minutes' => '60', 'probe_ban' => '30'], $this->super());
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM security_settings WHERE name = 'fw.mode.empty_ua'"));
        self::assertGreaterThanOrEqual(2, (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE action = 'firewall_settings_changed'"));
    }

    public function test_the_tester_explains_a_verdict_without_side_effects(): void
    {
        $this->rule(['name' => 'Block beta', 'value' => '/beta']);
        $r = $this->admin()->test(['method' => 'GET', 'url' => "/beta?id=1' UNION SELECT 1", 'user_agent' => 'sqlmap', 'ip' => '198.51.100.80']);
        self::assertSame('block', $r['verdict']);
        $keys = array_column($r['matches'], 'key');
        self::assertContains('sqli', $keys);
        self::assertContains('scanners', $keys);
        self::assertContains('custom:' . $this->db->selectValue("SELECT id FROM firewall_rules WHERE name = 'Block beta'"), $keys);
        self::assertSame('pass', $this->admin()->test(['method' => 'GET', 'url' => '/overseas-jobs?q=driver', 'user_agent' => 'Mozilla/5.0'])['verdict']);
        self::assertSame(0, $this->events(), 'nothing logged');
        self::assertSame(0, (int) $this->db->selectValue('SELECT hits FROM firewall_rules'), 'no hits counted');
        self::assertSame('none', $this->app->get(IpRules::class)->verdict('198.51.100.80'), 'nobody banned');
    }

    // ---- the screens -------------------------------------------------------------------------------------------------

    public function test_only_the_super_admin_manages_the_firewall_and_changes_need_confirmation(): void
    {
        $this->actAs($this->user('admin'));
        foreach (['/admin/security/firewall', '/admin/security/firewall/settings', '/admin/security/firewall/rules', '/admin/security/firewall/test'] as $u) {
            self::assertSame(403, $this->code('GET', $u), $u);
        }
        $this->actAs($this->super()->id, confirmed: false);
        $res = $this->send('POST', '/admin/security/firewall/rules', ['name' => 'x', 'part' => 'path', 'operator' => 'contains', 'value' => 'x', 'action' => 'block']);
        self::assertStringContainsString('confirm', (string) $res->getHeader('Location'));
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM firewall_rules'));
    }

    public function test_the_screens_render_and_manage_everything(): void
    {
        $this->through($this->request('GET', '/wp-login.php', '', [], '198.51.100.90'));
        $this->actAs($this->super()->id);
        $page = $this->send('GET', '/admin/security/firewall');
        self::assertSame(200, $page->getStatus());
        self::assertStringContainsString('no-store', (string) $page->getHeader('Cache-Control'));
        self::assertStringContainsString('198.51.100.90', $page->getBody());
        self::assertStringContainsString('/wp-login.php', $page->getBody());
        self::assertStringNotContainsString('style="', $page->getBody(), 'no inline styles (the CSP forbids them)');
        self::assertStringContainsString('198.51.100.90', $this->send('GET', '/admin/security/firewall?ip=198.51.100.90&action=block')->getBody());
        self::assertStringNotContainsString('/wp-login.php', $this->send('GET', '/admin/security/firewall?action=log')->getBody());
        self::assertStringContainsString('SQL injection', $this->send('GET', '/admin/security/firewall/settings')->getBody());

        $this->send('PUT', '/admin/security/firewall/settings', ['_method' => 'PUT', 'enabled' => '1', 'modes' => ['xss' => 'log'], 'escalate_threshold' => '10', 'escalate_minutes' => '30', 'probe_ban' => '15']);
        self::assertSame('log', $this->fw()->mode('xss'));
        self::assertSame('10', $this->fw()->setting('escalate_threshold'));

        $this->send('POST', '/admin/security/firewall/rules', ['name' => 'Screen rule', 'part' => 'path', 'operator' => 'contains', 'value' => '/secret', 'action' => 'block', 'is_active' => '1']);
        $id = (int) $this->db->selectValue("SELECT id FROM firewall_rules WHERE name = 'Screen rule'");
        self::assertGreaterThan(0, $id);
        self::assertStringContainsString('Screen rule', $this->send('GET', '/admin/security/firewall/rules')->getBody());
        self::assertStringContainsString('value="/secret"', $this->send('GET', "/admin/security/firewall/rules?edit={$id}")->getBody());
        $this->send('PUT', "/admin/security/firewall/rules/{$id}", ['_method' => 'PUT', 'name' => 'Screen rule 2', 'part' => 'path', 'operator' => 'contains', 'value' => '/secret', 'action' => 'log', 'is_active' => '1']);
        self::assertSame('log', $this->admin()->rule($id)['action']);
        $this->send('POST', "/admin/security/firewall/rules/{$id}/toggle", ['x' => '1']);
        $this->send('POST', "/admin/security/firewall/rules/{$id}/delete", ['x' => '1']);
        self::assertNull($this->admin()->rule($id));
        self::assertSame(404, $this->code('POST', '/admin/security/firewall/rules/abc/delete', ['x' => '1']));

        $tested = $this->send('POST', '/admin/security/firewall/test', ['method' => 'GET', 'url' => '/?q=<script>alert(1)</script>', 'user_agent' => 'Mozilla', 'ip' => '203.0.113.9']);
        self::assertStringContainsString('Cross-site scripting', $tested->getBody());
        self::assertStringNotContainsString('<script>alert(1)</script>', $tested->getBody(), 'the tested payload is shown escaped');

        $this->send('POST', '/admin/security/firewall/geo', ['geo_scope' => 'staff', 'geo_mode' => 'block', 'countries' => ['CN']]);
        self::assertSame('staff', $this->fw()->setting('geo_scope'));
        $lock = $this->send('POST', '/admin/security/firewall/lockdown', ['on' => '1']);
        self::assertSame('/admin/security/firewall/settings', $lock->getHeader('Location'));
        self::assertSame('0', $this->fw()->setting('lockdown'), 'refused: 127.0.0.1 is not allow-listed');
    }
}
