<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\HtmlAudit;
use PHPUnit\Framework\TestCase;

/** The rule engine behind scripts/html-audit.php: good markup is clean, each classic mistake trips its own rule. */
final class HtmlAuditTest extends TestCase
{
    private const GOOD_APP = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Leads</title></head>
<body><a href="#main">Skip to content</a><nav aria-label="Main"><a href="/leads">Leads</a></nav>
<main id="main"><h1>Leads</h1><form method="post" action="/x"><input type="hidden" name="_token" value="t"><label for="q">Search</label><input id="q" name="q">
<button type="submit">Go</button></form><div class="table-wrap"><table aria-label="Leads"><thead><tr><th>Name</th></tr></thead><tbody><tr><td>A</td></tr></tbody></table></div>
<a href="/x" target="_blank" rel="noopener">Doc</a><img src="a.png" alt="" width="1" height="1"></main></body></html>';

    /** @return list<string> rule names */
    private function rules(string $html, string $kind = 'app', array $headers = ['x-robots-tag' => 'noindex']): array
    {
        return array_values(array_unique(array_column((new HtmlAudit())->check($html, $kind, $headers), 'rule')));
    }

    private static function with(string $find, string $replace): string
    {
        self::assertStringContainsString($find, self::GOOD_APP, 'fixture drifted');

        return str_replace($find, $replace, self::GOOD_APP);
    }

    public function test_well_formed_markup_has_no_findings(): void
    {
        self::assertSame([], (new HtmlAudit())->check(self::GOOD_APP, 'app', ['x-robots-tag' => 'noindex, nofollow']));
    }

    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function mistakes(): array
    {
        return [
            'no lang'                => ['<html lang="en">', '<html>', 'html-lang'],
            'no title'               => ['<title>Leads</title>', '', 'title'],
            'no viewport'            => ['<meta name="viewport" content="width=device-width, initial-scale=1">', '', 'viewport'],
            'zoom locked'            => ['initial-scale=1"', 'initial-scale=1, user-scalable=no"', 'viewport-zoom'],
            'two h1'                 => ['<h1>Leads</h1>', '<h1>Leads</h1><h1>Again</h1>', 'h1'],
            'no h1'                  => ['<h1>Leads</h1>', '<h2>Leads</h2>', 'h1'],
            'heading jump'           => ['<h1>Leads</h1>', '<h1>Leads</h1><h3>Deep</h3>', 'heading-order'],
            'empty heading'          => ['<h1>Leads</h1>', '<h1>Leads</h1><h2> </h2>', 'heading-empty'],
            'no main'                => ['<main id="main">', '<div id="main">', 'main'],
            'no skip link'           => ['<a href="#main">Skip to content</a>', '', 'skip-link'],
            'unlabelled nav'         => ['<nav aria-label="Main"><a href="/leads">Leads</a></nav>', '<nav><a href="/leads">Leads</a></nav><nav><a href="/b">B</a></nav>', 'nav-label'],
            'img without alt'        => ['alt="" width="1" height="1"', 'width="1" height="1"', 'img-alt'],
            'img without size'       => ['alt="" width="1" height="1"', 'alt=""', 'img-size'],
            'input without label'    => ['<label for="q">Search</label><input id="q" name="q">', '<input id="q" name="q">', 'form-label'],
            'password autocomplete'  => ['<button type="submit">', '<label for="p">Password</label><input id="p" type="password"><button type="submit">', 'autocomplete'],
            'post form without csrf' => ['<input type="hidden" name="_token" value="t">', '', 'csrf-field'],
            'icon-only button'       => ['<button type="submit">Go</button>', '<button type="submit"><svg></svg></button>', 'accessible-name'],
            'inline handler'         => ['<button type="submit">', '<button type="submit" onclick="x()">', 'inline-handler'],
            'duplicate id'           => ['<h1>Leads</h1>', '<h1 id="q">Leads</h1>', 'duplicate-id'],
            'dangling aria ref'      => ['<h1>Leads</h1>', '<h1 aria-labelledby="nope">Leads</h1>', 'aria-ref'],
            '_blank without rel'     => ['target="_blank" rel="noopener"', 'target="_blank"', 'noopener'],
            'vague link text'        => ['>Doc</a>', '>click here</a>', 'link-text'],
            'table without th'       => ['<thead><tr><th>Name</th></tr></thead>', '', 'table-headers'],
            'table cannot scroll'    => ['<div class="table-wrap">', '<div>', 'table-scroll'],
            'unnamed table'          => [' aria-label="Leads"><thead>', '><thead>', 'table-name'],
            'fixed pixel width'      => ['<h1>Leads</h1>', '<h1 style="width: 1200px">Leads</h1>', 'fixed-width'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mistakes')]
    public function test_each_mistake_trips_its_rule(string $find, string $replace, string $rule): void
    {
        self::assertContains($rule, $this->rules(self::with($find, $replace)));
    }

    public function test_hidden_honeypot_fields_and_hidden_inputs_need_no_label(): void
    {
        $html = self::with('<h1>Leads</h1>', '<h1>Leads</h1><input type="text" name="company" tabindex="-1" aria-hidden="true">');

        self::assertNotContains('form-label', $this->rules($html));
    }

    public function test_a_crm_page_must_not_be_indexable(): void
    {
        self::assertContains('noindex', $this->rules(self::GOOD_APP, 'app', []));
        self::assertNotContains('noindex', $this->rules(self::GOOD_APP, 'app', ['x-robots-tag' => 'noindex, nofollow']));
        self::assertNotContains('noindex', $this->rules(str_replace('<title>', '<meta name="robots" content="noindex"><title>', self::GOOD_APP), 'app', []));
    }

    public function test_public_pages_need_complete_seo_metadata(): void
    {
        $head = '<title>Overseas jobs and travel packages</title><meta name="description" content="Find verified overseas jobs, recruitment help and travel packages from a licensed agency you can call."><link rel="canonical" href="https://x.in/">'
            . '<meta property="og:title" content="t"><meta property="og:description" content="d"><meta property="og:type" content="website"><meta property="og:url" content="https://x.in/"><meta property="og:image" content="https://x.in/i.png">'
            . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Organization","name":"X"}</script>';
        $complete = str_replace('<title>Leads</title>', $head, self::GOOD_APP);

        self::assertSame([], $this->rules($complete, 'public', []));

        $bare = $this->rules(self::GOOD_APP, 'public', []);
        foreach (['seo-description', 'seo-canonical', 'seo-og', 'seo-og-image', 'seo-jsonld-missing'] as $rule) {
            self::assertContains($rule, $bare);
        }
        self::assertContains('seo-canonical', $this->rules(str_replace('https://x.in/">', '/relative">', $complete), 'public', []), 'a relative canonical is not enough');
        self::assertContains('seo-jsonld', $this->rules(str_replace('{"@context"', '{broken', $complete), 'public', []));
        self::assertContains('seo-noindex', $this->rules(str_replace('<title>', '<meta name="robots" content="noindex"><title>', $complete), 'public', []));
        self::assertContains('seo-title-length', $this->rules(str_replace('Overseas jobs and travel packages', 'Hi', $complete), 'public', []));
    }
}
