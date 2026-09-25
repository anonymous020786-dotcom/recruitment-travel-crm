<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Auth;
use App\Auth\Gate;
use App\Auth\PermissionService;
use App\Exceptions\AuthorizationException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Integrations\Credentials;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\RoleAdminService;
use App\Session\ArraySessionStore;
use App\Session\SessionStore;
use App\Support\Encryptor;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

/** Admin → Integrations: the catalogue, encrypted write-only secrets, precedence, config overlay, audit, and access. */
final class IntegrationCredentialsTest extends DbTestCase
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
    /** @var list<array<string,mixed>> the real saved credentials, put back afterwards */
    private array $snapshot = [];

    private const SECRET = 'sk_test_51Habcdefghijklmnop9876';

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->snapshot = $this->db->select('SELECT * FROM integration_credentials');
        $this->db->affectingStatement('DELETE FROM integration_credentials');
        $this->branch = (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => 'IC branch', 'code' => 'ICX-' . bin2hex(random_bytes(2))]);
        $this->store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $this->store);
        $this->router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($this->router);
        $this->router->finalizeNames();
        $this->app->instance(Router::class, $this->router);
    }

    protected function tearDown(): void
    {
        putenv('INTEGRATIONS_WHATSAPP_NUMBER');
        $this->db->affectingStatement('DELETE FROM integration_credentials');
        foreach ($this->snapshot as $row) {
            $this->db->insertRow('integration_credentials', $row);
        }
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM sessions WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module = 'integrations' AND action LIKE 'integration\\_%'");
        $this->db->affectingStatement('DELETE FROM branches WHERE code LIKE ?', ['ICX-%']);
    }

    private function user(string $role): int
    {
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "IC {$role}", 'email' => 'ic_' . bin2hex(random_bytes(4)) . '@dev.local',
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

    private function creds(): Credentials
    {
        return $this->app->get(Credentials::class);
    }

    private function stripe(array $over = []): array
    {
        return $over + ['publishable_key' => 'pk_test_abc', 'secret_key' => self::SECRET, 'webhook_secret' => 'whsec_1234567890abcdef'];
    }

    // ---- the catalogue ---------------------------------------------------------------------------------------------

    public function test_the_registry_is_well_formed_and_covers_the_promised_services(): void
    {
        $services = $this->creds()->services();
        $groups = $this->creds()->groups();
        $configPaths = [];
        $envNames = [];

        foreach ($services as $key => $def) {
            self::assertMatchesRegularExpression('/^[a-z0-9]{2,20}$/', $key);
            self::assertNotSame('', (string) $def['label'], $key);
            self::assertArrayHasKey($def['group'], $groups, "{$key} names a group that exists");
            self::assertNotEmpty($def['fields'], $key);
            foreach ($def['fields'] as $name => $f) {
                self::assertMatchesRegularExpression('/^[a-z0-9_]{2,40}$/', $name);
                self::assertNotSame(Credentials::ENABLED, $name);
                self::assertContains($f['type'], ['text', 'secret', 'select', 'number', 'url'], "{$key}.{$name}");
                if ($f['type'] === 'select') {
                    self::assertNotEmpty($f['options'], "{$key}.{$name} needs options");
                }
                foreach (['config' => &$configPaths, 'env' => &$envNames] as $attr => &$seen) {
                    if (!empty($f[$attr])) {
                        self::assertNotContains($f[$attr], $seen, "{$attr} {$f[$attr]} is used twice");
                        $seen[] = $f[$attr];
                    }
                }
                unset($seen);
            }
        }

        // six Indian gateways, two international, two storage providers, plus the tools already in use
        foreach (['razorpay', 'payu', 'cashfree', 'phonepe', 'ccavenue', 'paytm'] as $in) {
            self::assertSame('payments_in', $services[$in]['group'], $in);
        }
        foreach (['stripe', 'paypal'] as $intl) {
            self::assertSame('payments_intl', $services[$intl]['group'], $intl);
        }
        foreach (['s3', 'r2'] as $store) {
            self::assertSame('storage', $services[$store]['group'], $store);
        }
        foreach (['smtp', 'turnstile', 'ga4', 'tawk', 'whatsapp'] as $tool) {
            self::assertArrayHasKey($tool, $services);
        }
        // every secret-bearing gateway field is typed as a secret
        foreach ([['razorpay', 'key_secret'], ['razorpay', 'webhook_secret'], ['payu', 'merchant_salt'], ['cashfree', 'secret_key'], ['phonepe', 'salt_key'],
            ['ccavenue', 'working_key'], ['paytm', 'merchant_key'], ['stripe', 'secret_key'], ['stripe', 'webhook_secret'], ['paypal', 'client_secret'],
            ['s3', 'secret_key'], ['r2', 'secret_key'], ['smtp', 'password'], ['turnstile', 'secret_key']] as [$s, $f]) {
            self::assertSame('secret', $services[$s]['fields'][$f]['type'], "{$s}.{$f} must be a secret");
        }
    }

    // ---- writing and reading ------------------------------------------------------------------------------------------

    public function test_secrets_are_encrypted_at_rest_and_plain_fields_are_not(): void
    {
        $this->creds()->save('stripe', $this->stripe(), $this->model($this->user('super_admin')));

        $secretRow = $this->db->selectOne("SELECT * FROM integration_credentials WHERE service = 'stripe' AND field = 'secret_key'");
        $plainRow = $this->db->selectOne("SELECT * FROM integration_credentials WHERE service = 'stripe' AND field = 'publishable_key'");

        self::assertSame(1, (int) $secretRow['is_secret']);
        self::assertStringNotContainsString('sk_test', (string) $secretRow['value'], 'the secret is not stored as text');
        self::assertSame(self::SECRET, $this->app->get(Encryptor::class)->decrypt((string) $secretRow['value']));
        self::assertSame(0, (int) $plainRow['is_secret']);
        self::assertSame('pk_test_abc', $plainRow['value']);
        self::assertSame(self::SECRET, $this->creds()->get('stripe', 'secret_key'), 'and it reads back for the code that needs it');
    }

    public function test_the_view_of_a_service_never_contains_a_secret_only_a_masked_hint(): void
    {
        $this->creds()->save('stripe', $this->stripe(['webhook_secret' => 'short']), $this->model($this->user('super_admin')));

        $view = $this->creds()->view('stripe');

        self::assertSame('••••' . substr(self::SECRET, -4), $view['secret_key']['hint']);
        self::assertSame('', $view['secret_key']['value']);
        self::assertSame('••••••••', $view['webhook_secret']['hint'], 'a short secret is masked completely');
        self::assertSame('pk_test_abc', $view['publishable_key']['value']);
        self::assertStringNotContainsString(self::SECRET, json_encode($view));
        self::assertSame('••••wxyz', Credentials::mask('abcdefghijklwxyz'));
        self::assertSame('', Credentials::mask(''));
    }

    public function test_a_blank_secret_keeps_the_saved_one_and_a_blank_plain_field_returns_to_the_default(): void
    {
        $admin = $this->model($this->user('super_admin'));
        $this->creds()->save('stripe', $this->stripe(), $admin);

        $changed = $this->creds()->save('stripe', ['publishable_key' => '', 'secret_key' => '', 'webhook_secret' => ''], $admin);

        self::assertSame(['publishable_key'], $changed);
        self::assertNull($this->creds()->get('stripe', 'publishable_key'));
        self::assertSame(self::SECRET, $this->creds()->get('stripe', 'secret_key'), 'an empty secret box does not wipe the secret');
        self::assertSame([], $this->creds()->save('stripe', ['secret_key' => self::SECRET], $admin), 'saving the same value again changes nothing');
    }

    public function test_a_secret_can_be_removed_explicitly_and_a_service_reset(): void
    {
        $admin = $this->model($this->user('super_admin'));
        $this->creds()->save('stripe', $this->stripe(), $admin);

        self::assertSame(['secret_key'], $this->creds()->save('stripe', ['clear' => ['secret_key']], $admin));
        self::assertNull($this->creds()->get('stripe', 'secret_key'));
        self::assertGreaterThan(0, $this->creds()->reset('stripe', $admin));
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM integration_credentials WHERE service = 'stripe'"));
        self::assertSame('empty', $this->creds()->status('stripe')['state']);
    }

    public function test_the_environment_supplies_a_value_only_when_nothing_is_saved(): void
    {
        $admin = $this->model($this->user('super_admin'));
        putenv('INTEGRATIONS_WHATSAPP_NUMBER=911111111111');

        self::assertSame('911111111111', $this->creds()->get('whatsapp', 'number'));
        self::assertSame('env', $this->creds()->source('whatsapp', 'number'));

        $this->creds()->save('whatsapp', ['number' => '922222222222'], $admin);
        self::assertSame('922222222222', $this->creds()->get('whatsapp', 'number'), 'a saved value wins over .env');
        self::assertSame('saved', $this->creds()->source('whatsapp', 'number'));

        $this->creds()->save('whatsapp', ['number' => ''], $admin);
        self::assertSame('911111111111', $this->creds()->get('whatsapp', 'number'), 'emptying it goes back to the .env value');
    }

    public function test_invalid_input_is_rejected_field_by_field_and_nothing_is_saved(): void
    {
        $admin = $this->model($this->user('super_admin'));

        foreach ([
            ['razorpay', ['mode' => 'production']], ['phonepe', ['salt_index' => 'one']], ['phonepe', ['salt_index' => '1e9']],
            ['stripe', ['publishable_key' => "pk\nInjected"]], ['stripe', ['secret_key' => "sk\x00x"]], ['stripe', ['publishable_key' => str_repeat('k', 256)]],
            ['stripe', ['secret_key' => str_repeat('k', 2001)]],
        ] as [$service, $bad]) {
            try {
                $this->creds()->save($service, $bad + ['publishable_key' => 'pk_ok'], $admin);
                self::fail('accepted ' . json_encode($bad));
            } catch (ValidationException $e) {
                self::assertNotEmpty($e->errors(), json_encode($bad));
            }
        }
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM integration_credentials'), 'a rejected save is all-or-nothing');
        $this->expectException(\InvalidArgumentException::class);
        $this->creds()->save('no-such-service', ['x' => 'y'], $admin);
    }

    public function test_the_audit_trail_names_the_fields_but_never_the_values(): void
    {
        $this->creds()->save('stripe', $this->stripe(), $this->model($this->user('super_admin')));

        $log = (string) $this->db->selectValue("SELECT new_values FROM activity_logs WHERE action = 'integration_updated' ORDER BY id DESC LIMIT 1");

        self::assertStringContainsString('stripe', $log);
        self::assertStringContainsString('secret_key', $log);
        self::assertStringNotContainsString(self::SECRET, $log);
        self::assertStringNotContainsString('whsec_', $log);
        self::assertStringNotContainsString('pk_test', $log);
    }

    public function test_a_secret_that_can_no_longer_be_decrypted_reads_as_missing_and_is_flagged(): void
    {
        $this->creds()->save('stripe', $this->stripe(), $this->model($this->user('super_admin')));
        $wrongKey = new Credentials($this->db, new Encryptor('a-completely-different-application-key'), $this->app->get(\App\Audit\AuditService::class), $this->app);

        self::assertNull($wrongKey->get('stripe', 'secret_key'), 'ciphertext is never returned');
        self::assertTrue($wrongKey->view('stripe')['secret_key']['unreadable']);
        self::assertSame('pk_test_abc', $wrongKey->get('stripe', 'publishable_key'), 'plain fields are unaffected');
    }

    // ---- status, switch and the config overlay ------------------------------------------------------------------------------

    public function test_status_follows_required_fields_and_the_on_off_switch(): void
    {
        $admin = $this->model($this->user('super_admin'));
        self::assertSame('empty', $this->creds()->status('stripe')['state']);

        $this->creds()->save('stripe', ['publishable_key' => 'pk_test_abc'], $admin);
        self::assertSame('incomplete', $this->creds()->status('stripe')['state']);
        self::assertFalse($this->creds()->isConfigured('stripe'));

        $this->creds()->save('stripe', $this->stripe(), $admin);
        self::assertSame('configured', $this->creds()->status('stripe')['state']);

        $this->creds()->save('stripe', [Credentials::ENABLED => '0'], $admin);
        self::assertSame('off', $this->creds()->status('stripe')['state']);
        self::assertNull($this->creds()->get('stripe', 'secret_key'), 'a switched-off service hands out nothing');
        $this->creds()->save('stripe', [Credentials::ENABLED => '1'], $admin);
        self::assertSame(self::SECRET, $this->creds()->get('stripe', 'secret_key'));
    }

    public function test_saved_values_are_copied_into_config_and_a_switched_off_service_is_blanked(): void
    {
        $admin = $this->model($this->user('super_admin'));
        $config = $this->app->config();
        $this->creds()->save('turnstile', ['site_key' => '0xSITE', 'secret_key' => '0xSECRETSECRET'], $admin);
        $this->creds()->save('smtp', ['host' => 'smtp.example.test', 'port' => '2525', 'username' => 'u', 'password' => 'p'], $admin);

        $this->creds()->applyToConfig();

        self::assertSame('0xSITE', $config->get('integrations.turnstile.site_key'));
        self::assertSame('0xSECRETSECRET', $config->get('integrations.turnstile.secret_key'));
        self::assertSame('smtp.example.test', $config->get('mail.smtp.host'));
        self::assertSame(2525, $config->get('mail.smtp.port'), 'numbers arrive as numbers');
        self::assertSame('smtp', $config->get('mail.driver'));

        $this->creds()->save('turnstile', [Credentials::ENABLED => '0'], $admin);
        $this->creds()->save('smtp', [Credentials::ENABLED => '0'], $admin);
        $this->creds()->applyToConfig();

        self::assertSame('', $config->get('integrations.turnstile.site_key'), 'switching Turnstile off turns the widget off');
        self::assertSame('log', $config->get('mail.driver'), 'switching mail off sends nothing');
    }

    // ---- access ----------------------------------------------------------------------------------------------------------

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

    private function code(string $method, string $uri, array $post = []): int
    {
        try {
            return $this->send($method, $uri, $post)->getStatus();
        } catch (AuthorizationException) {
            return 403;
        } catch (\App\Exceptions\HttpException $e) {
            return $e->getStatusCode();
        }
    }

    public function test_only_the_super_admin_holds_integration_permissions_by_default(): void
    {
        $roles = $this->app->get(RoleAdminService::class);
        foreach (['admin', 'manager', 'accounts', 'counselor'] as $role) {
            self::assertNotContains('integrations.view', $roles->defaultsFor($role), $role);
            self::assertNotContains('integrations.manage', $roles->defaultsFor($role), $role);
        }
        self::assertSame(2, (int) $this->db->selectValue("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id JOIN roles r ON r.id = rp.role_id WHERE r.name = 'super_admin' AND p.name LIKE 'integrations.%'"));
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id JOIN roles r ON r.id = rp.role_id WHERE r.name <> 'super_admin' AND p.name LIKE 'integrations.%'"));

        $this->actAs($this->user('admin'));
        self::assertSame(403, $this->code('GET', '/admin/integrations'));
        self::assertSame(403, $this->code('GET', '/admin/integrations/stripe'));
        self::assertSame(403, $this->code('PUT', '/admin/integrations/stripe', ['_method' => 'PUT', 'f' => ['secret_key' => 'x']]));
    }

    public function test_the_screens_show_status_but_never_a_secret(): void
    {
        $super = $this->user('super_admin');
        $this->creds()->save('stripe', $this->stripe(), $this->model($super));
        $this->actAs($super);

        $index = $this->send('GET', '/admin/integrations');
        self::assertSame(200, $index->getStatus());
        foreach (['Razorpay', 'PayU India', 'Cashfree Payments', 'PhonePe PG', 'CCAvenue', 'Paytm Payment Gateway', 'Stripe', 'PayPal', 'Amazon S3', 'Cloudflare R2'] as $label) {
            self::assertStringContainsString($label, $index->getBody());
        }
        self::assertStringContainsString('Configured', $index->getBody());

        $show = $this->send('GET', '/admin/integrations/stripe');
        self::assertSame(200, $show->getStatus());
        self::assertStringNotContainsString(self::SECRET, $show->getBody());
        self::assertStringNotContainsString('whsec_1234567890abcdef', $show->getBody());
        self::assertStringContainsString('••••' . substr(self::SECRET, -4), $show->getBody());
        self::assertStringContainsString('value="pk_test_abc"', $show->getBody());
        self::assertStringContainsString('no-store', (string) $show->getHeader('Cache-Control'));
        self::assertSame(404, $this->code('GET', '/admin/integrations/no-such-service'));
    }

    public function test_saving_needs_a_fresh_password_confirmation_and_never_echoes_what_was_typed(): void
    {
        $super = $this->user('super_admin');

        $this->actAs($super, confirmed: false);
        $res = $this->send('PUT', '/admin/integrations/stripe', ['_method' => 'PUT', 'f' => $this->stripe(), 'enabled' => '1']);
        self::assertSame('/confirm-password', $res->getHeader('Location'));
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM integration_credentials'));

        $this->actAs($super);
        $res = $this->send('PUT', '/admin/integrations/stripe', ['_method' => 'PUT', 'f' => $this->stripe(), 'enabled' => '1']);
        self::assertSame('/admin/integrations/stripe', $res->getHeader('Location'));
        self::assertSame(self::SECRET, $this->creds()->get('stripe', 'secret_key'));

        // a rejected save must not put the typed secret into the flashed old input
        $this->send('PUT', '/admin/integrations/razorpay', ['_method' => 'PUT', 'f' => ['key_id' => 'rzp_x', 'key_secret' => 'TOPSECRETVALUE1234', 'mode' => 'production'], 'enabled' => '1']);
        $page = $this->send('GET', '/admin/integrations/razorpay')->getBody();
        self::assertStringNotContainsString('TOPSECRETVALUE1234', $page);
        self::assertStringContainsString('must be one of the listed options', $page);

        $this->send('POST', '/admin/integrations/stripe/reset', ['x' => '1']);
        self::assertSame(0, (int) $this->db->selectValue("SELECT COUNT(*) FROM integration_credentials WHERE service = 'stripe'"));
    }
}
