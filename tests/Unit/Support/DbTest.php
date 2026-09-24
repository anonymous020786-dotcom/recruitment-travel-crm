<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Exceptions\QueryException;
use App\Support\Db;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Db wrapper against an in-memory SQLite connection. The SQL
 * surface used here is portable; MySQL/MariaDB-specific behaviour is covered by
 * the migration integration test.
 */
final class DbTest extends TestCase
{
    private Db $db;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, qty INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1)');

        $this->db = new Db(['driver' => 'sqlite', 'database' => ':memory:'], null, $pdo);
    }

    public function test_insert_row_and_select(): void
    {
        $id = $this->db->insertRow('widgets', ['name' => 'Alpha', 'qty' => 3, 'active' => true]);
        self::assertSame('1', $id);

        $row = $this->db->selectOne('SELECT * FROM widgets WHERE id = :id', ['id' => 1]);
        self::assertSame('Alpha', $row['name']);
        self::assertSame(3, $row['qty']);
        self::assertSame(1, $row['active']);
    }

    public function test_positional_and_named_bindings(): void
    {
        $this->db->insertRow('widgets', ['name' => 'Beta', 'qty' => 5]);
        self::assertSame('Beta', $this->db->selectValue('SELECT name FROM widgets WHERE qty = ?', [5]));
        self::assertSame('Beta', $this->db->selectValue('SELECT name FROM widgets WHERE qty = :q', ['q' => 5]));
    }

    public function test_update_row_returns_affected(): void
    {
        $this->db->insertRow('widgets', ['name' => 'Gamma', 'qty' => 1]);
        $affected = $this->db->updateRow('widgets', ['qty' => 10], ['name' => 'Gamma']);
        self::assertSame(1, $affected);
        self::assertSame(10, $this->db->selectValue('SELECT qty FROM widgets WHERE name = ?', ['Gamma']));
    }

    public function test_transaction_commits(): void
    {
        $this->db->transaction(function (): void {
            $this->db->insertRow('widgets', ['name' => 'Delta']);
            $this->db->insertRow('widgets', ['name' => 'Epsilon']);
        });
        self::assertSame(2, $this->db->selectValue('SELECT COUNT(*) FROM widgets'));
    }

    public function test_transaction_rolls_back_on_exception(): void
    {
        try {
            $this->db->transaction(function (): void {
                $this->db->insertRow('widgets', ['name' => 'Zeta']);
                throw new \RuntimeException('stop');
            });
            self::fail('expected exception');
        } catch (\RuntimeException) {
            // expected
        }
        self::assertSame(0, $this->db->selectValue('SELECT COUNT(*) FROM widgets'));
    }

    public function test_nested_transaction_savepoint_partial_rollback(): void
    {
        $this->db->transaction(function (): void {
            $this->db->insertRow('widgets', ['name' => 'Outer']);
            try {
                $this->db->transaction(function (): void {
                    $this->db->insertRow('widgets', ['name' => 'Inner']);
                    throw new \RuntimeException('inner fails');
                });
            } catch (\RuntimeException) {
                // swallow — outer continues
            }
        });

        $names = array_column($this->db->select('SELECT name FROM widgets ORDER BY id'), 'name');
        self::assertSame(['Outer'], $names);
    }

    public function test_query_error_is_wrapped(): void
    {
        $this->expectException(QueryException::class);
        $this->db->select('SELECT * FROM does_not_exist');
    }

    public function test_identifier_guard_rejects_injection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->insertRow('widgets`;DROP TABLE widgets;--', ['name' => 'x']);
    }

    public function test_floats_are_bound_exactly_not_rounded_to_eight_decimals(): void
    {
        // Regression: floats used to be bound with sprintf('%.8F'), so -5.5E-10 was stored as -0 and 0.1 + 0.2 as 0.3.
        $this->db->unprepared('CREATE TABLE reals (id INTEGER PRIMARY KEY AUTOINCREMENT, v REAL)');
        $values = [-5.5E-10, 1.0E+300, 0.1 + 0.2, 3.141592653589793, 1.0E-320, -0.75, 0.0];

        foreach ($values as $v) {
            $this->db->insertRow('reals', ['v' => $v]);
        }
        $stored = array_map(static fn (array $r): float => (float) $r['v'], $this->db->select('SELECT v FROM reals ORDER BY id'));

        self::assertSame($values, $stored);
    }

    public function test_non_finite_floats_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->insertRow('widgets', ['name' => 'x', 'qty' => NAN]);
    }

    public function test_cursor_streams_rows(): void
    {
        foreach (range(1, 5) as $i) {
            $this->db->insertRow('widgets', ['name' => "w{$i}"]);
        }
        $seen = 0;
        foreach ($this->db->cursor('SELECT * FROM widgets') as $row) {
            $seen++;
            self::assertArrayHasKey('name', $row);
        }
        self::assertSame(5, $seen);
    }
}
