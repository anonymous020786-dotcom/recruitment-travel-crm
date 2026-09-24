<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tailwind only ships the classes it finds in the views when it is built. A view that uses a class nobody compiled
 * renders unstyled in that spot — silently. `node scripts/build.mjs --check` rebuilds to a temp file and compares it
 * with the committed stylesheet; this runs it wherever Node and Tailwind are installed (skipped elsewhere, e.g. on the
 * server, which only needs the committed build).
 */
final class AssetsUpToDateTest extends TestCase
{
    public function test_the_committed_stylesheet_contains_every_class_the_views_use(): void
    {
        $node = trim((string) @shell_exec(\DIRECTORY_SEPARATOR === '\\' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
        if ($node === '' || !is_file(TEST_ROOT . '/node_modules/tailwindcss/lib/cli.js')) {
            self::markTestSkipped('Node / Tailwind are not installed here');
        }

        $out = [];
        exec('node ' . escapeshellarg(TEST_ROOT . '/scripts/build.mjs') . ' --check 2>&1', $out, $code);

        self::assertSame(0, $code, implode("\n", $out));
        $manifest = json_decode((string) file_get_contents(TEST_ROOT . '/public/assets/build/manifest.json'), true);
        self::assertFileExists(TEST_ROOT . '/public/assets/build/' . $manifest['app.css'], 'the manifest points at a file that exists');
        self::assertSame(
            hash_file('sha256', TEST_ROOT . '/public/assets/build/app.css'),
            hash_file('sha256', TEST_ROOT . '/public/assets/build/' . $manifest['app.css']),
            'the hashed file is the current build',
        );
    }
}
