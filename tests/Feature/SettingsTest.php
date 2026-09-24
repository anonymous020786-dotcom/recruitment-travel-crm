<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Auth;
use App\Auth\Gate;
use App\Auth\PermissionService;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\SettingsService;
use App\Session\ArraySessionStore;
use App\Session\SessionStore;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

/** Admin → Settings: the registry, validation, defaults, audit, and the public pages that read the values. */
final class SettingsTest extends DbTestCase
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
    /** @var list<array<string,mixed>> the real rows for our keys, put back afterwards */
    private array $snapshot = [];

    private const KEYS_LIKE = ['business.%', 'finance.reminder_max_repeats'];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->snapshot = $this->db->select("SELECT * FROM settings WHERE key_name LIKE 'business.%' OR key_name = 'finance.reminder_max_repeats'");
        $this->wipeKeys();
        $this->branch = (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => 'ST branch', 'code' => 'STX-' . bin2hex(random_bytes(2))]);
        $this->store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $this->store);
        $this->router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($this->router);
        $this->router->finalizeNames();
        $this->app->instance(Router::class, $this->router);
    }

    protected function tearDown(): void
    {
        $this->wipeKeys();
        foreach ($this->snapshot as $row) {
            $this->db->insertRow('settings', $row);
        }
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM sessions WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module = 'settings' AND action = 'settings_updated'");
        $this->db->affectingStatement('DELETE FROM branches WHERE code LIKE ?', ['STX-%']);
    }

    private function wipeKeys(): void
    {
        $this->db->affectingStatement("DELETE FROM settings WHERE key_name LIKE 'business.%' OR key_name = 'finance.reminder_max_repeats'");
    }

    private function user(string $role): int
    {
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "ST {$role}", 'email' => 'st_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $this->branch, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $this->branch]);
        $this->userIds[] = $id;

        return $id;
    }

    private function model(int $id): User
    {
        return $this->app->get(UserRepository::class)->findById($id);
    }

    private function service(): SettingsService
    {
        return $this->app->get(SettingsService::class);
    }

    private function stored(string $key): mixed
    {
        $raw = $this->db->selectValue('SELECT value FROM settings WHERE key_name = ?', [$key]);

        return $raw === null ? null : json_decode((string) $raw, true);
    }

    // ---- reading ----------------------------------------------------------------------------------

    public function test_a_field_falls_back_to_its_default_and_unknown_keys_are_refused(): void
    {
        self::assertSame(config('seo.organization_name'), $this->service()->get('business.name'));
        self::assertNull($this->service()->get('business.phone'));
        self::assertSame('none', $this->service()->get('business.phone', 'none'));
        self::assertSame((int) config('finance.reminder_max_repeats'), $this->service()->get('finance.reminder_max_repeats'));

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->get('app.key');
    }

    public function test_a_stored_value_of_the_wrong_shape_is_ignored_rather_than_breaking_a_page(): void
    {
        $this->db->insertRow('settings', ['key_name' => 'business.phone', 'value' => json_encode(['x' => 1]), 'is_public' => 1]);
        $this->db->insertRow('settings', ['key_name' => 'finance.reminder_max_repeats', 'value' => json_encode('lots'), 'is_public' => 0]);

        $fresh = new SettingsService($this->app->get(\App\Repositories\SettingsRepository::class), $this->app->get(\App\Audit\AuditService::class), $this->app, $this->db);

        self::assertNull($fresh->get('business.phone'));
        self::assertSame((int) config('finance.reminder_max_repeats'), $fresh->get('finance.reminder_max_repeats'));
    }

    // ---- writing ----------------------------------------------------------------------------------

    public function test_saving_stores_audits_only_what_changed_and_marks_business_fields_public(): void
    {
        $actor = $this->model($this->user('admin'));

        $changed = $this->service()->update([
            'business.name' => '  Acme Overseas  ', 'business.phone' => '+91 98765 43210', 'business.email' => 'Hello@Acme.EXAMPLE',
            'business.address' => "1 Main Road\nMumbai", 'finance.reminder_max_repeats' => '3', 'business.hours' => '',
        ], $actor);

        self::assertEqualsCanonicalizing(['business.name', 'business.phone', 'business.email', 'business.address', 'finance.reminder_max_repeats'], $changed);
        self::assertSame('Acme Overseas', $this->service()->get('business.name'));
        self::assertSame('hello@acme.example', $this->service()->get('business.email'));
        self::assertSame(3, $this->service()->get('finance.reminder_max_repeats'));
        self::assertSame(1, (int) $this->db->selectValue("SELECT is_public FROM settings WHERE key_name = 'business.phone'"));
        self::assertSame(0, (int) $this->db->selectValue("SELECT is_public FROM settings WHERE key_name = 'finance.reminder_max_repeats'"));
        self::assertNull($this->stored('business.hours'));

        $log = (string) $this->db->selectValue("SELECT new_values FROM activity_logs WHERE action = 'settings_updated' ORDER BY id DESC LIMIT 1");
        self::assertStringContainsString('business.phone', $log);
        self::assertStringNotContainsString('business.hours', $log, 'an unchanged field is not part of the audit row');

        // Saving the very same values changes nothing and writes no second audit row.
        $rows = (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE action = 'settings_updated'");
        self::assertSame([], $this->service()->update(['business.name' => 'Acme Overseas', 'business.phone' => '+91 98765 43210'], $actor));
        self::assertSame($rows, (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE action = 'settings_updated'"));
    }

    public function test_emptying_a_field_returns_it_to_its_default(): void
    {
        $actor = $this->model($this->user('admin'));
        $this->service()->update(['business.name' => 'Acme', 'business.phone' => '+91 98765 43210'], $actor);

        $changed = $this->service()->update(['business.name' => '   ', 'business.phone' => ''], $actor);

        self::assertEqualsCanonicalizing(['business.name', 'business.phone'], $changed);
        self::assertSame(config('seo.organization_name'), $this->service()->get('business.name'));
        self::assertNull($this->service()->get('business.phone'));
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM settings WHERE key_name IN ('business.name', 'business.phone')"));
    }

    public function test_invalid_input_is_rejected_field_by_field_and_nothing_is_saved(): void
    {
        $actor = $this->model($this->user('admin'));

        foreach ([
            'business.phone'  => ['call me maybe', '12', str_repeat('9', 31)],
            'business.email'  => ['not-an-email', 'a@b', "x@y.example\nBcc: z@y.example"],
            'business.name'   => [str_repeat('n', 121), "two\nlines", "bell\x07"],
            'business.address' => [str_repeat('a', 401)],
            'finance.reminder_max_repeats' => ['-1', '21', 'many', '2.5'],
        ] as $key => $bad) {
            foreach ($bad as $value) {
                try {
                    $this->service()->update(['business.hours' => 'Should not stick', $key => $value], $actor);
                    self::fail("accepted invalid {$key}: " . json_encode($value));
                } catch (ValidationException $e) {
                    self::assertArrayHasKey($key, $e->errors());
                    self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM settings WHERE key_name = 'business.hours'"), 'a rejected save is all-or-nothing');
                }
            }
        }
    }

    public function test_only_declared_keys_are_touched(): void
    {
        $actor = $this->model($this->user('admin'));
        $this->db->insertRow('settings', ['key_name' => 'business.zzz_other', 'value' => json_encode('keep'), 'is_public' => 0]);

        $this->service()->update(['business.zzz_other' => 'hacked', 'dashboard.anything' => 'x', 'app.key' => 'x', 'business.name' => 'Acme'], $actor);

        self::assertSame('keep', $this->stored('business.zzz_other'));
        self::assertNull($this->stored('app.key'));
        self::assertNull($this->stored('dashboard.anything'));
    }

    // ---- the screens and the public site ---------------------------------------------------------------

    private function actAs(?int $userId): void
    {
        $this->sid = bin2hex(random_bytes(32));
        $this->token = bin2hex(random_bytes(32));
        $this->store->sessions[$this->sid] = ['data' => ($userId !== null ? ['_auth_user_id' => $userId, '_auth_at' => time(), '_authenticated_at' => time()] : []) + ['_token' => $this->token, '_started_at' => time(), '_last_regen' => time(), '_last_activity' => time()], 'touched' => time()];
        $auth = new Auth($this->app, new UserRepository($this->db));
        $this->app->instance(Auth::class, $auth);
        $this->app->instance(Gate::class, new Gate($this->app, $this->app->get(PermissionService::class), $auth));
    }

    private function send(string $method, string $uri, array $post = []): Response
    {
        if ($post !== [] && !isset($post['_token'])) {
            $post['_token'] = $this->token;
        }

        return $this->router->dispatch(new Request([], $post, ['crm_session' => $this->sid], [], [
            'REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'http://localhost',
        ], ''));
    }

    private function code(string $method, string $uri, array $post = []): int
    {
        try {
            return $this->send($method, $uri, $post)->getStatus();
        } catch (\App\Exceptions\HttpException $e) {
            return $e->getStatusCode();
        } catch (\App\Exceptions\AuthorizationException) {
            return 403;
        }
    }

    public function test_who_can_see_and_who_can_change_settings(): void
    {
        $this->actAs($this->user('counselor'));
        self::assertSame(403, $this->code('GET', '/admin/settings'));
        self::assertSame(403, $this->code('PUT', '/admin/settings', ['_method' => 'PUT', 's' => ['business.name' => 'X']]));

        $this->actAs($this->user('manager'));   // may view, may not change
        self::assertSame(200, $this->code('GET', '/admin/settings'));
        self::assertSame(403, $this->code('PUT', '/admin/settings', ['_method' => 'PUT', 's' => ['business.name' => 'X']]));
        self::assertNull($this->stored('business.name'));

        $this->actAs($this->user('admin'));
        self::assertSame(200, $this->code('GET', '/admin/settings'));
    }

    public function test_saving_through_the_screen_and_a_validation_error_round_trip(): void
    {
        $this->actAs($this->user('admin'));

        $res = $this->send('PUT', '/admin/settings', ['_method' => 'PUT', 's' => ['business.phone' => '+91 98765 43210', 'business.name' => 'Acme']]);
        self::assertSame(302, $res->getStatus());
        self::assertSame('+91 98765 43210', $this->stored('business.phone'));
        self::assertStringContainsString('value="+91 98765 43210"', $this->send('GET', '/admin/settings')->getBody());

        $res = $this->send('PUT', '/admin/settings', ['_method' => 'PUT', 's' => ['business.phone' => 'nope', 'business.name' => 'Changed']]);
        self::assertSame(302, $res->getStatus());
        self::assertSame('+91 98765 43210', $this->stored('business.phone'));
        self::assertSame('Acme', $this->stored('business.name'));
        $page = $this->send('GET', '/admin/settings')->getBody();
        self::assertStringContainsString('must be 7–30 digits', $page);
        self::assertStringContainsString('value="nope"', $page, 'the form keeps what was typed');
    }

    public function test_the_public_site_shows_the_business_profile(): void
    {
        $this->actAs($this->user('admin'));
        $this->send('PUT', '/admin/settings', ['_method' => 'PUT', 's' => [
            'business.name' => 'Acme Overseas', 'business.phone' => '+91 98765 43210', 'business.whatsapp' => '+91 91234 56789',
            'business.email' => 'hello@acme.example', 'business.address' => "1 <b>Main</b> Road\nMumbai", 'business.hours' => 'Mon–Sat 10–6',
        ]]);

        $this->actAs(null);
        $contact = $this->send('GET', '/contact')->getBody();
        self::assertStringContainsString('href="tel:+919876543210"', $contact);
        self::assertStringContainsString('href="https://wa.me/919123456789"', $contact);
        self::assertStringContainsString('href="mailto:hello@acme.example"', $contact);
        self::assertStringContainsString('Mon–Sat 10–6', $contact);
        self::assertStringContainsString('1 &lt;b&gt;Main&lt;/b&gt; Road', $contact, 'the address is escaped');
        self::assertStringNotContainsString('<b>Main</b>', $contact);

        $home = $this->send('GET', '/about')->getBody();
        self::assertStringContainsString('Acme Overseas', $home);
        self::assertStringContainsString('href="tel:+919876543210"', $home, 'the footer carries the phone');
        self::assertMatchesRegularExpression('/"telephone":"\+91 98765 43210".*"email":"hello@acme.example"/s', $home, 'search engines get the organisation details');
    }

    public function test_an_empty_profile_shows_no_empty_contact_block(): void
    {
        $this->actAs(null);
        $contact = $this->send('GET', '/contact')->getBody();

        self::assertStringNotContainsString('href="tel:', $contact);
        self::assertStringNotContainsString('wa.me', $contact);
        self::assertStringNotContainsString('<dl', $contact);
    }
}
