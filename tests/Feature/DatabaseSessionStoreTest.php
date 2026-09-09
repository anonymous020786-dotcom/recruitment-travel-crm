<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Session\DatabaseSessionStore;
use App\Support\Db;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for the real `sessions` table. Skipped unless a MySQL/MariaDB
 * connection is configured and reachable (env CRM_TEST_DB_* or a local crm_dev).
 */
final class DatabaseSessionStoreTest extends TestCase
{
    private Db $db;
    private DatabaseSessionStore $store;

    protected function setUp(): void
    {
        $config = [
            'driver'   => 'mysql',
            'host'     => getenv('CRM_TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => (int) (getenv('CRM_TEST_DB_PORT') ?: 3306),
            'database' => getenv('CRM_TEST_DB_NAME') ?: 'crm_dev',
            'username' => getenv('CRM_TEST_DB_USER') ?: 'crm_dev',
            'password' => getenv('CRM_TEST_DB_PASS') ?: 'crm_dev_pw',
            'charset'  => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'timezone' => '+00:00',
            'options'  => [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC],
        ];

        try {
            $this->db = new Db($config);
            $this->db->select('SELECT 1');
            $this->db->select('SELECT 1 FROM sessions LIMIT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('No test database / sessions table: ' . $e->getMessage());
        }

        $this->store = new DatabaseSessionStore($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->affectingStatement("DELETE FROM sessions WHERE id LIKE 'test_%'");
        }
    }

    public function test_read_missing_returns_empty(): void
    {
        self::assertSame([], $this->store->read('test_missing_' . bin2hex(random_bytes(4))));
    }

    public function test_write_then_read_roundtrip(): void
    {
        $id = 'test_' . bin2hex(random_bytes(20));
        $this->store->write($id, ['name' => 'Asha', 'roles' => ['a', 'b'], '_auth_user_id' => 7], [
            'ip' => inet_pton('127.0.0.1'), 'user_agent' => 'phpunit',
        ]);

        $data = $this->store->read($id);
        self::assertSame('Asha', $data['name']);
        self::assertSame(['a', 'b'], $data['roles']);

        $row = $this->db->selectOne('SELECT user_id FROM sessions WHERE id = ?', [$id]);
        self::assertSame(7, (int) $row['user_id']);
    }

    public function test_write_is_upsert(): void
    {
        $id = 'test_' . bin2hex(random_bytes(20));
        $this->store->write($id, ['v' => 1]);
        $this->store->write($id, ['v' => 2]);
        self::assertSame(2, $this->store->read($id)['v']);
    }

    public function test_destroy(): void
    {
        $id = 'test_' . bin2hex(random_bytes(20));
        $this->store->write($id, ['v' => 1]);
        $this->store->destroy($id);
        self::assertSame([], $this->store->read($id));
    }

    public function test_gc_removes_stale(): void
    {
        $id = 'test_' . bin2hex(random_bytes(20));
        $this->store->write($id, ['v' => 1]);
        $this->db->affectingStatement('UPDATE sessions SET last_activity = ? WHERE id = ?', [time() - 100000, $id]);

        self::assertGreaterThanOrEqual(1, $this->store->gc(3600));
        self::assertSame([], $this->store->read($id));
    }
}
