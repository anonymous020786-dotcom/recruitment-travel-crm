<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Static accessibility / responsiveness / SEO checks on one rendered HTML page (Phase 12 audit).
 *
 * It cannot judge colour contrast or real layout — those need a browser — but it catches the structural mistakes that
 * cause most failures: missing labels and names, broken heading order, missing landmarks, duplicate ids, unlabelled icon
 * buttons, tables that cannot scroll on a phone, and (for the public site) missing SEO metadata.
 *
 * kind = 'app'    signed-in CRM page: must NOT be indexable, otherwise the same structural rules
 * kind = 'public' marketing page: title / description / canonical / Open Graph / JSON-LD are required
 *
 * @phpstan-type Finding array{rule:string,severity:string,message:string,snippet:string}
 */
final class HtmlAudit
{
    public const ERROR = 'error';
    public const WARN = 'warn';

    /** @var list<array{rule:string,severity:string,message:string,snippet:string}> */
    private array $findings = [];
    private \DOMXPath $xp;
    private \DOMDocument $doc;

    /**
     * @param array<string,string> $headers lower-cased response headers (used for the robots check)
     * @return list<array{rule:string,severity:string,message:string,snippet:string}>
     */
    public function check(string $html, string $kind = 'app', array $headers = []): array
    {
        $this->findings = [];
        $this->doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $this->doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $this->xp = new \DOMXPath($this->doc);

        $this->document();
        $this->headings();
        $this->landmarks();
        $this->images();
        $this->formControls();
        $this->names();
        $this->ids();
        $this->links();
        $this->tables();
        $this->misc();
        $kind === 'public' ? $this->seoPublic() : $this->seoApp($headers);

        return $this->findings;
    }

    // ---- rules ------------------------------------------------------------------------

    private function document(): void
    {
        $html = $this->one('//html');
        if ($html === null || trim($html->getAttribute('lang')) === '') {
            $this->add('html-lang', self::ERROR, '<html> has no lang attribute (screen readers pick the wrong voice).', '<html>');
        }
        $title = trim((string) $this->one('//head/title')?->textContent);
        if ($title === '') {
            $this->add('title', self::ERROR, 'The page has no <title>.', '<head>');
        }
        $viewport = $this->one('//meta[@name="viewport"]');
        $content = $viewport?->getAttribute('content') ?? '';
        if ($viewport === null || !str_contains($content, 'width=device-width')) {
            $this->add('viewport', self::ERROR, 'Missing <meta name="viewport" content="width=device-width, initial-scale=1"> — the page is not responsive.', '<head>');
        } elseif (preg_match('/user-scalable\s*=\s*(no|0)|maximum-scale\s*=\s*1(\.0)?\b/i', $content)) {
            $this->add('viewport-zoom', self::ERROR, 'The viewport blocks pinch-zoom (WCAG 1.4.4).', $content);
        }
    }

    private function headings(): void
    {
        $h1 = $this->nodes('//h1');
        if (count($h1) !== 1) {
            $this->add('h1', self::ERROR, 'Expected exactly one <h1>, found ' . count($h1) . '.', '');
        }
        $prev = 0;
        foreach ($this->nodes('//h1|//h2|//h3|//h4|//h5|//h6') as $h) {
            $level = (int) substr($h->nodeName, 1);
            if ($prev !== 0 && $level > $prev + 1) {
                $this->add('heading-order', self::WARN, "Heading jumps from h{$prev} to h{$level}.", $this->snip($h));
            }
            if (trim($h->textContent) === '') {
                $this->add('heading-empty', self::ERROR, 'Empty heading.', $this->snip($h));
            }
            $prev = $level;
        }
    }

    private function landmarks(): void
    {
        $main = $this->nodes('//main|//*[@role="main"]');
        if (count($main) !== 1) {
            $this->add('main', self::ERROR, 'Expected exactly one <main> landmark, found ' . count($main) . '.', '');
        }
        $navs = $this->nodes('//nav');
        if (count($navs) > 1) {
            foreach ($navs as $n) {
                if (trim($n->getAttribute('aria-label') . $n->getAttribute('aria-labelledby')) === '') {
                    $this->add('nav-label', self::WARN, 'With several <nav> landmarks each needs an aria-label.', $this->snip($n));
                }
            }
        }
        // A "skip to content" link should be one of the first focusable things.
        $first = $this->nodes('(//a[@href])[position() <= 3]');
        $skip = false;
        foreach ($first as $a) {
            $skip = $skip || (str_starts_with($a->getAttribute('href'), '#') && $a->getAttribute('href') !== '#');
        }
        if (!$skip) {
            $this->add('skip-link', self::WARN, 'No "skip to content" link near the top — keyboard users must tab through the whole navigation.', '');
        }
    }

