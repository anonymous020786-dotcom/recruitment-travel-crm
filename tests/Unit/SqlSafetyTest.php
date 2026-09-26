<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Sql;
use PHPUnit\Framework\TestCase;

/**
 * Static SQL-injection guard (Phase 12). Values are always bound; the only things interpolated into SQL text
 * are fragments the code itself builds (scope predicates, allow-listed ORDER BY, int-typed LIMIT/OFFSET,
 * placeholder lists, identifiers checked by Sql::identifier). This test reads the data-access code and fails when
 * a *new* variable starts being interpolated into SQL, so it gets a human look — and when request data could reach
 * a query string at all.
 */
final class SqlSafetyTest extends TestCase
{
    /** Interpolated names that were reviewed: each holds code-built SQL or an int, never request data. */
    private const REVIEWED = [
        'branchSql'    => 'BranchScope::whereClause() predicate; values bound as :bs0…',
        'where'        => 'implode of literal predicates built in the repository',
        'condition'    => 'literal predicate chosen by the repository',
        'window'       => 'match() over a fixed bucket list',
        'extraWhere'   => 'literal predicate passed by DailyReport/Dashboard services',
        'order'        => 'SORT allow-list lookup + fixed direction (ListQuery)',
        'limit'        => 'int-typed parameter',
        'offset'       => 'int-typed parameter',
        'cap'          => 'AuditLogRepository::COUNT_CAP + 1, an int built from a constant',
        'owed'         => 'constant SQL expression',
        'in'           => 'list of :placeholders',
        'table'        => 'repository/service constant, or config table name',
        'this->table'  => 'constructor-injected config table name',
        'dateColumn'   => 'literal passed by the calling service',
        'amountColumn' => 'literal passed by the calling service',
        'column'       => 'IntegrityRepository constant map',
        'n'            => 'loop counter used in placeholder names',
        'prefix'       => 'placeholder-name prefix',
        'ph'           => 'list of ? placeholders built by array_fill',
        'year'         => 'int',
        'scope'        => 'constant sequence scope (bound value or key text)',
        'i'            => 'loop counter used in placeholder names',
        'timezone'     => 'validated config value in Db::connect()',
        'collation'    => 'derived from validated charset config in Db::connect()',
        "this->config['charset']" => 'validated charset config in Db::connect()',
        'sql'          => 'SQL text already built by the caller (Db::exists wraps it)',
    ];

    private const SQL_LINE = '/\b(SELECT|INSERT|UPDATE|DELETE|WHERE|FROM|JOIN|ORDER BY|GROUP BY|LIMIT|SET NAMES|SET time_zone|IN \()\b|\bAND\b.*:/';

    /** @return list<string> */
    private static function dataAccessFiles(): array
    {
        $root = TEST_ROOT . '/app';
        $files = array_merge(
            glob($root . '/Repositories/*.php') ?: [],
            glob($root . '/Session/*.php') ?: [],
            glob($root . '/Support/{Db,Sequences,RateLimiter}.php', GLOB_BRACE) ?: [],
            [$root . '/Mail/MailQueue.php'],
        );
        self::assertGreaterThan(30, count($files));

        return $files;
    }

    /** @return array<string,list<string>> variable => "file:line" of each interpolation on a SQL line */
    private static function interpolations(): array
    {
        $found = [];
        foreach (self::dataAccessFiles() as $file) {
            foreach (file($file) ?: [] as $i => $line) {
                $trim = ltrim($line);
                if (str_starts_with($trim, '*') || str_starts_with($trim, '//') || str_starts_with($trim, '/*') || !preg_match(self::SQL_LINE, $line)) {
                    continue;
                }
                if (preg_match_all('/\{\$([^}]+)\}/', $line, $m)) {
                    foreach ($m[1] as $expr) {
                        // strip a trailing method/array suffix that is part of the reviewed name (e.g. this->table)
                        $found[$expr][] = basename($file) . ':' . ($i + 1);
                    }
                }
            }
        }

        return $found;
    }

    public function test_only_reviewed_variables_are_interpolated_into_sql(): void
    {
        $found = self::interpolations();
        self::assertGreaterThan(80, array_sum(array_map('count', $found)), 'the scan should see the repositories\' interpolations — is the pattern still matching?');
        self::assertArrayHasKey('branchSql', $found);

        $unreviewed = [];
        foreach ($found as $expr => $where) {
            if (!isset(self::REVIEWED[$expr])) {
                $unreviewed[$expr] = $where[0];
            }
        }

        self::assertSame([], $unreviewed, 'new variable(s) interpolated into SQL — bind it, or review it and add it to REVIEWED');
    }

    public function test_request_data_never_appears_in_a_query_string(): void
    {
        $bad = [];
        foreach (self::dataAccessFiles() as $file) {
            foreach (file($file) ?: [] as $i => $line) {
                if (preg_match('/\$_(GET|POST|REQUEST|COOKIE|SERVER)\b|\$request->|request\(\)->/', $line)) {
                    $bad[] = basename($file) . ':' . ($i + 1);
                }
            }
        }

        self::assertSame([], $bad, 'repositories must not read the request; services pass values in');
    }

    public function test_set_lists_go_through_the_identifier_checked_helper(): void
    {
        $raw = [];
        foreach (self::dataAccessFiles() as $file) {
            foreach (file($file) ?: [] as $i => $line) {
                if (str_contains($line, '`{$col}`')) {
                    $raw[] = basename($file) . ':' . ($i + 1);
                }
            }
        }

        self::assertSame([], $raw, 'use Sql::assign() for dynamic SET columns');
    }

    public function test_references_finds_a_table_alias_but_not_lookalikes(): void
    {
        self::assertTrue(Sql::references('a.branch_id IN (1) AND p.full_name LIKE :s', 'p'));
        self::assertTrue(Sql::references('(p.full_name LIKE :s)', 'p'));
        self::assertFalse(Sql::references('pm.status = :s AND l.map.x = 1 AND a.branch_id IN (1)', 'p'), 'pm. / map. are other names');
        self::assertFalse(Sql::references('a.status = :f_status', 'st'));
        self::assertTrue(Sql::references('st.key_name = :f_status', 'st'));
        self::assertFalse(Sql::references("l.note LIKE 'p.x'", 'q'));
    }

    public function test_sql_helper_rejects_anything_but_a_plain_identifier(): void
    {
        self::assertSame('status', Sql::identifier('status'));
        self::assertSame('`closed_at` = :c_closed_at', Sql::assign('closed_at', 'c_'));

        foreach (['', 'a b', 'x`y', 'a;drop', 'a-b', '1abc', 'a.b', "a\n", 'a=1 --'] as $bad) {
            try {
                Sql::assign($bad, 'c_');
                self::fail("accepted unsafe identifier: {$bad}");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
