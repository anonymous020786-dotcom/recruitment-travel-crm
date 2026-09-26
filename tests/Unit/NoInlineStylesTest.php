<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The CSP (config/security.php) allows styles only from our stylesheet or a <style> carrying the request nonce. An inline
 * `style="…"` attribute or a <style> without the nonce is silently ignored by browsers — charts render flat, printouts lose
 * their layout. E-mail templates are exempt: mail clients need inline styles and no CSP applies there.
 */
final class NoInlineStylesTest extends TestCase
{
    public function test_web_views_use_no_inline_style_attributes_and_every_style_block_has_the_nonce(): void
    {
        $root = dirname(__DIR__, 2) . '/resources/views';
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (!str_ends_with($path, '.php') || str_contains($path, '/views/mail/')) {
                continue;
            }
            $src = (string) file_get_contents($path);
            $rel = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
            if (preg_match('/\sstyle\s*=\s*[\'"\\\\]/i', $src) === 1) {
                $offenders[] = "{$rel}: inline style attribute";
            }
            if (preg_match_all('/<style\b[^>]*>/i', $src, $m) > 0) {
                foreach ($m[0] as $tag) {
                    if (!str_contains($tag, 'nonce')) {
                        $offenders[] = "{$rel}: <style> without the CSP nonce";
                    }
                }
            }
        }
        self::assertSame([], $offenders);
    }
}