    private function images(): void
    {
        foreach ($this->nodes('//img') as $img) {
            if (!$img->hasAttribute('alt')) {
                $this->add('img-alt', self::ERROR, '<img> without an alt attribute (use alt="" if decorative).', $this->snip($img));
            }
            if ($img->getAttribute('width') === '' && $img->getAttribute('height') === '' && !str_contains($img->getAttribute('class'), 'w-') && !str_contains($img->getAttribute('style'), 'width')) {
                $this->add('img-size', self::WARN, '<img> has no width/height — the layout will jump as it loads.', $this->snip($img));
            }
        }
    }

    private function formControls(): void
    {
        foreach ($this->nodes('//input[not(@type="hidden" or @type="submit" or @type="button" or @type="reset" or @type="image")]|//select|//textarea') as $el) {
            if ($el->getAttribute('aria-hidden') === 'true') {
                continue; // e.g. the anti-spam honeypot: hidden from assistive tech on purpose
            }
            if (!$this->hasLabel($el)) {
                $this->add('form-label', self::ERROR, 'Form control has no label (label[for], wrapping <label>, aria-label or aria-labelledby).', $this->snip($el));
            }
            if ($el->nodeName === 'input' && $el->getAttribute('type') === 'password' && $el->getAttribute('autocomplete') === '') {
                $this->add('autocomplete', self::WARN, 'Password field without an autocomplete attribute (password managers rely on it).', $this->snip($el));
            }
            if ($el->getAttribute('tabindex') !== '' && (int) $el->getAttribute('tabindex') > 0) {
                $this->add('tabindex', self::WARN, 'Positive tabindex breaks the natural focus order.', $this->snip($el));
            }
        }
        foreach ($this->nodes('//form') as $form) {
            $method = strtolower($form->getAttribute('method'));
            if ($method === 'post' && $this->one('.//input[@name="_token"]', $form) === null && $form->getAttribute('data-no-csrf') === '') {
                $this->add('csrf-field', self::ERROR, 'POST form without a CSRF token field.', $this->snip($form));
            }
        }
    }

    private function names(): void
    {
        foreach ($this->nodes('//button|//a[@href]|//*[@role="button"]|//input[@type="submit" or @type="button"]') as $el) {
            if ($this->accessibleName($el) === '') {
                $this->add('accessible-name', self::ERROR, 'A ' . $el->nodeName . ' has no accessible name (icon-only controls need aria-label).', $this->snip($el));
            }
        }
        foreach ($this->nodes('//*[@onclick or @onchange or @onsubmit or @onload]') as $el) {
            $this->add('inline-handler', self::ERROR, 'Inline event handler — blocked by the CSP and not keyboard-friendly.', $this->snip($el));
        }
        foreach ($this->nodes('//div[@onclick]|//span[@onclick]') as $el) {
            $this->add('click-div', self::ERROR, 'Clickable <div>/<span>: use a <button> or <a>.', $this->snip($el));
        }
    }

    private function ids(): void
    {
        $seen = [];
        foreach ($this->nodes('//*[@id]') as $el) {
            $id = $el->getAttribute('id');
            if (isset($seen[$id])) {
                $this->add('duplicate-id', self::ERROR, "Duplicate id \"{$id}\".", $this->snip($el));
            }
            $seen[$id] = true;
        }
        foreach ($this->nodes('//*[@aria-labelledby or @aria-describedby or @aria-controls]') as $el) {
            foreach (['aria-labelledby', 'aria-describedby', 'aria-controls'] as $attr) {
                foreach (preg_split('/\s+/', trim($el->getAttribute($attr))) ?: [] as $ref) {
                    if ($ref !== '' && !isset($seen[$ref])) {
                        $this->add('aria-ref', self::ERROR, "{$attr} points at a missing id \"{$ref}\".", $this->snip($el));
                    }
                }
            }
        }
    }

