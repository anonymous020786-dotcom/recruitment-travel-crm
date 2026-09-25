<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Auth;
use App\Auth\AuthService;
use App\Auth\Gate;
use App\Auth\PermissionService;
use App\Exceptions\AuthorizationException;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Http\Kernel;
use App\Http\Middleware\IpFilter;
use App\Http\Middleware\RateLimit;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Repositories\UserRepository;
use App\Security\IpRules;
use App\Security\SecurityPolicy;
use App\Services\RoleAdminService;
use App\Session\ArraySessionStore;
use App\Session\SessionStore;
use App\Support\Hash;
use App\Support\Logger;
use App\Support\RateLimiter;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

/** Admin → Security: editable rate limits, the two-factor policy, IP rules + automatic block, sessions, and who may do any of it. */
final class SecurityCentreTest extends DbTestCase
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
    /** @var list<array<string,mixed>> */
    private array $settingsSnapshot = [];
    /** @var list<array<string,mixed>> */
    private array $rulesSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->settingsSnapshot = $this->db->select('SELECT * FROM security_settings');
        $this->rulesSnapshot = $this->db->select('SELECT * FROM ip_rules');
        $this->db->affectingStatement('DELETE FROM security_settings');
        $this->db->affectingStatement('DELETE FROM ip_rules');
        $this->branch = (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => 'SC branch', 'code' => 'SCX-' . bin2hex(random_bytes(2))]);
        $this->store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $this->store);
        $this->router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($this->router);
        $this->router->finalizeNames();
        $this->app->instance(Router::class, $this->router);
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement('DELETE FROM security_settings');
        $this->db->affectingStatement('DELETE FROM ip_rules');
        foreach ($this->settingsSnapshot as $row) {
            $this->db->insertRow('security_settings', $row);
        }
        foreach ($this->rulesSnapshot as $row) {
            $this->db->insertRow('ip_rules', $row);
        }
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM sessions WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM login_attempts WHERE email LIKE 'sc\\_%'");
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->app->get(RateLimiter::class)->clear('rl:search|user:guest');   // the limiter hashes keys like this one
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module = 'security'");
        $this->db->affectingStatement('DELETE FROM branches WHERE code LIKE ?', ['SCX-%']);
    }

    private function user(string $role, ?string $email = null): int
    {
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "SC {$role}", 'email' => $email ?? 'sc_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('right-password'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $this->branch, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $this->branch]);
        $this->userIds[] = $id;

        return $id;
    }

    private function policy(): SecurityPolicy
    {
        return $this->app->get(SecurityPolicy::class);
    }

    private function rules(): IpRules
    {
        return $this->app->get(IpRules::class);
    }

    private function actor(int $id): \App\Models\User
    {
        return $this->app->get(UserRepository::class)->findById($id);
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

        return $this->router->dispatch(new Request([], $post, ['crm_session' => $this->sid], [], [
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

    private function fromIp(string $ip): Request
    {
        return new Request([], [], [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'REMOTE_ADDR' => $ip, 'HTTP_HOST' => 'localhost'], '');
    }

    private function filterStatus(string $ip): int
    {
        $filter = new IpFilter($this->app, $this->app->get(Logger::class));

        return $filter->handle($this->fromIp($ip), static fn (Request $r): Response => Response::html('ok'))->getStatus();
    }

    // ---- IP maths --------------------------------------------------------------------------------------------------

    public function test_addresses_and_ranges_are_parsed_and_normalised(): void
    {
        $one = IpRules::parse('203.0.113.7');
        self::assertSame('203.0.113.7', $one['cidr']);
        self::assertSame(32, $one['prefix']);
        self::assertSame(IpRules::pack('203.0.113.7'), $one['from']);
        self::assertSame($one['from'], $one['to']);

        $net = IpRules::parse('203.0.113.9/24');
        self::assertSame('203.0.113.0/24', $net['cidr'], 'host bits are dropped');
        self::assertSame(IpRules::pack('203.0.113.0'), $net['from']);
        self::assertSame(IpRules::pack('203.0.113.255'), $net['to']);

        $v6 = IpRules::parse('2001:db8:1::5/48');
        self::assertTrue($v6['v6']);
        self::assertSame('2001:db8:1::/48', $v6['cidr']);
        self::assertSame(IpRules::pack('2001:db8:1::'), $v6['from']);
        self::assertSame(IpRules::pack('2001:db8:1:ffff:ffff:ffff:ffff:ffff'), $v6['to']);

        self::assertSame(16, strlen((string) IpRules::pack('1.2.3.4')), 'IPv4 is stored as 16 bytes (::ffff:a.b.c.d)');
        self::assertSame(IpRules::pack('1.2.3.4'), IpRules::pack('::ffff:1.2.3.4'));

        foreach (['', 'abc', '1.2.3', '1.2.3.4/33', '1.2.3.4/-1', '::1/129', '1.2.3.4/', '999.1.1.1', "1.2.3.4\n5.6.7.8", '1.2.3.4/24/1', str_repeat('1', 60), '1.2.3.4; DROP TABLE'] as $bad) {
            self::assertNull(IpRules::parse($bad), var_export($bad, true));
        }
    }

    public function test_a_block_covers_its_range_an_allow_always_wins_and_expired_rules_are_ignored(): void
    {
        $this->rules()->add('block', '203.0.113.0/24', 'noisy', null, null);
        self::assertSame('block', $this->rules()->verdict('203.0.113.77'));
        self::assertSame('none', $this->rules()->verdict('203.0.114.1'));
        self::assertSame('none', $this->rules()->verdict('not an address'));

        $this->rules()->add('allow', '203.0.113.10', 'our office', null, null);
        self::assertSame('allow', $this->rules()->verdict('203.0.113.10'), 'allow beats the wider block');
        self::assertSame('block', $this->rules()->verdict('203.0.113.11'));

        $this->rules()->add('block', '198.51.100.5', 'temporary', 30, null);
        self::assertSame('block', $this->rules()->verdict('198.51.100.5'));
        $this->db->affectingStatement("UPDATE ip_rules SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE cidr = '198.51.100.5'");
        self::assertSame('none', $this->rules()->verdict('198.51.100.5'), 'an expired rule no longer applies');

        $this->rules()->add('block', '2001:db8:abcd::/48', null, null, null);
        self::assertSame('block', $this->rules()->verdict('2001:db8:abcd:1::9'));
        self::assertSame('none', $this->rules()->verdict('2001:db8:abce::1'));
    }

    public function test_adding_the_same_rule_again_updates_it_instead_of_duplicating(): void
    {
        $a = $this->rules()->add('block', '203.0.113.5', 'first', 10, null);
        $b = $this->rules()->add('block', '203.0.113.5/32', 'second', null, null);
        self::assertSame($a, $b);
        self::assertSame(1, $this->rules()->count());
        self::assertSame('second', $this->db->selectValue('SELECT note FROM ip_rules WHERE id = :i', ['i' => $a]));
        self::assertNull($this->db->selectValue('SELECT expires_at FROM ip_rules WHERE id = :i', ['i' => $a]));
    }

    public function test_guard_rails_refuse_huge_ranges_bad_input_and_blocking_yourself(): void
    {
        foreach (['0.0.0.0/0', '10.0.0.0/7', '::/0', '2001:db8::/16'] as $wide) {
            try {
                $this->rules()->add('block', $wide, null, null, null);
                self::fail("{$wide} should be refused");
            } catch (ValidationException $e) {
                self::assertArrayHasKey('cidr', $e->errors());
            }
        }
        $this->rules()->add('allow', '10.0.0.0/8', null, null, null);   // an allow may be wide: it can only let people in

        foreach ([['block', 'nonsense', null], ['maybe', '1.2.3.4', null], ['block', '1.2.3.4', str_repeat('x', 201)]] as [$effect, $ip, $note]) {
            try {
                $this->rules()->add($effect, $ip, $note, null, null);
                self::fail('should be refused');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->rules()->add('block', '1.2.3.4', null, 0, null);
            self::fail('a lifetime of 0 minutes is not valid');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('minutes', $e->errors());
        }

        try {
            $this->rules()->add('block', '192.0.2.0/24', null, null, null, '192.0.2.44');
            self::fail('blocking your own address must be refused');
        } catch (ValidationException $e) {
            self::assertStringContainsString('192.0.2.44', $e->errors()['cidr'][0]);
        }
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM ip_rules WHERE cidr = '192.0.2.0/24'"));

        $this->rules()->add('allow', '192.0.2.44', 'me', null, null);
        $this->rules()->add('block', '192.0.2.0/24', null, null, null, '192.0.2.44');   // fine now: an allow rule covers you
        self::assertSame('allow', $this->rules()->verdict('192.0.2.44'));
    }

    public function test_the_rule_count_is_capped(): void
    {
        $this->db->affectingStatement('DELETE FROM ip_rules');
        $rows = [];
        for ($i = 0; $i < IpRules::MAX_RULES; $i++) {
            $ip = '198.18.' . intdiv($i, 250) . '.' . ($i % 250 + 1);
            $r = IpRules::parse($ip);
            $this->db->insertRow('ip_rules', ['effect' => 'block', 'cidr' => $r['cidr'], 'ip_from' => $r['from'], 'ip_to' => $r['to'], 'source' => 'manual']);
        }
        try {
            $this->rules()->add('block', '203.0.113.99', null, null, null);
            self::fail('the cap must hold');
        } catch (ValidationException $e) {
            self::assertStringContainsString((string) IpRules::MAX_RULES, $e->errors()['cidr'][0]);
        }
        $this->rules()->add('block', '198.18.0.1', 'renewing an existing rule is still allowed', null, null);
        self::assertSame(IpRules::MAX_RULES, $this->rules()->count());
    }

    public function test_removing_and_pruning_and_the_emergency_clear(): void
    {
        $id = $this->rules()->add('block', '203.0.113.5', null, null, null);
        self::assertTrue($this->rules()->remove($id, null));
        self::assertFalse($this->rules()->remove($id, null), 'a rule that is gone cannot be removed twice');
        self::assertSame('none', $this->rules()->verdict('203.0.113.5'));

        $old = $this->rules()->add('block', '203.0.113.6', null, 5, null);
        $this->db->affectingStatement('UPDATE ip_rules SET expires_at = UTC_TIMESTAMP() - INTERVAL 3 DAY WHERE id = :i', ['i' => $old]);
        $fresh = $this->rules()->add('block', '203.0.113.7', null, 5, null);
        self::assertSame(1, $this->rules()->pruneExpired(), 'only rules expired for over a day go');
        self::assertSame(1, $this->rules()->count());

        self::assertSame(1, $this->rules()->clearAll());
        self::assertSame(0, $this->rules()->count());
        self::assertFalse((bool) $this->app->config()->get('security.ip_rules_active'));
        unset($fresh);
    }

    // ---- the request filter ----------------------------------------------------------------------------------------

    public function test_the_filter_is_global_costs_nothing_without_rules_and_turns_blocked_addresses_away(): void
    {
        self::assertContains(IpFilter::class, (new Kernel())->global);

        self::assertSame(200, $this->filterStatus('203.0.113.5'), 'no rules: straight through');

        $this->rules()->add('block', '203.0.113.5', null, null, null);
        self::assertTrue((bool) $this->app->config()->get('security.ip_rules_active'), 'adding a rule switches the check on');
        $blocked = (new IpFilter($this->app, $this->app->get(Logger::class)))->handle($this->fromIp('203.0.113.5'), static fn (Request $r): Response => Response::html('ok'));
        self::assertSame(403, $blocked->getStatus());
        self::assertStringContainsString('no-store', (string) $blocked->getHeader('Cache-Control'));
        self::assertStringNotContainsString('203.0.113.5', $blocked->getBody(), 'the page does not echo the address back');
        self::assertNotNull($this->db->selectValue("SELECT last_blocked_at FROM ip_rules WHERE cidr = '203.0.113.5'"));

        self::assertSame(200, $this->filterStatus('203.0.113.6'), 'other addresses are unaffected');

        $this->rules()->add('allow', '203.0.113.5', null, null, null);
        self::assertSame(200, $this->filterStatus('203.0.113.5'), 'an allow rule wins');
    }

    public function test_the_flag_survives_a_restart_and_the_filter_fails_open_if_the_database_breaks(): void
    {
        $this->rules()->add('block', '203.0.113.5', null, null, null);
        $this->app->config()->set('security.ip_rules_active', false);
        $this->policy()->applyToConfig();   // what boot does
        self::assertTrue((bool) $this->app->config()->get('security.ip_rules_active'));
        self::assertSame(403, $this->filterStatus('203.0.113.5'));

        $this->db->affectingStatement('RENAME TABLE ip_rules TO ip_rules_gone');
        try {
            self::assertSame(200, $this->filterStatus('203.0.113.5'), 'a broken rules table must not lock everybody out');
        } finally {
            $this->db->affectingStatement('RENAME TABLE ip_rules_gone TO ip_rules');
        }
    }

    // ---- automatic block -------------------------------------------------------------------------------------------

    private function failSignIn(string $email, string $ip): void
    {
        try {
            $this->app->get(AuthService::class)->attempt($email, 'wrong-password', $this->fromIp($ip));
        } catch (ValidationException|HttpException) {
        }
    }

    public function test_repeated_failed_sign_ins_block_the_address_for_a_while_and_an_allow_rule_prevents_it(): void
    {
        $email = 'sc_' . bin2hex(random_bytes(3)) . '@dev.local';
        $this->user('counselor', $email);

        // off by default
        for ($i = 0; $i < 4; $i++) {
            $this->failSignIn('sc_nobody@dev.local', '198.51.100.20');
        }
        self::assertSame(0, $this->rules()->count(), 'the automatic block is off until the super admin sets a threshold');

        $this->policy()->saveAutoBlock(5, 30, null);
        $this->policy()->applyToConfig();
        for ($i = 0; $i < 4; $i++) {
            $this->failSignIn($email, '198.51.100.21');
        }
        self::assertSame('none', $this->rules()->verdict('198.51.100.21'), 'still below the threshold');
        $this->failSignIn($email, '198.51.100.21');
        self::assertSame('block', $this->rules()->verdict('198.51.100.21'));
        $row = $this->db->selectOne("SELECT source, expires_at, note FROM ip_rules WHERE cidr = '198.51.100.21'");
        self::assertSame('auto', $row['source']);
        self::assertNotNull($row['expires_at']);
        self::assertGreaterThan(time() + 25 * 60, strtotime($row['expires_at'] . ' UTC'));
        self::assertStringContainsString('failed sign-ins', (string) $row['note']);
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE action = 'ip_auto_blocked' AND module = 'security'"));

        $this->rules()->add('allow', '198.51.100.22', 'trusted office', null, null);
        for ($i = 0; $i < 8; $i++) {
            $this->failSignIn($email, '198.51.100.22');
        }
        self::assertSame('allow', $this->rules()->verdict('198.51.100.22'), 'an allowed address is never auto-blocked');
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM ip_rules WHERE effect = 'block' AND cidr = '198.51.100.22'"));
    }

    // ---- rate limits -----------------------------------------------------------------------------------------------

    public function test_a_saved_rate_limit_is_what_the_limiter_enforces(): void
    {
        $rows = array_column($this->policy()->rateLimits(), null, 'bucket');
        self::assertSame(30, $rows['search']['limit']);
        self::assertFalse($rows['search']['custom']);
        self::assertCount(count((array) config('rate_limits.buckets')), $rows, 'every bucket in config/rate_limits.php is listed');
        foreach ($rows as $bucket => $r) {
            self::assertNotSame($bucket, $r['label'], "{$bucket} has a readable label");
            self::assertNotSame('', $r['help'], "{$bucket} has help text");
        }

        $this->app->get(RateLimiter::class)->clear('rl:search|user:guest');
        $changed = $this->policy()->saveRateLimits(['search' => ['limit' => '2', 'window' => '60']], null);
        self::assertSame(['search'], $changed);
        $rows = array_column($this->policy()->rateLimits(), null, 'bucket');
        self::assertSame(2, $rows['search']['limit']);
        self::assertTrue($rows['search']['custom']);
        self::assertSame(30, $rows['search']['default_limit'], 'the default is still shown');

        $this->policy()->applyToConfig();
        self::assertSame(2, (int) $this->app->config()->get('rate_limits.buckets.search.limit'));

        $mw = new RateLimit($this->app, $this->app->get(RateLimiter::class), ['search']);
        $ok = static fn (Request $r): Response => Response::html('ok');
        $req = $this->fromIp('127.0.0.1');
        self::assertSame('1', $mw->handle($req, $ok)->getHeader('X-RateLimit-Remaining'));
        self::assertSame('0', $mw->handle($req, $ok)->getHeader('X-RateLimit-Remaining'));
        try {
            $mw->handle($req, $ok);
            self::fail('the third call must be limited');
        } catch (HttpException $e) {
            self::assertSame(429, $e->getStatusCode());
        }
    }

    public function test_saving_the_default_removes_the_override_and_nothing_is_reported_changed(): void
    {
        $this->policy()->saveRateLimits(['write' => ['limit' => '50', 'window' => '30']], null);
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM security_settings WHERE name = 'rate.write'"));
        self::assertSame([], $this->policy()->saveRateLimits(['write' => ['limit' => '50', 'window' => '30']], null), 'the same values again change nothing');

        self::assertSame(['write'], $this->policy()->saveRateLimits(['write' => ['limit' => '120', 'window' => '60']], null), 'back to the default values');
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM security_settings WHERE name = 'rate.write'"), 'no row is kept for a default');
        self::assertFalse(array_column($this->policy()->rateLimits(), null, 'bucket')['write']['custom']);
    }

    public function test_rate_limit_input_is_validated_all_or_nothing_and_protected_buckets_cannot_be_opened_wide(): void
    {
        $bad = [
            ['search' => ['limit' => '0', 'window' => '60']],
            ['search' => ['limit' => '-5', 'window' => '60']],
            ['search' => ['limit' => 'many', 'window' => '60']],
            ['search' => ['limit' => '100001', 'window' => '60']],
            ['search' => ['limit' => '10', 'window' => '5']],
            ['search' => ['limit' => '10', 'window' => '86401']],
            ['search' => ['limit' => '', 'window' => '']],
            ['login' => ['limit' => '11', 'window' => '900']],           // default 5 → at most 10
            ['login' => ['limit' => '5', 'window' => '100']],            // window may not shrink below half
            ['password_reset' => ['limit' => '7', 'window' => '3600']],
        ];
        foreach ($bad as $input) {
            try {
                $this->policy()->saveRateLimits($input, null);
                self::fail('should be refused: ' . json_encode($input));
            } catch (ValidationException $e) {
                self::assertNotSame([], $e->errors());
            }
        }

        // one bad row stops the whole save
        try {
            $this->policy()->saveRateLimits(['search' => ['limit' => '5', 'window' => '60'], 'write' => ['limit' => '0', 'window' => '60']], null);
            self::fail();
        } catch (ValidationException) {
        }
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM security_settings'));

        // tightening is always fine, doubling a protected one is fine, unknown buckets are ignored
        self::assertSame(['login', 'two_factor'], $this->policy()->saveRateLimits(['login' => ['limit' => '10', 'window' => '450'], 'two_factor' => ['limit' => '2', 'window' => '3600'], 'nonsense' => ['limit' => '1', 'window' => '60']], null));
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM security_settings WHERE name = 'rate.nonsense'"));
    }

    public function test_resetting_one_or_all_limits(): void
    {
        $this->policy()->saveRateLimits(['search' => ['limit' => '9', 'window' => '60'], 'export' => ['limit' => '3', 'window' => '3600']], null);
        self::assertSame(1, $this->policy()->resetRateLimits('search', null));
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM security_settings WHERE name LIKE 'rate.%'"));
        self::assertSame(1, $this->policy()->resetRateLimits(null, null));
        self::assertSame(0, $this->policy()->resetRateLimits(null, null));
        self::assertSame(0, $this->policy()->resetRateLimits('nonsense', null));
    }

    // ---- two-factor policy + auto-block settings -------------------------------------------------------------------

    public function test_the_two_factor_policy_is_saved_validated_and_read_by_the_application(): void
    {
        $this->user('accounts');
        $this->user('accounts');
        $this->policy()->saveTwoFactor(['accounts', 'manager', 'accounts'], '2', null);

        $tf = $this->policy()->twoFactor();
        self::assertSame(['accounts', 'manager'], $tf['roles']);
        self::assertSame(2, $tf['grace']);
        self::assertSame('panel', $tf['source']);

        $this->policy()->applyToConfig();
        self::assertSame(['accounts', 'manager'], $this->app->config()->get('auth.two_factor.required_roles'));
        self::assertSame(2, (int) $this->app->config()->get('auth.two_factor.grace_logins'));

        self::assertGreaterThanOrEqual(2, $this->policy()->twoFactorGaps()['accounts'], 'people who have not enrolled are counted');

        $this->policy()->saveTwoFactor([], '0', null);
        $this->policy()->applyToConfig();
        self::assertSame([], $this->app->config()->get('auth.two_factor.required_roles'), 'the panel can also switch the requirement off');

        foreach ([[['ghost_role'], '3'], [['accounts'], 'x'], [['accounts'], '31'], [['accounts'], '-1']] as [$roles, $grace]) {
            try {
                $this->policy()->saveTwoFactor($roles, $grace, null);
                self::fail('should be refused');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(2, (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE action = 'two_factor_policy_changed' AND module = 'security'"), 'the two successful saves are audited; the refused ones are not');
    }

    public function test_the_automatic_block_settings_are_validated(): void
    {
        self::assertSame(['threshold' => 0, 'minutes' => 60], $this->policy()->autoBlock());
        foreach ([['4', '60'], ['1001', '60'], ['abc', '60'], ['10', '4'], ['10', '10081'], ['10', '']] as [$t, $m]) {
            try {
                $this->policy()->saveAutoBlock($t, $m, null);
                self::fail("{$t}/{$m} should be refused");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $this->policy()->saveAutoBlock('20', '120', null);
        self::assertSame(['threshold' => 20, 'minutes' => 120], $this->policy()->autoBlock());
        $this->policy()->saveAutoBlock('0', '120', null);   // 0 = off
        self::assertSame(0, $this->policy()->autoBlock()['threshold']);
    }

    // ---- who may do it ---------------------------------------------------------------------------------------------

    public function test_only_the_super_admin_holds_the_security_permissions_by_default(): void
    {
        $roles = $this->app->get(RoleAdminService::class);
        foreach (['admin', 'manager', 'accounts', 'counselor'] as $role) {
            self::assertNotContains('security.view', $roles->defaultsFor($role), $role);
            self::assertNotContains('security.manage', $roles->defaultsFor($role), $role);
        }
        self::assertSame(2, (int) $this->db->selectValue("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id JOIN roles r ON r.id = rp.role_id WHERE r.name = 'super_admin' AND p.name LIKE 'security.%'"));
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id JOIN roles r ON r.id = rp.role_id WHERE r.name <> 'super_admin' AND p.name LIKE 'security.%'"));

        $this->actAs($this->user('admin'));
        foreach (['/admin/security', '/admin/security/rate-limits', '/admin/security/policy', '/admin/security/ip-rules', '/admin/security/sessions'] as $url) {
            self::assertSame(403, $this->code('GET', $url), $url);
        }
        self::assertSame(403, $this->code('PUT', '/admin/security/rate-limits', ['_method' => 'PUT', 'rl' => ['search' => ['limit' => '1', 'window' => '60']]]));
        self::assertSame(403, $this->code('POST', '/admin/security/ip-rules', ['effect' => 'block', 'cidr' => '203.0.113.5']));
        self::assertSame(403, $this->code('POST', '/admin/security/sessions/sign-out-others', ['x' => '1']));
        self::assertSame(0, $this->rules()->count());
    }

    public function test_every_page_renders_for_the_super_admin_and_is_never_cached(): void
    {
        $this->rules()->add('block', '203.0.113.5', 'noisy', null, null);
        $this->db->insertRow('login_attempts', ['email' => 'sc_probe@dev.local', 'ip_address' => inet_pton('203.0.113.5'), 'successful' => 0]);
        $this->actAs($this->user('super_admin'));

        foreach (['/admin/security', '/admin/security/rate-limits', '/admin/security/policy', '/admin/security/ip-rules', '/admin/security/sessions'] as $url) {
            $res = $this->send('GET', $url);
            self::assertSame(200, $res->getStatus(), $url);
            self::assertStringContainsString('no-store', (string) $res->getHeader('Cache-Control'), $url);
            self::assertStringContainsString('Security sections', $res->getBody(), $url);
        }
        $overview = $this->send('GET', '/admin/security')->getBody();
        self::assertStringContainsString('sc_probe@dev.local', $overview);
        self::assertStringContainsString('203.0.113.5', $overview);
        self::assertStringContainsString('Block 24 h', $overview);
        self::assertStringContainsString('noisy', $this->send('GET', '/admin/security/ip-rules')->getBody());
        self::assertStringContainsString('Sign-in attempts', $this->send('GET', '/admin/security/rate-limits')->getBody());
        self::assertStringContainsString('name="rl[login][limit]"', $this->send('GET', '/admin/security/rate-limits')->getBody());
    }

    // ---- changes over HTTP -----------------------------------------------------------------------------------------

    public function test_the_writes_need_a_fresh_password_confirmation(): void
    {
        $this->actAs($this->user('super_admin'), confirmed: false);
        $res = $this->send('PUT', '/admin/security/rate-limits', ['_method' => 'PUT', 'rl' => ['search' => ['limit' => '1', 'window' => '60']]]);
        self::assertSame(302, $res->getStatus());
        self::assertStringContainsString('confirm', (string) $res->getHeader('Location'));
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM security_settings'));

        $res = $this->send('POST', '/admin/security/ip-rules', ['effect' => 'block', 'cidr' => '203.0.113.5']);
        self::assertStringContainsString('confirm', (string) $res->getHeader('Location'));
        self::assertSame(0, $this->rules()->count());
    }

    public function test_rate_limits_policy_and_ip_rules_can_be_changed_from_the_pages_and_are_audited(): void
    {
        $this->actAs($this->user('super_admin'));

        $res = $this->send('PUT', '/admin/security/rate-limits', ['_method' => 'PUT', 'rl' => ['export' => ['limit' => '4', 'window' => '3600'], 'login' => ['limit' => '5', 'window' => '900']]]);
        self::assertSame('/admin/security/rate-limits', $res->getHeader('Location'));
        self::assertSame(4, array_column($this->policy()->rateLimits(), null, 'bucket')['export']['limit']);

        $res = $this->send('PUT', '/admin/security/rate-limits', ['_method' => 'PUT', 'rl' => ['login' => ['limit' => '99', 'window' => '900']]]);
        self::assertSame('/admin/security/rate-limits', $res->getHeader('Location'));
        self::assertSame(5, array_column($this->policy()->rateLimits(), null, 'bucket')['login']['limit'], 'a refused value is not saved');

        $this->send('POST', '/admin/security/rate-limits/reset', ['x' => '1']);
        self::assertFalse(array_column($this->policy()->rateLimits(), null, 'bucket')['export']['custom']);

        $this->send('PUT', '/admin/security/policy', ['_method' => 'PUT', 'roles' => ['accounts'], 'grace' => '1', 'threshold' => '10', 'minutes' => '45']);
        self::assertSame(['accounts'], $this->policy()->twoFactor()['roles']);
        self::assertSame(['threshold' => 10, 'minutes' => 45], $this->policy()->autoBlock());
        $this->send('PUT', '/admin/security/policy', ['_method' => 'PUT', 'roles' => ['ghost'], 'grace' => '1', 'threshold' => '10', 'minutes' => '45']);
        self::assertSame(['accounts'], $this->policy()->twoFactor()['roles'], 'an invalid save changes nothing');

        $res = $this->send('POST', '/admin/security/ip-rules', ['effect' => 'block', 'cidr' => '203.0.113.0/24', 'note' => 'scanner', 'minutes' => '60']);
        self::assertSame('/admin/security/ip-rules', $res->getHeader('Location'));
        self::assertSame('block', $this->rules()->verdict('203.0.113.9'));
        $mine = $this->send('POST', '/admin/security/ip-rules', ['effect' => 'block', 'cidr' => '127.0.0.0/24']);
        self::assertSame('/admin/security/ip-rules', $mine->getHeader('Location'));
        self::assertSame('none', $this->rules()->verdict('127.0.0.1'), 'the guard stops you blocking the address you are using');
        $this->send('POST', '/admin/security/ip-rules', ['effect' => 'block', 'cidr' => 'garbage']);
        self::assertSame(1, $this->rules()->count());

        $id = (int) $this->db->selectValue("SELECT id FROM ip_rules WHERE cidr = '203.0.113.0/24'");
        self::assertSame(404, $this->code('POST', '/admin/security/ip-rules/999999/remove', ['x' => '1']));
        self::assertSame(404, $this->code('POST', '/admin/security/ip-rules/abc/remove', ['x' => '1']));
        $this->send('POST', "/admin/security/ip-rules/{$id}/remove", ['x' => '1']);
        self::assertSame(0, $this->rules()->count());

        foreach (['rate_limits_changed', 'rate_limits_reset', 'two_factor_policy_changed', 'autoblock_changed', 'ip_rule_added', 'ip_rule_removed'] as $action) {
            self::assertGreaterThanOrEqual(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM activity_logs WHERE module = :m AND action = :a', ['m' => 'security', 'a' => $action]), $action);
        }
    }

    // ---- sessions --------------------------------------------------------------------------------------------------

    private function session(int $userId, string $ip = '203.0.113.50'): string
    {
        $id = bin2hex(random_bytes(32));
        $this->db->insertRow('sessions', ['id' => $id, 'user_id' => $userId, 'ip_address' => inet_pton($ip), 'user_agent' => 'Test Browser/1.0', 'payload' => '', 'last_activity' => time()]);

        return $id;
    }

    public function test_the_sessions_page_lists_people_and_lets_the_super_admin_end_sessions(): void
    {
        $me = $this->user('super_admin');
        $other = $this->user('counselor');
        $third = $this->user('accounts');
        $mine = $this->session($me, '127.0.0.1');
        $s1 = $this->session($other);
        $s2 = $this->session($other, '203.0.113.51');
        $s3 = $this->session($third);
        $stale = bin2hex(random_bytes(32));
        $this->db->insertRow('sessions', ['id' => $stale, 'user_id' => $third, 'ip_address' => inet_pton('203.0.113.52'), 'user_agent' => 'Old', 'payload' => '', 'last_activity' => time() - 90 * 86400]);
        $this->actAs($me);
        $this->store->sessions[$mine] = $this->store->sessions[$this->sid];   // "this device"
        $this->sid = $mine;

        $page = $this->send('GET', '/admin/security/sessions')->getBody();
        self::assertStringContainsString('203.0.113.50', $page);
        self::assertStringContainsString('203.0.113.51', $page);
        self::assertStringNotContainsString('203.0.113.52', $page, 'a session past the lifetime is not "signed in"');
        self::assertStringContainsString('Test Browser/1.0', $page);

        // end one session
        $this->send('POST', '/admin/security/sessions/revoke', ['id' => $s1]);
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM sessions WHERE id = :i', ['i' => $s1]));
        self::assertSame(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM sessions WHERE id = :i', ['i' => $s2]));

        // never one's own, never a malformed or unknown id
        $this->send('POST', '/admin/security/sessions/revoke', ['id' => $mine]);
        self::assertSame(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM sessions WHERE id = :i', ['i' => $mine]));
        $this->send('POST', '/admin/security/sessions/revoke', ['id' => "x' OR '1'='1"]);
        $this->send('POST', '/admin/security/sessions/revoke', ['id' => str_repeat('a', 64)]);

        // sign a person out everywhere
        $publicId = (string) $this->db->selectValue('SELECT public_id FROM users WHERE id = :i', ['i' => $other]);
        $this->send('POST', "/admin/security/users/{$publicId}/sign-out", ['x' => '1']);
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM sessions WHERE user_id = :u', ['u' => $other]));
        self::assertSame(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM sessions WHERE id = :i', ['i' => $s3]), 'other people are untouched');
        self::assertSame(302, $this->code('POST', '/admin/security/users/NOSUCHUSER0000000000000000/sign-out', ['x' => '1']), 'an unknown person redirects back with a message');

        // everyone else
        $this->send('POST', '/admin/security/sessions/sign-out-others', ['x' => '1']);
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM sessions WHERE id = :i', ['i' => $s3]));
        self::assertSame(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM sessions WHERE id = :i', ['i' => $mine]), 'your own session stays');

        foreach (['session_revoked', 'user_signed_out', 'all_sessions_revoked'] as $action) {
            self::assertGreaterThanOrEqual(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM activity_logs WHERE action = :a AND module IN (:m1, :m2)', ['a' => $action, 'm1' => 'security', 'm2' => 'users']), $action);
        }
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE action = 'user_signed_out' AND module = 'users'");
    }

    public function test_signing_out_a_super_admin_is_governed_by_the_same_rules_as_the_users_page(): void
    {
        $admin = $this->user('super_admin');
        $peer = $this->user('super_admin');
        $this->session($peer);
        $this->actAs($admin);
        $publicId = (string) $this->db->selectValue('SELECT public_id FROM users WHERE id = :i', ['i' => $peer]);
        $this->send('POST', "/admin/security/users/{$publicId}/sign-out", ['x' => '1']);
        // whichever way the users-page rules decide, the response is a redirect back and nothing crashes
        self::assertContains($this->code('POST', "/admin/security/users/{$publicId}/sign-out", ['x' => '1']), [302]);
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE action = 'user_signed_out' AND module = 'users'");
    }
}
