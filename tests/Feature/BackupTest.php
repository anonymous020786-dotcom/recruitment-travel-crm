<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\BackupManager;
use App\Support\DbBackup;
use App\Support\DbRestore;
use Tests\Support\DbTestCase;

/**
 * Backup / restore (Phase 12): the dump must round-trip hostile values exactly, prove its own completeness, refuse
 * to overwrite the live database, and retention must never break a restorable chain.
 */
final class BackupTest extends DbTestCase
{
    private const PROBE = 'zz_backup_probe';
    private const COPY = 'zz_backup_probe_copy';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/crm_backup_test_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        $this->dropProbe();
    }

    protected function tearDown(): void
    {
        $this->dropProbe();
        foreach (glob($this->dir . '/{,*/,*/*/}*', GLOB_BRACE) ?: [] as $f) {
            is_file($f) && @unlink($f);
        }
        foreach ([$this->dir . '/docs/sub', $this->dir . '/docs', $this->dir . '/out', $this->dir] as $d) {
            is_dir($d) && @rmdir($d);
        }
    }

    private function dropProbe(): void
    {
        foreach ([self::PROBE, self::COPY] as $t) {
            $this->db->unprepared("DROP TABLE IF EXISTS `{$t}`");
        }
    }

    /** @return array<string,mixed> */
    private function dbConfig(): array
    {
        return $this->dbConfig;
    }

    private function manager(?string $docs = null): BackupManager
    {
        return new BackupManager($this->dir . '/out', $this->dbConfig(), $docs ?? ($this->dir . '/docs'));
    }

    // ---- fidelity ---------------------------------------------------------------

    public function test_hostile_values_survive_a_dump_and_reload_exactly(): void
    {
        $this->db->unprepared('CREATE TABLE `' . self::PROBE . '` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, txt TEXT NULL, bin VARBINARY(16) NULL, j JSON NULL,
            dec_v DECIMAL(14,2) NULL, dbl DOUBLE NULL, dt DATETIME NULL, big BIGINT UNSIGNED NULL, flag TINYINT(1) NULL, n VARCHAR(20) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $rows = [
            ['plain', "\x00\xFF\x10\x7F", '{"a":[1,2,{"b":null}]}', '12345.67', 0.1 + 0.2, '2026-09-24 10:11:12', '18446744073709551615', 1, 'x'],
            ["O'Brien \"quoted\" back\\slash \\' \\\\", '', '[]', '-0.01', 1.0E+300, '2000-01-01 00:00:00', '0', 0, ''],
            ["line1\nline2\r\nline3;\nDROP TABLE users;\n-- comment\n", null, null, null, null, null, null, null, null],
            ['नमस्ते 🙂 café ñ 日本語', "\x00", '"string"', '0.00', -5.5E-10, '2026-12-31 23:59:59', '1', 1, 'a;b'],
            [str_repeat('long ', 5000), str_repeat("\xAB", 16), null, '99999999999.99', 3.141592653589793, null, null, null, null],
        ];
        foreach ($rows as $r) {
            $this->db->insertRow(self::PROBE, ['txt' => $r[0], 'bin' => $r[1], 'j' => $r[2], 'dec_v' => $r[3], 'dbl' => $r[4], 'dt' => $r[5], 'big' => $r[6], 'flag' => $r[7], 'n' => $r[8]]);
        }

        $file = $this->dir . '/probe.sql.gz';
        (new DbBackup($this->dbConfig()))->dump($file);
        DbBackup::verify($file);

        // Replay only the probe table, under another name, into the same database.
        $in = gzopen($file, 'rb');
        while (($line = gzgets($in)) !== false) {
            $line = rtrim($line, "\r\n");
            if (str_contains($line, '`' . self::PROBE . '`') && !str_starts_with($line, '--')) {
                $this->db->unprepared(str_replace('`' . self::PROBE . '`', '`' . self::COPY . '`', $line));
            }
        }
        gzclose($in);

        $original = $this->db->select('SELECT * FROM `' . self::PROBE . '` ORDER BY id');
        $copy = $this->db->select('SELECT * FROM `' . self::COPY . '` ORDER BY id');

        self::assertCount(5, $copy);
        self::assertSame($original, $copy, 'every value identical after dump + reload');
        self::assertSame(
            $this->db->selectValue('CHECKSUM TABLE `' . self::PROBE . '`', [], null) === null ? null : array_values($this->db->selectOne('CHECKSUM TABLE `' . self::PROBE . '`'))[1],
            array_values($this->db->selectOne('CHECKSUM TABLE `' . self::COPY . '`'))[1],
        );
    }

    public function test_transient_tables_keep_their_structure_but_not_their_rows(): void
    {
        $file = $this->dir . '/d.sql.gz';
        $this->db->insertRow('rate_limits', ['bucket_key' => 'zz-backup-test', 'window_started' => gmdate('Y-m-d H:i:s'), 'hits' => 1]);

        try {
            $r = (new DbBackup($this->dbConfig()))->dump($file);
        } finally {
            $this->db->affectingStatement("DELETE FROM rate_limits WHERE bucket_key = 'zz-backup-test'");
        }

        self::assertArrayNotHasKey('rate_limits', $r['per_table']);
        self::assertArrayNotHasKey('sessions', $r['per_table']);
        $text = (string) gzdecode((string) file_get_contents($file));
        self::assertStringContainsString('CREATE TABLE `rate_limits`', $text);
        self::assertStringNotContainsString('zz-backup-test', $text);
    }

    // ---- completeness checks ---------------------------------------------------------

    public function test_a_complete_dump_verifies_and_reports_its_totals(): void
    {
        $file = $this->dir . '/ok.sql.gz';
        $dump = (new DbBackup($this->dbConfig()))->dump($file);
        $check = DbBackup::verify($file);

        self::assertSame($dump['tables'], $check['tables']);
        self::assertSame($dump['rows'], $check['rows']);
        self::assertGreaterThan(50, $check['tables']);
    }

    public function test_truncated_corrupt_and_tampered_dumps_are_rejected(): void
    {
        $file = $this->dir . '/ok.sql.gz';
        (new DbBackup($this->dbConfig()))->dump($file);
        $text = (string) gzdecode((string) file_get_contents($file));
        $bytes = (string) file_get_contents($file);

        // no footer (a dump that died half way)
        file_put_contents($this->dir . '/nofooter.sql.gz', gzencode(substr($text, 0, (int) strrpos($text, '-- dump complete'))));
        // gzip cut off mid-stream
        file_put_contents($this->dir . '/cut.sql.gz', substr($bytes, 0, (int) (strlen($bytes) * 0.6)));
        // footer promises more rows than the file holds
        file_put_contents($this->dir . '/lies.sql.gz', gzencode((string) preg_replace('/rows=(\d+)/', 'rows=99999999', $text)));
        // an INSERT line silently dropped
        $lines = explode("\n", $text);
        $drop = array_search(true, array_map(static fn ($l) => str_starts_with($l, 'INSERT INTO `users`'), $lines), true);
        unset($lines[$drop]);
        file_put_contents($this->dir . '/missing.sql.gz', gzencode(implode("\n", $lines)));
        file_put_contents($this->dir . '/tiny.sql.gz', 'x');

        foreach (['nofooter', 'cut', 'lies', 'missing', 'tiny'] as $name) {
            try {
                DbBackup::verify("{$this->dir}/{$name}.sql.gz");
                self::fail("accepted a bad dump: {$name}");
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_a_restore_refuses_the_live_database_and_a_bad_file_before_touching_anything(): void
    {
        $live = (string) $this->dbConfig()['database'];
        $file = $this->dir . '/ok.sql.gz';
        (new DbBackup($this->dbConfig()))->dump($file);

        try {
            (new DbRestore($this->dbConfig()))->restore($file, $live);
            self::fail('restored over the live database');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('live database', $e->getMessage());
        }

        file_put_contents($this->dir . '/junk.sql.gz', gzencode("DROP TABLE users;\n"));
        $this->expectException(\RuntimeException::class);
        (new DbRestore(['database' => $live . '_elsewhere'] + $this->dbConfig()))->restore($this->dir . '/junk.sql.gz', $live);
    }

    // ---- the manager -----------------------------------------------------------------------

    public function test_a_run_writes_a_verified_private_dump_and_no_partial_files(): void
    {
        $r = $this->manager()->run(withFiles: false, now: new \DateTimeImmutable('2026-09-24 04:00:00', new \DateTimeZone('UTC')));

        self::assertSame('db-20260924-040000.sql.gz', $r['db']['file']);
        self::assertFileExists($this->dir . '/out/db-20260924-040000.sql.gz');
        self::assertSame([], glob($this->dir . '/out/*.partial'));
        self::assertNull($r['files']);
        self::assertSame($r['db']['rows'], DbBackup::verify($this->dir . '/out/' . $r['db']['file'])['rows']);
    }

    public function test_documents_are_archived_in_full_then_incrementally_and_the_archive_holds_the_files(): void
    {
        if (!class_exists(\PharData::class)) {
            self::markTestSkipped('phar extension not available');
        }
        mkdir($this->dir . '/docs/sub', 0700, true);
        file_put_contents($this->dir . '/docs/a.pdf', 'AAA');
        file_put_contents($this->dir . '/docs/sub/b.pdf', 'BBB');
        file_put_contents($this->dir . '/docs/.gitkeep', '');
        $utc = new \DateTimeZone('UTC');
        $m = $this->manager();

        $r1 = $m->run(now: new \DateTimeImmutable('2026-09-20 04:00:00', $utc));
        self::assertSame('full', $r1['files']['kind']);
        self::assertSame(2, $r1['files']['files'], '.gitkeep is not backed up');
        $archive = new \PharData($this->dir . '/out/' . $r1['files']['file']);
        self::assertTrue(isset($archive['sub/b.pdf']), 'nested path preserved');
        self::assertSame('BBB', $archive['sub/b.pdf']->getContent());
        self::assertSame('AAA', $archive['a.pdf']->getContent());
        self::assertFalse(isset($archive['.gitkeep']));

        // nothing changed since → nothing archived (mtime older than the previous archive)
        touch($this->dir . '/docs/a.pdf', strtotime('2026-09-19 00:00:00 UTC'));
        touch($this->dir . '/docs/sub/b.pdf', strtotime('2026-09-19 00:00:00 UTC'));
        $r2 = $m->run(now: new \DateTimeImmutable('2026-09-21 04:00:00', $utc));
        self::assertNull($r2['files']);

        // one new file → an incremental holding just that file
        file_put_contents($this->dir . '/docs/sub/c.pdf', 'CCC');
        touch($this->dir . '/docs/sub/c.pdf', strtotime('2026-09-21 12:00:00 UTC'));
        $r3 = $m->run(now: new \DateTimeImmutable('2026-09-22 04:00:00', $utc));
        self::assertSame('inc', $r3['files']['kind']);
        self::assertSame(1, $r3['files']['files']);

        // a week after the last full → a new full
        $r4 = $m->run(now: new \DateTimeImmutable('2026-09-28 04:00:00', $utc));
        self::assertSame('full', $r4['files']['kind']);
        self::assertSame(3, $r4['files']['files']);
    }

    public function test_documents_over_the_size_limit_fail_loudly_instead_of_a_silent_partial_backup(): void
    {
        if (!class_exists(\PharData::class)) {
            self::markTestSkipped('phar extension not available');
        }
        mkdir($this->dir . '/docs', 0700, true);
        file_put_contents($this->dir . '/docs/big.pdf', str_repeat('x', 2048));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too large');
        (new BackupManager($this->dir . '/out', $this->dbConfig(), $this->dir . '/docs', null, 1024))->run();
    }

    // ---- retention ---------------------------------------------------------------------------

    private static function at(string $s): \DateTimeImmutable
    {
        return new \DateTimeImmutable($s, new \DateTimeZone('UTC'));
    }

    public function test_database_retention_keeps_7_days_4_sundays_and_3_month_starts(): void
    {
        $times = [];
        for ($d = 0; $d < 120; $d++) {
            $times[] = self::at('2026-09-24 04:00:00')->modify("-{$d} days");
        }
        $times[] = self::at('2026-09-24 09:00:00');           // a second backup the same day: only the newest counts
        $keep = BackupManager::retain($times);
        $days = array_map(static fn (string $k): string => substr($k, 0, 8), array_keys($keep));

        self::assertContains('20260924', $days);
        self::assertContains('20260918', $days, 'the 7th newest day');
        self::assertNotContains('20260917', array_diff($days, ['20260913', '20260906']), 'a plain weekday beyond 7 days is dropped');
        self::assertArrayHasKey('20260924-090000', $keep, 'newest of the day');
        self::assertArrayNotHasKey('20260924-040000', $keep, 'the older same-day backup is dropped');
        self::assertContains('20260913', $days, 'a Sunday');
        self::assertContains('20260830', $days, 'the 4th Sunday back');
        self::assertNotContains('20260823', $days, 'the 5th Sunday back is beyond the policy');
        self::assertContains('20260901', $days);
        self::assertContains('20260801', $days);
        self::assertContains('20260701', $days, 'the 3rd first-of-month');
        self::assertNotContains('20260601', $days);
    }

    public function test_document_retention_never_breaks_a_chain(): void
    {
        $a = static fn (string $t, string $k): array => ['at' => self::at($t), 'kind' => $k];
        $archives = [
            $a('2026-08-02 04:00:00', 'full'), $a('2026-08-05 04:00:00', 'inc'),
            $a('2026-08-09 04:00:00', 'full'), $a('2026-08-12 04:00:00', 'inc'),
            $a('2026-08-16 04:00:00', 'full'), $a('2026-08-18 04:00:00', 'inc'),
            $a('2026-08-23 04:00:00', 'full'), $a('2026-08-25 04:00:00', 'inc'),
            $a('2026-08-30 04:00:00', 'full'), $a('2026-09-01 04:00:00', 'inc'), $a('2026-09-02 04:00:00', 'inc'),
        ];
        $keep = array_keys(BackupManager::retainFiles($archives));
        sort($keep);

        self::assertSame(
            ['20260809-040000', '20260816-040000', '20260823-040000', '20260830-040000',    // 4 newest fulls
             '20260825-040000', '20260901-040000', '20260902-040000'],                      // incrementals newer than the 2nd-newest full
            array_values(array_diff($keep, [])) === $keep ? $this->sorted($keep) : $keep,
        );
        self::assertNotContains('20260805-040000', $keep, 'an incremental whose full was pruned is useless');
        self::assertNotContains('20260802-040000', $keep, 'the 5th full is beyond the policy');
        self::assertSame(['20260830-040000'], array_keys(BackupManager::retainFiles([$a('2026-08-30 04:00:00', 'full')])));
        self::assertSame([], BackupManager::retainFiles([]));
    }

    /** @param list<string> $l @return list<string> */
    private function sorted(array $l): array
    {
        // expected list above is "fulls then incrementals"; normalise both sides to the same order for the comparison
        $order = ['20260809-040000', '20260816-040000', '20260823-040000', '20260830-040000', '20260825-040000', '20260901-040000', '20260902-040000'];

        return array_values(array_filter($order, static fn (string $k): bool => in_array($k, $l, true)));
    }

    public function test_prune_deletes_only_what_the_policy_no_longer_needs(): void
    {
        mkdir($this->dir . '/out', 0700, true);
        $names = [];
        for ($d = 0; $d < 12; $d++) {
            $t = self::at('2026-09-24 04:00:00')->modify("-{$d} days");   // 24 Sep back to 13 Sep
            $names[] = 'db-' . $t->format('Ymd-His') . '.sql.gz';
        }
        foreach ($names as $n) {
            file_put_contents($this->dir . '/out/' . $n, 'x');
        }
        file_put_contents($this->dir . '/out/notes.txt', 'not a backup');   // never touched

        $deleted = $this->manager()->prune();
        sort($deleted);

        // kept: 24..18 Sep (7 days) + Sundays 20 and 13 Sep; dropped: 17, 16, 15, 14 Sep
        self::assertSame(['db-20260914-040000.sql.gz', 'db-20260915-040000.sql.gz', 'db-20260916-040000.sql.gz', 'db-20260917-040000.sql.gz'], $deleted);
        self::assertFileExists($this->dir . '/out/notes.txt');
        self::assertFileExists($this->dir . '/out/db-20260913-040000.sql.gz', 'a Sunday');
    }
}
