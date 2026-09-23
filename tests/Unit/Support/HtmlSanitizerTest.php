<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\HtmlSanitizer;
use App\Support\Slug;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerTest extends TestCase
{
    public function test_strips_scripts_and_their_bodies(): void
    {
        self::assertSame('<p>Hi</p>', HtmlSanitizer::clean('<p>Hi</p><script>alert(1)</script>'));
    }

    public function test_drops_all_attributes_including_handlers(): void
    {
        self::assertSame('<p>x</p>', HtmlSanitizer::clean('<p onclick="evil()" style="x:y">x</p>'));
    }

    public function test_removes_links_and_images_but_keeps_text(): void
    {
        $out = HtmlSanitizer::clean('<a href="javascript:evil()">click</a><img src=x onerror=evil()>');

        self::assertStringNotContainsString('<a', $out);
        self::assertStringNotContainsString('<img', $out);
        self::assertStringNotContainsString('javascript', $out);
        self::assertStringContainsString('click', $out);
    }

    public function test_keeps_allowed_formatting_tags(): void
    {
        self::assertSame('<ul><li><strong>a</strong></li></ul>', HtmlSanitizer::clean('<ul><li><strong>a</strong></li></ul>'));
    }

    public function test_plain_text_is_escaped_into_paragraphs(): void
    {
        $out = HtmlSanitizer::fromPlainText("First <b>line</b>\nsecond\n\nNext & last");

        self::assertSame('<p>First &lt;b&gt;line&lt;/b&gt;<br>second</p><p>Next &amp; last</p>', $out);
    }

    public function test_empty_plain_text_yields_empty_string(): void
    {
        self::assertSame('', HtmlSanitizer::fromPlainText("  \n "));
    }

    public function test_slug(): void
    {
        self::assertSame('senior-driver-dubai', Slug::make('  Senior Driver — Dubai! '));
        self::assertSame('job', Slug::make('***'));
        self::assertLessThanOrEqual(20, strlen(Slug::make(str_repeat('a', 100), 20)));
    }
}
