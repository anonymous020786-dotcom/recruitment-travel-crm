<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Integrations\Credentials;
use App\Models\User;
use App\Payments\GatewayRegistry;
use App\Repositories\UserRepository;
use App\Support\HttpClient;
use App\Support\Hash;
use App\Support\Ulid;

/** Shared setup for gateway tests: a recording HTTP client bound into the container, and credentials saved through the real store. */
abstract class GatewayTestCase extends DbTestCase
{
    protected FakeHttp $http;
    protected int $branch;
    /** @var array<string,int> */
    protected array $roles = [];
    /** @var list<int> */
    protected array $userIds = [];
    /** @var list<array<string,mixed>> */
    private array $credSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->credSnapshot = $this->db->select('SELECT * FROM integration_credentials');
        $this->db->affectingStatement('DELETE FROM integration_credentials');
        $this->branch = (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => 'GW branch', 'code' => 'GWX-' . bin2hex(random_bytes(2))]);
        $this->http = new FakeHttp();
        $this->app->instance(HttpClient::class, $this->http);
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'GWX-%')";
        $this->db->affectingStatement("DELETE FROM gateway_payments WHERE invoice_id IN (SELECT id FROM invoices WHERE {$like})");
        $this->db->affectingStatement('DELETE FROM gateway_events WHERE gateway IN (\'razorpay\', \'stripe\', \'payu\', \'cashfree\', \'phonepe\', \'ccavenue\', \'paytm\', \'paypal\')');
        $this->db->affectingStatement("DELETE FROM receipts WHERE payment_id IN (SELECT id FROM payments WHERE {$like})");
        $this->db->affectingStatement("DELETE FROM payment_allocations WHERE payment_id IN (SELECT id FROM payments WHERE {$like})");
        $this->db->affectingStatement("DELETE FROM payments WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM invoices WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM applications WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM jobs WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM employers WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM candidates WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module IN ('payments', 'invoices', 'applications', 'jobs', 'employers', 'leads', 'candidates', 'integrations')");
        $this->db->affectingStatement("DELETE FROM leads WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM persons WHERE full_name LIKE 'GWX %'");
        $this->db->affectingStatement('DELETE FROM integration_credentials');
        foreach ($this->credSnapshot as $row) {
            $this->db->insertRow('integration_credentials', $row);
        }
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM notifications WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM sessions WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'GWX-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope REGEXP '^(invoice|payment|receipt|job|employer|lead|candidate|application):'");
    }

    protected function user(string $role, ?int $branch = null): User
    {
        $branch ??= $this->branch;
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "GW {$role}", 'email' => 'gw_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branch, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branch]);
        $this->userIds[] = $id;

        return $this->app->get(UserRepository::class)->findById($id);
    }

    /** Save credentials the way the super admin does. @param array<string,string> $fields */
    protected function configure(string $service, array $fields): void
    {
        $this->app->get(Credentials::class)->save($service, $fields, $this->user('super_admin'));
    }

    protected function gateway(string $key): \App\Payments\Gateway
    {
        return $this->app->get(GatewayRegistry::class)->usable($key) ?? throw new \LogicException("{$key} is not configured in this test");
    }
}
