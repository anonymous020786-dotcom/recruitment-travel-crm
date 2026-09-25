<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** House style: links are never underlined — not by a view class, not by the base styles, not on hover. */
final class NoLinkUnderlinesTest extends TestCase
{
    public function test_no_view_asks_for_an_underline(): void
    {
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(TEST_ROOT . '/resources/views', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && preg_match('/underline/i', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = substr($file->getPathname(), strlen(TEST_ROOT) + 1);
            }
        }

        self::assertSame([], $offenders, 'a view uses an underline utility class (or mentions underline)');
    }

    public function test_the_stylesheet_source_and_the_compiled_build_forbid_link_underlines(): void
    {
        $src = (string) file_get_contents(TEST_ROOT . '/resources/css/app.css');
        self::assertStringContainsString('a, a:hover, a:focus, a:active, a:visited { text-decoration: none !important; }', $src);
        self::assertStringNotContainsString('a:hover { @apply text-brand-700 underline', $src);

        $manifest = json_decode((string) file_get_contents(TEST_ROOT . '/public/assets/build/manifest.json'), true);
        $css = (string) file_get_contents(TEST_ROOT . '/public/assets/build/' . $manifest['app.css']);
        preg_match('/(?:^|})(a[^{}]*)\{text-decoration:none!important\}/', $css, $m);
        self::assertNotEmpty($m, 'the compiled CSS carries the no-underline rule');
        $selectors = explode(',', $m[1]);
        sort($selectors);
        self::assertSame(['a', 'a:active', 'a:focus', 'a:hover', 'a:visited'], $selectors, 'it covers every link state');
    }
}