    private function links(): void
    {
        foreach ($this->nodes('//a[@target="_blank"]') as $a) {
            $rel = strtolower($a->getAttribute('rel'));
            if (!str_contains($rel, 'noopener') && !str_contains($rel, 'noreferrer')) {
                $this->add('noopener', self::ERROR, 'target="_blank" link without rel="noopener".', $this->snip($a));
            }
        }
        foreach ($this->nodes('//a[not(@href)]') as $a) {
            if ($a->getAttribute('name') === '' && $a->getAttribute('id') === '' && $a->getAttribute('role') === '') {
                $this->add('anchor-no-href', self::WARN, '<a> without href is not focusable — use a <button> for actions.', $this->snip($a));
            }
        }
        foreach ($this->nodes('//a[@href]') as $a) {
            $text = strtolower(trim(preg_replace('/\s+/', ' ', $a->textContent) ?? ''));
            if (in_array($text, ['click here', 'here', 'read more', 'more'], true) && $a->getAttribute('aria-label') === '') {
                $this->add('link-text', self::WARN, "Non-descriptive link text \"{$text}\".", $this->snip($a));
            }
        }
    }

    private function tables(): void
    {
        foreach ($this->nodes('//table') as $t) {
            if ($this->one('.//th', $t) === null) {
                $this->add('table-headers', self::ERROR, 'Data table without any <th> header cells.', $this->snip($t));
            }
            // Wide tables must be able to scroll sideways on a phone instead of stretching the page.
            $scrolls = false;
            for ($p = $t->parentNode; $p instanceof \DOMElement; $p = $p->parentNode) {
                $cls = ' ' . $p->getAttribute('class') . ' ';
                if (str_contains($cls, 'table-wrap') || str_contains($cls, 'overflow-x-auto') || str_contains($cls, 'overflow-auto') || str_contains($cls, 'overflow-x-scroll')) {
                    $scrolls = true;
                    break;
                }
            }
            if (!$scrolls) {
                $this->add('table-scroll', self::ERROR, 'Table is not inside a horizontally scrollable wrapper (.table-wrap) — it will overflow on phones.', $this->snip($t));
            }
            if ($t->getElementsByTagName('caption')->length === 0 && $t->getAttribute('aria-label') === '' && $t->getAttribute('aria-labelledby') === '') {
                $this->add('table-name', self::WARN, 'Table has no caption or aria-label naming it.', $this->snip($t));
            }
        }
    }

    private function misc(): void
    {
        foreach ($this->nodes('//meta[@http-equiv="refresh"]') as $m) {
            $this->add('meta-refresh', self::ERROR, 'Auto-refresh (WCAG 2.2.1).', $this->snip($m));
        }
        foreach ($this->nodes('//*[@style]') as $el) {
            if (preg_match('/(?<![-a-z])width\s*:\s*(\d{4,})px/i', $el->getAttribute('style'), $m)) {
                $this->add('fixed-width', self::WARN, "Fixed inline width of {$m[1]}px will overflow small screens.", $this->snip($el));
            }
        }
        foreach ($this->nodes('//marquee|//blink|//font|//center') as $el) {
            $this->add('obsolete-element', self::WARN, "<{$el->nodeName}> is obsolete.", $this->snip($el));
        }
    }

    private function seoApp(array $headers): void
    {
        $robots = strtolower($headers['x-robots-tag'] ?? '');
        $meta = strtolower($this->one('//meta[@name="robots"]')?->getAttribute('content') ?? '');
        if (!str_contains($robots, 'noindex') && !str_contains($meta, 'noindex')) {
            $this->add('noindex', self::ERROR, 'A signed-in CRM page must not be indexable (no X-Robots-Tag / robots noindex).', '');
        }
    }

