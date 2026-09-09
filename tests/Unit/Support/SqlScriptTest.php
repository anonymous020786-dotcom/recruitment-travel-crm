<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SqlScript;
use PHPUnit\Framework\TestCase;

final class SqlScriptTest extends TestCase
{
    public function test_splits_simple_statements(): void
    {
        $out = SqlScript::split('SELECT 1; SELECT 2;');
        self::assertSame(['SELECT 1', 'SELECT 2'], $out);
    }

    public function test_ignores_semicolons_inside_strings(): void
    {
        $out = SqlScript::split("INSERT INTO t (a) VALUES ('x;y'); SELECT 1;");
        self::assertCount(2, $out);
        self::assertStringContainsString("'x;y'", $out[0]);
    }

    public function test_ignores_semicolons_in_comments(): void
    {
        $sql = <<<SQL
        -- a comment; with semicolon
        CREATE TABLE t (id INT); # trailing; comment
        /* block; comment */
        SELECT 2;
        SQL;
        $out = SqlScript::split($sql);
        self::assertCount(2, $out);
        self::assertStringStartsWith('CREATE TABLE t', $out[0]);
        self::assertSame('SELECT 2', $out[1]);
    }

    public function test_handles_backtick_identifiers(): void
    {
        $out = SqlScript::split('CREATE TABLE `we;ird` (id INT); SELECT 1;');
        self::assertCount(2, $out);
        self::assertStringContainsString('`we;ird`', $out[0]);
    }

    public function test_trailing_statement_without_semicolon(): void
    {
        $out = SqlScript::split("SELECT 1;\nSELECT 2");
        self::assertSame(['SELECT 1', 'SELECT 2'], $out);
    }

    public function test_escaped_single_quote(): void
    {
        $out = SqlScript::split("INSERT INTO t VALUES ('O''Brien; Co'); SELECT 1;");
        self::assertCount(2, $out);
    }

    public function test_real_baseline_migration_parses(): void
    {
        $file = TEST_ROOT . '/database/migrations/0001_initial_schema.sql';
        self::assertFileExists($file);
        $statements = SqlScript::split((string) file_get_contents($file));
        self::assertGreaterThan(50, count($statements));
        foreach ($statements as $s) {
            self::assertMatchesRegularExpression('/^(CREATE|ALTER|INSERT|SET|DROP)/i', $s);
        }
    }
}
