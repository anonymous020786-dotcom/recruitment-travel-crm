<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\HtmlMinifier;
use PHPUnit\Framework\TestCase;
use Tests\Support\HtmlSignature;

/** The minifier may shrink a page but must never change what it renders. */
final class HtmlMinifierTest extends TestCase
{
    public function test_whitespace_and_comments_go_but_visible_spacing_stays(): void
    {
        $html = "<!doctype html>\n<html lang=\"en\">\n<head>\n    <meta charset=\"utf-8\">\n    <title>  A   title </title>\n    <!-- a comment -->\n</head>\n<body>\n"
            . "    <div  class=\"a   b\"\n         id=\"x\">\n        <p>Hello   <b>bold</b> <i>italic</i>\n        world.</p>\n        <a href=\"/a\">One</a> <a href=\"/b\">Two</a>\n    </div>\n</body>\n</html>\n";

        $min = HtmlMinifier::minify($html);

        self::assertSame(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>A title</title></head><body><div class="a   b" id="x"><p>Hello <b>bold</b> <i>italic</i> world.</p><a href="/a">One</a> <a href="/b">Two</a></div></body></html>',
            $min,
        );
        self::assertLessThan(strlen($html), strlen($min));
    }

    public function test_pre_textarea_script_and_style_are_copied_byte_for_byte(): void
    {
        $pre = "<pre class=\"x\">\n  line one\n     line   two\n</pre>";
        $area = "<textarea name=\"t\">\n  keep   this\n</textarea>";
        $script = "<script nonce=\"abc\">\n  var a  =  1;   // comment\n  if (a < 2 && b > 1) { go(); }\n</script>";
        $style = "<style>\n  .a   { color : red; }\n</style>";
        $html = "<html><body>\n  <div>\n    {$pre}\n    <form>{$area}</form>\n    {$script}\n    {$style}\n  </div>\n</body></html>";

        $min = HtmlMinifier::minify($html);

        foreach ([$pre, $area, $script, $style] as $block) {
            self::assertStringContainsString($block, $min);
        }
        self::assertStringNotContainsString("\n  <div>", $min);
    }

    public function test_quoted_attribute_values_and_odd_characters_are_never_touched(): void
    {
        $html = "<html><body><a   href='/x?a=1&amp;b=2'  title=\"two  spaces\nand a newline > and <\" data-json='{\"k\":  \"v\"}'>t</a><input  value=\"a  b\"  disabled  /><br  /></body></html>";

        $min = HtmlMinifier::minify($html);

        self::assertStringContainsString("title=\"two  spaces\nand a newline > and <\"", $min);
        self::assertStringContainsString("data-json='{\"k\":  \"v\"}'", $min);
        self::assertStringContainsString('<input value="a  b" disabled />', $min);
        self::assertStringContainsString('<br />', $min);
        self::assertStringContainsString("href='/x?a=1&amp;b=2'", $min);
    }

    public function test_conditional_comments_survive_and_ordinary_ones_do_not(): void
    {
        $html = "<html><head>\n<!--[if lt IE 9]><script src=\"h.js\"></script><![endif]-->\n<!-- remove   me -->\n</head><body><p>a <!-- inline --> b</p></body></html>";

        $min = HtmlMinifier::minify($html);

        self::assertStringContainsString('<!--[if lt IE 9]><script src="h.js"></script><![endif]-->', $min);
        self::assertStringNotContainsString('remove', $min);
        self::assertStringContainsString('<p>a b</p>', $min, 'text either side of a removed comment joins with one space');
    }

    public function test_an_unclosed_raw_element_or_tiny_input_is_returned_unchanged(): void
    {
        $unclosed = "<html><body>\n  <p>x</p>\n  <script>\n  var a = 1;\n  </body></html>";
        self::assertSame($unclosed, HtmlMinifier::minify($unclosed));
        self::assertSame("<p>  hi  </p>", HtmlMinifier::minify("<p>  hi  </p>"), 'not worth touching');
        self::assertSame('', HtmlMinifier::minify(''));
    }

    public function test_it_is_idempotent(): void
    {
        $html = "<!doctype html>\n<html>\n<body>\n<ul>\n  <li> one </li>\n  <li>two <em> x </em></li>\n</ul>\n<pre>  keep </pre>\n</body>\n</html>\n";

        $once = HtmlMinifier::minify($html);

        self::assertSame($once, HtmlMinifier::minify($once));
    }

    public function test_the_structure_of_a_templated_page_is_unchanged(): void
    {
        $rows = '';
        for ($i = 1; $i <= 12; $i++) {
            $rows .= "            <tr class=\"row\">\n                <td>\n                    <a href=\"/leads/{$i}\" class=\"font-medium\">Lead {$i}</a>\n                    <p class=\"text-xs\">lead{$i}@example.test</p>\n                </td>\n                <td>  <span class=\"badge\">New</span>  </td>\n            </tr>\n";
        }
        $html = "<!doctype html>\n<html lang=\"en\">\n<head>\n    <meta charset=\"utf-8\">\n    <title>Leads</title>\n    <link rel=\"stylesheet\" href=\"/a.css\">\n    <script type=\"application/ld+json\">{\"@type\":  \"Thing\"}</script>\n</head>\n<body>\n    <nav aria-label=\"Main\">\n        <ul>\n            <li><a href=\"/x\">X</a></li>\n            <li><a href=\"/y\">Y</a></li>\n        </ul>\n    </nav>\n    <main>\n        <h1>Leads</h1>\n        <form method=\"get\">\n            <label for=\"q\">Search</label>\n            <input id=\"q\" name=\"q\" value=\"a  b\">\n            <button type=\"submit\">Go</button> <button type=\"reset\">Reset</button>\n        </form>\n        <table>\n            <tbody>\n{$rows}            </tbody>\n        </table>\n        <pre>  { \"a\":   1 }  </pre>\n    </main>\n</body>\n</html>\n";

        $min = HtmlMinifier::minify($html);

        self::assertSame(HtmlSignature::of($html), HtmlSignature::of($min));
        self::assertLessThan(strlen($html) * 0.8, strlen($min), 'an indented, templated page should shrink by at least 20%');
    }
}
