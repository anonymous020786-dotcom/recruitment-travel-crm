<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Application;
use App\Support\Config;
use App\Support\Db;
use App\Support\Logger;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

/**
 * Base for integration tests that need a real MySQL/MariaDB. Auto-skips when the
 * database is unreachable so the suite still passes on machines without it.
 *
 * Override via env: CRM_TEST_DB_HOST/PORT/NAME/USER/PASS (default local crm_dev).
 */
abstract class DbTestCase extends TestCase
{
    protected Db $db;
    protected Application $app;
    /** @var array<string,mixed> the connection settings this test's Db was built from */
    protected array $dbConfig = [];

    protected function setUp(): void
    {
        $config = [
            'driver'    => 'mysql',
            'host'      => getenv('CRM_TEST_DB_HOST') ?: '127.0.0.1',
            'port'      => (int) (getenv('CRM_TEST_DB_PORT') ?: 3306),
            'database'  => getenv('CRM_TEST_DB_NAME') ?: 'crm_dev',
            'username'  => getenv('CRM_TEST_DB_USER') ?: 'crm_dev',
            'password'  => getenv('CRM_TEST_DB_PASS') ?: 'crm_dev_pw',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'timezone'  => '+00:00',
            'options'   => [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ];

        $this->dbConfig = $config;

        try {
            $this->db = new Db($config);
            $this->db->select('SELECT 1');
            $this->db->select('SELECT 1 FROM schema_migrations LIMIT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Integration DB unavailable: ' . $e->getMessage());
        }

        $this->app = new Application(TEST_ROOT);
        $appConfig = new Config(TEST_ROOT . '/config');
        $appConfig->set('app.env', 'testing');
        $appConfig->set('app.url', 'http://localhost');
        $appConfig->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $this->app->instance(Config::class, $appConfig);
        $this->app->instance(Logger::class, new Logger(sys_get_temp_dir() . '/crm_feat_logs'));
        $this->app->instance(Db::class, $this->db);
        $this->app->boot();

        // Same container wiring as production (Gate policies, StatusMachine, services, ...).
        (require TEST_ROOT . '/bootstrap/services.php')($this->app);
    }

    /**
     * PHPUnit keeps every TestCase object (and so its connection) alive until the end of the run, which
     * exhausted MariaDB's max_connections once the suite grew. Runs after each test's own tearDown().
     */
    #[After]
    protected function releaseConnection(): void
    {
        if (isset($this->db)) {
            $this->db->disconnect();
        }
    }

    protected function cleanupUsers(string $emailLike): void
    {
        $this->db->affectingStatement('DELETE FROM users WHERE email LIKE ?', [$emailLike]);
        $this->db->affectingStatement('DELETE FROM login_attempts WHERE email LIKE ?', [$emailLike]);
    }
}
