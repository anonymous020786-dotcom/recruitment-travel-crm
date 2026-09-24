<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\BlogFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What a blog author types becomes safe HTML — and only the markup the formatter itself makes. */
final class BlogFormatterTest extends TestCase
{
    public function test_the_supported_subset_becomes_the_expected_markup(): void
    {
        $src = "## Visa basics\n\nFirst paragraph\nsecond line.\n\n- one\n- **two**\n\n1. step\n2. *next*\n\n### Detail\n\nSee [our jobs](/overseas-jobs) or [the ministry](https://example.gov/x?a=1&b=2).";

        self::assertSame(
            '<h3>Visa basics</h3><p>First paragraph<br>second line.</p><ul><li>one</li><li><strong>two</strong></li></ul>'
            . '<ol><li>step</li><li><em>next</em></li></ol><h4>Detail</h4>'
            . '<p>See <a href="/overseas-jobs">our jobs</a> or <a href="https://example.gov/x?a=1&amp;b=2" rel="noopener">the ministry</a>.</p>',
            BlogFormatter::toHtml($src),
        );
    }

    public function test_empty_input_and_windows_line_endings(): void
    {
        self::assertSame('', BlogFormatter::toHtml("  \n\r\n "));
        self::assertSame('<p>a<br>b</p><p>c</p>', BlogFormatter::toHtml("a\r\nb\r\n\r\nc"));
    }

    public function test_ordinary_punctuation_is_not_mistaken_for_markup(): void
    {
        self::assertSame('<p>2 * 3 * 4 = 24 and a_b_c</p>', BlogFormatter::toHtml('2 * 3 * 4 = 24 and a_b_c'));
        self::assertSame('<p>#hashtag and ##notaheading</p>', BlogFormatter::toHtml('#hashtag and ##notaheading'));
        self::assertSame('<p>Fish &amp; chips &lt;3</p>', BlogFormatter::toHtml('Fish & chips <3'));
    }

    /** @return array<string,array{string}> */
    public static function hostile(): array
    {
        return [
            'script tag'          => ['<script>alert(1)</script>'],
            'img onerror'         => ['<img src=x onerror=alert(1)>'],
            'javascript link'     => ['[click](javascript:alert(1))'],
            'JaVaScRiPt link'     => ['[click](JaVaScRiPt:alert(1))'],
            'data link'           => ['[click](data:text/html;base64,PHNjcmlwdD4=)'],
            'protocol-relative'   => ['[click](//evil.example/x)'],
            'quote breakout'      => ['[click](https://a.example/"onmouseover="alert(1))'],
            'apostrophe breakout' => ["[click](https://a.example/'onmouseover='alert(1))"],
            'angle in url'        => ['[click](https://a.example/<b>x</b>)'],
            'html in heading'     => ['## <iframe src=//evil.example></iframe>'],
            'html in list'        => ['- <svg onload=alert(1)>'],
            'html in link text'   => ['[<b onclick=alert(1)>x</b>](/ok)'],
            'entity smuggling'    => ['&lt;script&gt;alert(1)&lt;/script&gt; &#60;script&#62;'],
            'markup in url'       => ['[x](https://a.example/**b**)'],
            'nested brackets'     => ['[[x](/a)](/b)'],
        ];
    }

    #[DataProvider('hostile')]
    public function test_hostile_input_can_only_produce_the_allowlisted_markup(string $input): void
    {
        $html = BlogFormatter::toHtml($input);

        preg_match_all('/<(\/?)([a-z0-9]+)([^>]*)>/i', $html, $tags, PREG_SET_ORDER);
        foreach ($tags as [, $closing, $name, $attrs]) {
            self::assertContains(strtolower($name), ['p', 'br', 'h3', 'h4', 'ul', 'ol', 'li', 'strong', 'em', 'a'], "unexpected <{$name}> in {$html}");
            if ($closing === '/') {
                self::assertSame('', $attrs);
            } elseif (strtolower($name) === 'a') {
                // Only an href (an http(s) or site-relative URL, quotes and angle brackets escaped) and rel="noopener".
                self::assertMatchesRegularExpression('/^ href="(?:https?:\/\/|\/(?!\/))[^"<>\']*"(?: rel="noopener")?$/', $attrs, "bad anchor in {$html}");
            } else {
                self::assertSame('', trim($attrs), "attributes on <{$name}> in {$html}");
            }
        }
        self::assertDoesNotMatchRegularExpression('/href="\s*(?:javascript|data|vbscript):/i', $html);
    }

    public function test_plain_text_for_descriptions_drops_the_markup(): void
    {
        $html = BlogFormatter::toHtml("## Title\n\nHello **world** and [a link](/x).\n\n- one\n- two");

        self::assertSame('Title Hello world and a link. one two', BlogFormatter::plainText($html, 200));
        self::assertLessThanOrEqual(20, mb_strlen(BlogFormatter::plainText($html, 20)));
    }
}
