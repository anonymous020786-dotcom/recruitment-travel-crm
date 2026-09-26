<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Cms\CmsFormatter;
use App\Cms\CmsRenderer;
use App\Cms\LineDiff;
use App\Cms\SeoAnalyzer;
use PHPUnit\Framework\TestCase;

/** What an editor types becomes safe HTML: escaped first, then only allowlisted markup. */
final class CmsFormatterTest extends TestCase
{
    public function test_headings_get_unique_ids_and_the_page_never_gets_a_second_h1(): void
    {
        $html = CmsFormatter::toHtml("# Big\n\n## Big\n\n### Small thing\n\n#### Tiny");
        self::assertStringContainsString('<h2 id="big">Big</h2>', $html);
        self::assertStringContainsString('<h2 id="big-2">Big</h2>', $html, 'a repeated heading gets its own id');
        self::assertStringContainsString('<h3 id="small-thing">Small thing</h3>', $html);
        self::assertStringContainsString('<h4 id="tiny">Tiny</h4>', $html);
        self::assertStringNotContainsString('<h1', $html);
        self::assertStringContainsString('id="section"', CmsFormatter::toHtml('## !!!'), 'a heading with nothing to slug still gets an id');
    }

    public function test_lists_quotes_rules_and_paragraphs(): void
    {
        $html = CmsFormatter::toHtml("- one\n- two with **bold**\n\n1. first\n2) second\n\n> quoted\n> more\n\n---\n\nLine one\nline two");
        self::assertStringContainsString('<ul><li>one</li><li>two with <strong>bold</strong></li></ul>', $html);
        self::assertStringContainsString('<ol><li>first</li><li>second</li></ol>', $html);
        self::assertStringContainsString('<blockquote><p>quoted<br>more</p></blockquote>', $html);
        self::assertStringContainsString('<hr>', $html);
        self::assertStringContainsString('<p>Line one<br>line two</p>', $html);
        self::assertSame('', CmsFormatter::toHtml("  \n\n "));
    }

    public function test_code_blocks_keep_blank_lines_and_are_never_formatted(): void
    {
        $html = CmsFormatter::toHtml("```php\n\$a = '<b>x</b>';\n\n**not bold** ## not a heading\n```\n\nafter");
        self::assertStringContainsString("<pre><code>\$a = '&lt;b&gt;x&lt;/b&gt;';\n\n**not bold** ## not a heading</code></pre>", str_replace('&#039;', "'", $html));
        self::assertStringContainsString('<p>after</p>', $html);
        self::assertStringContainsString('<code>a &lt;b&gt; b</code>', CmsFormatter::toHtml('inline `a <b> b` code'));
        self::assertStringNotContainsString('<strong>', CmsFormatter::toHtml('`**x**`'), 'nothing inside a code span is formatted');
        self::assertStringContainsString('<pre><code>open fence', CmsFormatter::toHtml("```\nopen fence\nruns on"), 'an unclosed fence runs to the end and stays safe');
    }

    public function test_tables_are_aligned_padded_and_escaped(): void
    {
        $html = CmsFormatter::toHtml("| Country | Salary | Note |\n|:---|---:|:---:|\n| UAE | 2,000 | <b>x</b> |\n| Qatar |");
        self::assertStringContainsString('<th class="text-left" scope="col">Country</th>', $html);
        self::assertStringContainsString('<th class="text-right" scope="col">Salary</th>', $html);
        self::assertStringContainsString('<th class="text-center" scope="col">Note</th>', $html);
        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        self::assertStringContainsString('<td class="text-left">Qatar</td><td class="text-right"></td><td class="text-center"></td>', $html, 'short rows are padded');
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('<p>a | b</p>', CmsFormatter::toHtml('a | b'), 'a pipe alone is not a table');
    }