    private function seoPublic(): void
    {
        $title = trim((string) $this->one('//head/title')?->textContent);
        $len = mb_strlen($title);
        if ($title !== '' && ($len < 15 || $len > 65)) {
            $this->add('seo-title-length', self::WARN, "Title is {$len} characters (aim for 15–65).", $title);
        }
        $desc = trim($this->one('//meta[@name="description"]')?->getAttribute('content') ?? '');
        if ($desc === '') {
            $this->add('seo-description', self::ERROR, 'Missing meta description.', '');
        } elseif (mb_strlen($desc) < 50 || mb_strlen($desc) > 165) {
            $this->add('seo-description-length', self::WARN, 'Meta description is ' . mb_strlen($desc) . ' characters (aim for 50–160).', $desc);
        }
        $canonical = $this->one('//link[@rel="canonical"]')?->getAttribute('href') ?? '';
        if ($canonical === '' || !preg_match('#^https?://#', $canonical)) {
            $this->add('seo-canonical', self::ERROR, 'Missing or relative canonical URL.', $canonical);
        }
        foreach (['og:title', 'og:description', 'og:type', 'og:url'] as $p) {
            if (trim($this->one('//meta[@property="' . $p . '"]')?->getAttribute('content') ?? '') === '') {
                $this->add('seo-og', self::WARN, "Missing Open Graph tag {$p}.", '');
            }
        }
        if ($this->one('//meta[@property="og:image"]') === null) {
            $this->add('seo-og-image', self::WARN, 'No og:image — link previews will have no picture.', '');
        }
        $robots = strtolower($this->one('//meta[@name="robots"]')?->getAttribute('content') ?? '');
        if (str_contains($robots, 'noindex')) {
            $this->add('seo-noindex', self::ERROR, 'A public page is marked noindex.', $robots);
        }
        foreach ($this->nodes('//script[@type="application/ld+json"]') as $s) {
            if (json_decode($s->textContent, true) === null) {
                $this->add('seo-jsonld', self::ERROR, 'Structured data (JSON-LD) is not valid JSON.', mb_substr(trim($s->textContent), 0, 80));
            }
        }
        if ($this->one('//script[@type="application/ld+json"]') === null) {
            $this->add('seo-jsonld-missing', self::WARN, 'No structured data (JSON-LD) on this page.', '');
        }
    }

    // ---- helpers ------------------------------------------------------------------------

    private function hasLabel(\DOMElement $el): bool
    {
        if (trim($el->getAttribute('aria-label')) !== '' || trim($el->getAttribute('aria-labelledby')) !== '' || trim($el->getAttribute('title')) !== '') {
            return true;
        }
        $id = $el->getAttribute('id');
        if ($id !== '' && $this->one('//label[@for="' . str_replace('"', '', $id) . '"]') !== null) {
            return true;
        }
        for ($p = $el->parentNode; $p instanceof \DOMElement; $p = $p->parentNode) {
            if ($p->nodeName === 'label') {
                return true;
            }
        }

        return false;
    }

    private function accessibleName(\DOMElement $el): string
    {
        foreach (['aria-label', 'title'] as $a) {
            if (trim($el->getAttribute($a)) !== '') {
                return trim($el->getAttribute($a));
            }
        }
        if ($el->nodeName === 'input' && trim($el->getAttribute('value')) !== '') {
            return trim($el->getAttribute('value'));
        }
        if (trim($el->getAttribute('aria-labelledby')) !== '') {
            return 'labelled';
        }
        $text = trim(preg_replace('/\s+/', ' ', $el->textContent) ?? '');
        if ($text !== '') {
            return $text;
        }
        foreach ($el->getElementsByTagName('img') as $img) {
            if (trim($img->getAttribute('alt')) !== '') {
                return trim($img->getAttribute('alt'));
            }
        }
        foreach ($el->getElementsByTagName('*') as $child) {
            if (trim($child->getAttribute('aria-label')) !== '' || ($child->nodeName === 'title' && trim($child->textContent) !== '')) {
                return 'nested-label';
            }
        }

        return '';
    }

    /** @return list<\DOMElement> */
    private function nodes(string $query, ?\DOMNode $ctx = null): array
    {
        $out = [];
        foreach ($this->xp->query($query, $ctx) ?: [] as $n) {
            if ($n instanceof \DOMElement) {
                $out[] = $n;
            }
        }

        return $out;
    }

    private function one(string $query, ?\DOMNode $ctx = null): ?\DOMElement
    {
        return $this->nodes($query, $ctx)[0] ?? null;
    }

    private function snip(\DOMElement $el): string
    {
        $html = (string) $this->doc->saveHTML($el);

        return mb_substr((string) preg_replace('/\s+/', ' ', $html), 0, 140);
    }

    private function add(string $rule, string $severity, string $message, string $snippet): void
    {
        $this->findings[] = ['rule' => $rule, 'severity' => $severity, 'message' => $message, 'snippet' => $snippet];
    }
}