    public function test_only_safe_links_become_links(): void
    {
        $ok = CmsFormatter::toHtml('[a](https://example.com/x?y=1) [b](/jobs) [c](#top) [d](mailto:hr@example.com) [e](tel:+911234567890)');
        self::assertStringContainsString('<a href="https://example.com/x?y=1" rel="noopener">a</a>', $ok);
        self::assertStringContainsString('<a href="/jobs">b</a>', $ok);
        self::assertStringContainsString('<a href="#top">c</a>', $ok);
        self::assertStringContainsString('<a href="mailto:hr@example.com">d</a>', $ok);
        self::assertStringContainsString('<a href="tel:+911234567890">e</a>', $ok);

        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html;base64,AAAA', '//evil.example/x', 'ftp://x.example/f', 'vbscript:x', 'https://x.example/a"onmouseover="alert(1)', ' javascript:alert(1)'] as $bad) {
            $html = CmsFormatter::toHtml("[click]({$bad})");
            self::assertDoesNotMatchRegularExpression('/<a\b[^>]*\bhref="\s*(?:javascript|data|vbscript|ftp|\/\/)/i', $html, $bad);
            self::assertStringNotContainsString(' onmouseover=', $html, $bad);
        }
    }

    public function test_images_need_https_or_a_site_path_and_escape_everything(): void
    {
        $html = CmsFormatter::toHtml('![A "nice" <b>view</b>](/media/a.jpg "Caption <i>here</i>")');
        self::assertStringContainsString('<figure><img src="/media/a.jpg" alt="A &quot;nice&quot; &lt;b&gt;view&lt;/b&gt;" loading="lazy" decoding="async"><figcaption>Caption &lt;i&gt;here&lt;/i&gt;</figcaption></figure>', $html);
        self::assertStringContainsString('src="https://cdn.example.com/x.png"', CmsFormatter::toHtml('![x](https://cdn.example.com/x.png)'));
        foreach (['javascript:alert(1)', 'data:image/svg+xml;base64,AAAA', 'http://insecure.example/x.png', '//evil.example/x.png', 'https://x.example/a.png" onerror="alert(1)'] as $bad) {
            $html = CmsFormatter::toHtml("![x]({$bad})");
            self::assertStringNotContainsString('<img', $html, $bad);
            self::assertDoesNotMatchRegularExpression('/<[a-z][^>]*\sonerror=/i', $html, $bad);
        }
    }

    public function test_shortcodes_are_an_allowlist_with_checked_arguments(): void
    {
        $sc = static fn (string $name, string $arg): string => '<div class="cms-sc" data-sc="' . $name . '" data-a="' . $arg . '"></div>';
        self::assertSame($sc('jobs', '6'), CmsFormatter::toHtml('{{jobs:6}}'));
        self::assertSame($sc('packages', '3'), CmsFormatter::toHtml('{{ packages : 3 }}'));
        self::assertSame($sc('toc', ''), CmsFormatter::toHtml('{{toc}}'));
        self::assertSame($sc('contact', ''), CmsFormatter::toHtml('{{contact}}'));
        self::assertSame($sc('contact', 'Talk to us'), CmsFormatter::toHtml('{{contact:Talk to us}}'));
        self::assertSame($sc('youtube', 'dQw4w9WgXcQ'), CmsFormatter::toHtml('{{youtube:dQw4w9WgXcQ}}'));
        self::assertSame($sc('button', '/jobs|Browse jobs'), CmsFormatter::toHtml('{{button:/jobs|Browse jobs}}'));
        self::assertSame($sc('snippet', 'footer-cta'), CmsFormatter::toHtml('{{snippet:footer-cta}}'));

        foreach (['{{jobs:abc}}', '{{jobs}}', '{{jobs:123}}', '{{phone:1}}', '{{unknown}}', '{{youtube:short}}', '{{button:javascript:alert(1)|x}}', '{{button://evil.example|x}}', '{{snippet:Bad_Key}}', '{{contact:<script>}}', '{{system:ls}}'] as $bad) {
            $html = CmsFormatter::toHtml($bad);
            self::assertStringNotContainsString('cms-sc', $html, $bad);
            self::assertStringNotContainsString('<script', $html, $bad);
            self::assertStringStartsWith('<p>', $html, "{$bad} stays visible as text");
        }
        self::assertStringNotContainsString('cms-sc', CmsFormatter::toHtml('text {{jobs:6}} inside a sentence'), 'only a shortcode on its own line is expanded');
    }

    public function test_an_editor_cannot_forge_a_placeholder_or_use_our_internal_markers(): void
    {
        $html = CmsFormatter::toHtml('<div class="cms-sc" data-sc="jobs" data-a="6"></div>');
        self::assertStringNotContainsString('<div class="cms-sc"', $html);
        self::assertStringContainsString('&lt;div class=&quot;cms-sc&quot;', $html);
        $html = CmsFormatter::toHtml("a `code` b \x010\x02 c");
        self::assertStringNotContainsString("\x01", $html);
        self::assertSame(1, substr_count($html, '<code>'));
    }

    public function test_a_hostile_corpus_never_produces_script_or_event_handlers(): void
    {
        $payloads = [
            '<script>alert(1)</script>', '"><script>alert(1)</script>', '<img src=x onerror=alert(1)>', '<svg/onload=alert(1)>', '<iframe src=//evil></iframe>',
            "[x](javascript:alert(1))\n\n[y](https://a.example/\"><script>alert(1)</script>)", '**<script>alert(1)</script>**', '*<img src=x onerror=alert(1)>*',
            '## <script>alert(1)</script>', '- <script>alert(1)</script>', '> <script>alert(1)</script>', '| <script>alert(1)</script> |' . "\n|---|\n| <img onerror=alert(1)> |",
            '![<script>](/x.png "<script>")', '`<script>alert(1)</script>`', "```\n<script>alert(1)</script>\n```", '&lt;script&gt;', '<!-- --><script>', "\x00<script>",
            '[a](https://x.example/&quot;onmouseover=&quot;alert(1))', '<a href="javascript:alert(1)">x</a>', '{{button:https://x.example/"onclick="alert(1)|x}}',
        ];
        foreach ($payloads as $p) {
            $html = CmsFormatter::toHtml($p);
            self::assertStringNotContainsString('<script', $html, $p);
            self::assertStringNotContainsString('<iframe', $html, $p);
            self::assertStringNotContainsString('<svg', $html, $p);
            self::assertDoesNotMatchRegularExpression('/<[a-z][^>]*\s(?:on\w+)=/i', $html, $p);
            self::assertDoesNotMatchRegularExpression('/(?:href|src)="\s*javascript:/i', $html, $p);
        }
    }

    public function test_word_count_and_plain_text_ignore_markup_and_placeholders(): void
    {
        $html = CmsFormatter::toHtml("## Title here\n\nOne two **three** four.\n\n{{jobs:6}}\n\n- five\n- six");
        self::assertSame(8, CmsFormatter::wordCount($html));
        self::assertSame('Title here One two three four. five six', CmsFormatter::plainText($html, 200));
        $long = CmsFormatter::toHtml(str_repeat('word ', 100));
        $short = CmsFormatter::plainText($long, 50);
        self::assertLessThanOrEqual(50, mb_strlen($short));
        self::assertStringEndsWith('…', $short);
        self::assertSame(0, CmsFormatter::wordCount(''));
    }

    // ---- the renderer's parts that need no database ---------------------------------------------------------

    public function test_headings_can_be_read_back_for_the_contents_list(): void
    {
        $h = CmsRenderer::headings(CmsFormatter::toHtml("## One & two\n\n### Sub\n\n#### Deep"));
        self::assertSame([['level' => 2, 'id' => 'one-two', 'text' => 'One & two'], ['level' => 3, 'id' => 'sub', 'text' => 'Sub'], ['level' => 4, 'id' => 'deep', 'text' => 'Deep']], $h);
    }

    public function test_the_faq_block_escapes_questions_and_answers(): void
    {
        $html = CmsRenderer::faq([['q' => 'Is it <b>safe</b>?', 'a' => "Yes.\n<script>alert(1)</script>"]]);
        self::assertStringContainsString('<summary>Is it &lt;b&gt;safe&lt;/b&gt;?</summary>', $html);
        self::assertStringContainsString('<p>Yes.</p><p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertSame('', CmsRenderer::faq([]));
    }

    // ---- the diff and the SEO checklist ---------------------------------------------------------------------

    public function test_the_line_diff_marks_added_removed_and_unchanged_lines(): void
    {
        $d = LineDiff::compare("a\nb\nc\nd", "a\nB\nc\nd\ne");
        self::assertSame([['=', 'a'], ['-', 'b'], ['+', 'B'], ['=', 'c'], ['=', 'd'], ['+', 'e']], array_map(static fn (array $r): array => [$r['op'], $r['text']], $d));
        self::assertSame(['added' => 2, 'removed' => 1], LineDiff::stats($d));
        self::assertSame(['added' => 0, 'removed' => 0], LineDiff::stats(LineDiff::compare("x\ny", "x\ny")));
        self::assertSame([['op' => '+', 'text' => 'new']], LineDiff::compare('', 'new'));
        self::assertSame([['op' => '-', 'text' => 'gone']], LineDiff::compare('gone', ''));
        $big = implode("\n", range(1, 2000));
        self::assertNotSame([], LineDiff::compare($big, $big . "\nx"), 'huge inputs are handled without exhausting memory');
    }

    public function test_the_seo_checklist_reports_what_is_good_and_what_is_missing(): void
    {
        $words = implode(' ', array_fill(0, 60, 'Travel with us today. We help you go abroad safely and legally.'));
        $good = SeoAnalyzer::analyze([
            'title' => 'Overseas jobs for skilled workers', 'meta_title' => 'Overseas jobs for skilled workers in the Gulf', 'meta_description' => str_repeat('Find verified overseas jobs with visa support. ', 2),
            'path' => 'overseas-jobs-gulf', 'focus_keyword' => 'overseas jobs', 'featured_image' => '/a.jpg', 'featured_alt' => 'Workers',
            'body_html' => CmsFormatter::toHtml("Overseas jobs are here. {$words}\n\n## Why us\n\nSee [our jobs](/overseas-jobs)."),
        ]);
        self::assertGreaterThanOrEqual(75, $good['score']);
        $levels = array_count_values(array_column($good['checks'], 'level'));
        self::assertArrayNotHasKey('bad', $levels);

        $poor = SeoAnalyzer::analyze(['title' => 'Hi', 'body_html' => '<p>short</p>', 'robots' => 'noindex'], 2);
        self::assertLessThan(40, $poor['score']);
        $text = implode(' | ', array_column($poor['checks'], 'text'));
        foreach (['No meta description', 'Only 1 words', 'Another page uses the same search title', 'noindex', 'No featured image'] as $needle) {
            self::assertStringContainsString($needle, $text);
        }
        self::assertLessThanOrEqual(100, SeoAnalyzer::analyze([])['score']);
    }
}
