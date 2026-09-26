<?php
/**
 * @var array<string,mixed>|null $p the page (null when creating) @var bool $canManage @var bool $canPublish @var array<string,string> $templates @var string $tz
 * With a page: @var array{score:int,checks:list<array{level:string,text:string}>} $seo @var int $previewLinks @var int $revisionCount @var int $words @var bool $editable
 */
$creating = $p === null;
$editable = $creating ? $canManage : ($editable ?? false);
$title = $creating ? 'New page' : (string) $p['title'];
$this->layout('layouts.app', ['title' => $title . ' — Pages', 'currentPath' => '/admin/cms']);
$this->start('content');

$id = $creating ? '' : e_attr((string) $p['public_id']);
$val = static fn (string $k, mixed $default = ''): string => (string) old($k, $default);
$cur = static fn (string $k, mixed $default = ''): string => $p === null ? $val($k, $default) : $val($k, $p[$k] ?? $default);
$dis = $editable ? '' : ' disabled';
$state = $creating ? 'draft' : (string) $p['state'];
$tone = ['draft' => 'slate', 'review' => 'amber', 'live' => 'green', 'scheduled' => 'blue', 'expired' => 'slate', 'archived' => 'slate', 'trash' => 'red'];
$label = ['draft' => 'Draft', 'review' => 'In review', 'live' => 'Live', 'scheduled' => 'Scheduled', 'expired' => 'Taken down', 'archived' => 'Archived', 'trash' => 'Trash'];
$local = static function (?string $utc) use ($tz): string {
    if ($utc === null || $utc === '') {
        return '';
    }

    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz))->format('Y-m-d\TH:i');
};
$faq = [];
if (old('faq_q') !== null && is_array(old('faq_q'))) {
    foreach (old('faq_q') as $i => $q) {
        $faq[] = ['q' => (string) $q, 'a' => (string) (old('faq_a')[$i] ?? '')];
    }
} elseif (!$creating && !empty($p['faq'])) {
    $decoded = json_decode((string) $p['faq'], true);
    $faq = is_array($decoded) ? $decoded : [];
}
while (count($faq) < max(3, count($faq) + 2) && count($faq) < 20) {
    $faq[] = ['q' => '', 'a' => ''];
}
$post = static fn (string $action, string $text, string $cls = 'btn-secondary', string $confirm = '', string $extra = ''): string
    => '<form method="post" action="/admin/cms/' . $id . $action . '" class="inline">' . csrf_field() . $extra
        . '<button type="submit" class="btn ' . $cls . ' btn-sm"' . ($confirm !== '' ? ' data-confirm="' . e_attr($confirm) . '"' : '') . '>' . e($text) . '</button></form>';
$err = static function (string $k): string {
    $e = error($k);

    return $e === null ? '' : '<p class="mt-1 text-xs text-red-600" role="alert">' . e($e) . '</p>';
};
?>
<?= component('page-header', [
    'title' => $title,
    'breadcrumbs' => [['label' => 'Pages', 'href' => '/admin/cms'], ['label' => $creating ? 'New page' : $title]],
]) ?>

<?php if (($e = error('form')) !== null): ?><div class="mb-4"><?= component('alert', ['type' => 'danger', 'message' => $e]) ?></div><?php endif ?>
<?php if (($url = session()?->get('preview_url')) !== null): ?>
    <div class="mb-4 card card-body max-w-3xl">
        <label class="form-label" for="preview-url">Preview link (shown once)</label>
        <input id="preview-url" class="form-input font-mono text-xs" readonly value="<?= e_attr((string) $url) ?>" onfocus="this.select()">
        <p class="mt-1 text-xs text-slate-500">Anyone with this link can see the page, even though it is not public, for <?= (int) \App\Services\CmsPageService::PREVIEW_DAYS ?> days.</p>
    </div>
<?php endif ?>

<?php if (!$creating): ?>
    <div class="mb-4 card card-body">
        <div class="flex flex-wrap items-center gap-2">
            <?= component('badge', ['label' => $label[$state] ?? $state, 'color' => $tone[$state] ?? 'slate', 'dot' => true]) ?>
            <span class="text-xs text-slate-500">version <?= (int) $p['version'] ?> · <?= number_format($words) ?> words · /<?= e((string) $p['path']) ?>
                <?php if ($state === 'scheduled'): ?> · goes live <?= e($local((string) $p['publish_at'])) ?> (<?= e($tz) ?>)<?php endif ?>
                <?php if ($p['unpublish_at'] !== null): ?> · taken down <?= e($local((string) $p['unpublish_at'])) ?><?php endif ?></span>
            <span class="ml-auto flex flex-wrap gap-2">
                <a class="btn btn-secondary btn-sm" href="/admin/cms/<?= $id ?>/preview" target="_blank" rel="noopener">Preview<span class="sr-only"> (opens in a new tab)</span></a>
                <a class="btn btn-secondary btn-sm" href="/admin/cms/<?= $id ?>/revisions">History (<?= (int) $revisionCount ?>)</a>
                <?php if ($state === 'live'): ?><a class="btn btn-secondary btn-sm" href="/<?= e_attr((string) $p['path']) ?>" target="_blank" rel="noopener">View live<span class="sr-only"> (opens in a new tab)</span></a><?php endif ?>
            </span>
        </div>
        <?php if ($canManage && $state !== 'trash'): ?>
            <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
                <?php if ($state === 'draft'): echo $post('/submit', 'Submit for review'); endif ?>
                <?php if ($state === 'review' && $canPublish): echo $post('/send-back', 'Send back to draft'); endif ?>
                <?php if ($canPublish && in_array($p['status'], ['draft', 'review', 'archived'], true)): ?>
                    <form method="post" action="/admin/cms/<?= $id ?>/publish" class="inline-flex flex-wrap items-center gap-2"><?= csrf_field() ?>
                        <label class="sr-only" for="go-live">Go-live time (<?= e($tz) ?>), empty = now</label>
                        <input id="go-live" class="form-input w-52 py-1 text-xs" type="datetime-local" name="publish_at" value="">
                        <button type="submit" class="btn btn-primary btn-sm">Publish</button>
                        <span class="text-xs text-slate-500">empty = now; times are <?= e($tz) ?></span>
                    </form>
                <?php endif ?>
                <?php if ($canPublish && $p['status'] === 'published'): echo $post('/unpublish', 'Unpublish', 'btn-secondary', 'Take this page off the website?'); endif ?>
                <?php if ($canPublish && in_array($p['status'], ['draft', 'review', 'published'], true)): echo $post('/archive', 'Archive', 'btn-ghost', 'Archive this page? It is removed from the website.'); endif ?>
                <?php if ($p['status'] === 'archived'): echo $post('/restore', 'Restore to draft'); endif ?>
                <?= $post('/duplicate', 'Duplicate', 'btn-ghost') ?>
                <?= $post('/trash', 'Move to trash', 'btn-ghost text-red-600', 'Move this page to the trash?' . ($p['status'] === 'published' ? ' It is live and will leave the website.' : '')) ?>
            </div>
        <?php elseif ($state === 'trash'): ?>
            <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
                <?php if ($canManage): echo $post('/untrash', 'Restore from trash'); endif ?>
                <?php if ($canPublish): echo $post('/purge', 'Delete for good', 'btn-ghost text-red-600', 'Delete this page and its history permanently?'); endif ?>
            </div>
        <?php endif ?>
    </div>
    <?php if (!$editable): ?><div class="mb-4"><?= component('alert', ['type' => 'info', 'message' => $state === 'trash' ? 'This page is in the trash — restore it to edit.' : ($p['status'] === 'archived' ? 'Archived pages must be restored to a draft before editing.' : 'A live page can only be edited by someone who may publish.')]) ?></div><?php endif ?>
<?php endif ?>

<div class="grid gap-6 lg:grid-cols-3">
<form method="post" action="<?= $creating ? '/admin/cms' : '/admin/cms/' . $id ?>" class="space-y-6 lg:col-span-2" data-once>
    <?= csrf_field() ?>
    <?php if (!$creating): ?><input type="hidden" name="_method" value="PUT"><input type="hidden" name="version" value="<?= (int) $p['version'] ?>"><?php endif ?>

    <section class="card card-body space-y-4" aria-label="Content">
        <div>
            <label class="form-label" for="f-title">Title</label>
            <input id="f-title" class="form-input" name="title" value="<?= e_attr($cur('title')) ?>" maxlength="180" required<?= $dis ?>>
            <?= $err('title') ?>
        </div>
        <div>
            <label class="form-label" for="f-path">Address</label>
            <div class="flex items-center gap-1"><span class="text-sm text-slate-500">/</span>
                <input id="f-path" class="form-input font-mono" name="path" value="<?= e_attr($cur('path')) ?>" maxlength="150" placeholder="visa-services  or  services/visa" spellcheck="false"<?= ($creating || $p['published_at'] === null) ? $dis : ' disabled' ?>></div>
            <p class="mt-1 text-xs text-slate-500">Lowercase letters, numbers and hyphens; up to three parts. <?= $creating ? 'Empty = made from the title.' : ($p['published_at'] !== null ? 'Frozen once a page has been published.' : '') ?></p>
            <?= $err('path') ?>
        </div>
        <div>
            <label class="form-label" for="f-summary">Summary <span class="font-normal text-slate-400">(shown under the title)</span></label>
            <input id="f-summary" class="form-input" name="summary" value="<?= e_attr($cur('summary')) ?>" maxlength="300"<?= $dis ?>>
            <?= $err('summary') ?>
        </div>
        <div>
            <label class="form-label" for="f-template">Layout</label>
            <select id="f-template" class="form-select w-full sm:w-72" name="template"<?= $dis ?>>
                <?php foreach ($templates as $k => $l): ?><option value="<?= e_attr($k) ?>" <?= $cur('template', 'default') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach ?>
            </select>
            <?= $err('template') ?>
        </div>
        <div>
            <label class="form-label" for="f-body">Content</label>
            <textarea id="f-body" class="form-input font-mono text-sm" name="body" rows="22" required<?= $dis ?>><?= e($cur('body_source', $cur('body'))) ?></textarea>
            <?= $err('body') ?>
            <details class="mt-2 text-xs text-slate-600">
                <summary class="cursor-pointer font-medium">Formatting help</summary>
                <p class="mt-2">Pictures: upload them in the <a href="/admin/cms/media" target="_blank" rel="noopener">media library<span class="sr-only"> (opens in a new tab)</span></a> and paste the code it gives you.</p>
                <div class="mt-2 space-y-1">
                    <p><code>## Heading</code>, <code>### Sub-heading</code> · <code>**bold**</code> <code>*italic*</code> <code>`code`</code> · <code>[text](https://…)</code> or <code>[text](/jobs)</code></p>
                    <p><code>- item</code> lists · <code>1. item</code> numbered · <code>&gt; quote</code> · <code>---</code> line · <code>![description](/path/or/https://image.jpg "caption")</code></p>
                    <p>Tables: <code>| A | B |</code> then <code>|---|---|</code> then rows. Code: three backticks on their own line.</p>
                    <p>Blocks on a line of their own: <code>{{jobs:6}}</code> latest jobs · <code>{{packages:3}}</code> latest packages · <code>{{contact:Talk to us}}</code> button · <code>{{button:/jobs|Browse jobs}}</code> · <code>{{phone}}</code> <code>{{whatsapp}}</code> <code>{{email}}</code> · <code>{{toc}}</code> contents · <code>{{faq}}</code> the questions below · <code>{{youtube:VIDEOID}}</code></p>
                </div>
            </details>
        </div>
    </section>

    <section class="card card-body space-y-4" aria-label="Image">
        <h2 class="text-sm font-semibold text-slate-900">Featured image</h2>
        <div class="grid gap-3 sm:grid-cols-2">
            <div><label class="form-label" for="f-img">Image address</label><input id="f-img" class="form-input" name="featured_image" value="<?= e_attr($cur('featured_image')) ?>" placeholder="https://… or /assets/…"<?= $dis ?>><?= $err('featured_image') ?></div>
            <div><label class="form-label" for="f-alt">Describe the image</label><input id="f-alt" class="form-input" name="featured_alt" value="<?= e_attr($cur('featured_alt')) ?>" maxlength="160"<?= $dis ?>><?= $err('featured_alt') ?></div>
        </div>
    </section>

    <section class="card card-body space-y-3" aria-label="Frequently asked questions">
        <h2 class="text-sm font-semibold text-slate-900">Frequently asked questions</h2>
        <p class="text-xs text-slate-500">Shown at the bottom of the page (or where you put <code>{{faq}}</code>) and sent to search engines as FAQ markup.</p>
        <?= $err('faq') ?>
        <?php foreach ($faq as $i => $f): ?>
            <div class="grid gap-2 sm:grid-cols-2">
                <div><label class="sr-only" for="faq-q-<?= $i ?>">Question <?= $i + 1 ?></label><input id="faq-q-<?= $i ?>" class="form-input" name="faq_q[]" value="<?= e_attr((string) $f['q']) ?>" maxlength="200" placeholder="Question"<?= $dis ?>></div>
                <div><label class="sr-only" for="faq-a-<?= $i ?>">Answer <?= $i + 1 ?></label><textarea id="faq-a-<?= $i ?>" class="form-input" name="faq_a[]" rows="2" maxlength="2000" placeholder="Answer"<?= $dis ?>><?= e((string) $f['a']) ?></textarea></div>
            </div>
        <?php endforeach ?>
        <p class="text-xs text-slate-500">Need more rows? Save, and empty ones are added.</p>
    </section>

    <section class="card card-body space-y-4" aria-label="Search engines">
        <h2 class="text-sm font-semibold text-slate-900">Search engines &amp; sharing</h2>
        <div><label class="form-label" for="f-mt">Search title <span class="font-normal text-slate-400">(30–60 characters; empty uses the page title)</span></label><input id="f-mt" class="form-input" name="meta_title" value="<?= e_attr($cur('meta_title')) ?>" maxlength="120"<?= $dis ?>><?= $err('meta_title') ?></div>
        <div><label class="form-label" for="f-md">Meta description <span class="font-normal text-slate-400">(70–160 characters)</span></label><textarea id="f-md" class="form-input" name="meta_description" rows="2" maxlength="200"<?= $dis ?>><?= e($cur('meta_description')) ?></textarea><?= $err('meta_description') ?></div>
        <div class="grid gap-3 sm:grid-cols-2">
            <div><label class="form-label" for="f-kw">Focus keyword</label><input id="f-kw" class="form-input" name="focus_keyword" value="<?= e_attr($cur('focus_keyword')) ?>" maxlength="80"<?= $dis ?>><?= $err('focus_keyword') ?></div>
            <div><label class="form-label" for="f-rb">Search engines</label>
                <select id="f-rb" class="form-select" name="robots"<?= $dis ?>><option value="index" <?= $cur('robots', 'index') === 'index' ? 'selected' : '' ?>>May list this page</option><option value="noindex" <?= $cur('robots') === 'noindex' ? 'selected' : '' ?>>Do not list (noindex)</option></select><?= $err('robots') ?></div>
        </div>
        <div><label class="form-label" for="f-cu">Canonical address <span class="font-normal text-slate-400">(only if this page duplicates another)</span></label><input id="f-cu" class="form-input" name="canonical_url" value="<?= e_attr($cur('canonical_url')) ?>" placeholder="https://…"<?= $dis ?>><?= $err('canonical_url') ?></div>
        <div class="grid gap-3 sm:grid-cols-2">
            <div><label class="form-label" for="f-ot">Share title</label><input id="f-ot" class="form-input" name="og_title" value="<?= e_attr($cur('og_title')) ?>" maxlength="120"<?= $dis ?>><?= $err('og_title') ?></div>
            <div><label class="form-label" for="f-od">Share description</label><input id="f-od" class="form-input" name="og_description" value="<?= e_attr($cur('og_description')) ?>" maxlength="200"<?= $dis ?>><?= $err('og_description') ?></div>
        </div>
        <fieldset class="grid gap-3 sm:grid-cols-3">
            <legend class="mb-1 text-sm font-medium text-slate-700">Sitemap</legend>
            <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="in_sitemap" value="1" <?= ($creating ? old('title') === null || old('in_sitemap') !== null : (bool) $p['in_sitemap']) ? 'checked' : '' ?><?= $dis ?>> List in sitemap.xml</label>
            <div><label class="sr-only" for="f-sp">Priority</label><select id="f-sp" class="form-select" name="sitemap_priority"<?= $dis ?>><?php foreach (['1.0', '0.9', '0.8', '0.7', '0.6', '0.5', '0.4', '0.3', '0.2', '0.1'] as $pr): ?><option value="<?= $pr ?>" <?= number_format((float) $cur('sitemap_priority', '0.5'), 1) === $pr ? 'selected' : '' ?>>Priority <?= $pr ?></option><?php endforeach ?></select><?= $err('sitemap_priority') ?></div>
            <div><label class="sr-only" for="f-sf">Changes</label><select id="f-sf" class="form-select" name="sitemap_changefreq"<?= $dis ?>><?php foreach (\App\Services\CmsPageService::CHANGEFREQ as $cf): ?><option value="<?= $cf ?>" <?= $cur('sitemap_changefreq', 'monthly') === $cf ? 'selected' : '' ?>>Changes <?= $cf ?></option><?php endforeach ?></select><?= $err('sitemap_changefreq') ?></div>
        </fieldset>
    </section>

    <?php if ($canPublish): ?>
        <section class="card card-body" aria-label="Schedule">
            <h2 class="mb-2 text-sm font-semibold text-slate-900">Schedule <span class="font-normal text-slate-400">(times are <?= e($tz) ?>)</span></h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="form-label" for="f-pa">Go live at</label><input id="f-pa" class="form-input" type="datetime-local" name="publish_at" value="<?= e_attr($cur('publish_at') !== '' && !str_contains($cur('publish_at'), 'T') ? $local($cur('publish_at')) : $cur('publish_at')) ?>"<?= $dis ?>><?= $err('publish_at') ?></div>
                <div><label class="form-label" for="f-ua">Take down at</label><input id="f-ua" class="form-input" type="datetime-local" name="unpublish_at" value="<?= e_attr($cur('unpublish_at') !== '' && !str_contains($cur('unpublish_at'), 'T') ? $local($cur('unpublish_at')) : $cur('unpublish_at')) ?>"<?= $dis ?>><?= $err('unpublish_at') ?></div>
            </div>
            <p class="mt-1 text-xs text-slate-500">Applies once the page is published. Leave empty for no limit.</p>
        </section>
    <?php endif ?>

    <?php if ($editable): ?><div><button type="submit" class="btn btn-primary"><?= $creating ? 'Create draft' : 'Save changes' ?></button></div><?php endif ?>
</form>

<aside class="space-y-4" aria-label="Checks">
    <?php if (!$creating): ?>
        <div class="card card-body">
            <h2 class="text-sm font-semibold text-slate-900">SEO checklist <span class="ml-1 <?= $seo['score'] >= 75 ? 'text-green-700' : ($seo['score'] >= 50 ? 'text-amber-700' : 'text-red-700') ?>"><?= (int) $seo['score'] ?>/100</span></h2>
            <p class="mt-1 text-xs text-slate-500">Based on the last saved version. Advice only — it never blocks saving.</p>
            <ul class="mt-2 space-y-1.5 text-sm">
                <?php foreach ($seo['checks'] as $c): ?>
                    <li class="flex gap-2"><span class="<?= $c['level'] === 'ok' ? 'text-green-600' : ($c['level'] === 'warn' ? 'text-amber-600' : 'text-red-600') ?>" aria-hidden="true"><?= $c['level'] === 'ok' ? '✔' : ($c['level'] === 'warn' ? '!' : '✖') ?></span><span class="sr-only"><?= e($c['level'] === 'ok' ? 'Good: ' : ($c['level'] === 'warn' ? 'Could be better: ' : 'Needs work: ')) ?></span><span class="text-slate-700"><?= e($c['text']) ?></span></li>
                <?php endforeach ?>
            </ul>
        </div>
        <div class="card card-body">
            <h2 class="text-sm font-semibold text-slate-900">How it looks in search</h2>
            <?php $sTitle = $cur('meta_title') !== '' ? $cur('meta_title') : (string) $p['title']; $sDesc = $cur('meta_description') !== '' ? $cur('meta_description') : \App\Cms\CmsFormatter::plainText((string) $p['body_html'], 160); ?>
            <p class="mt-2 truncate text-base text-blue-800"><?= e(mb_substr($sTitle, 0, 60)) ?></p>
            <p class="truncate text-xs text-green-800"><?= e(rtrim((string) config('app.url', ''), '/')) ?>/<?= e((string) $p['path']) ?></p>
            <p class="text-xs text-slate-600"><?= e(mb_substr($sDesc, 0, 160)) ?></p>
        </div>
        <?php if ($canManage && $state !== 'trash'): ?>
            <div class="card card-body">
                <h2 class="text-sm font-semibold text-slate-900">Share a preview</h2>
                <p class="mt-1 text-xs text-slate-500">A private link that shows this page before it is public. <?= (int) $previewLinks ?> active link(s).</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <?= $post('/preview-link', 'Create link') ?>
                    <?php if ($previewLinks > 0): echo $post('/preview-link/revoke', 'Revoke all', 'btn-ghost', 'Revoke every preview link for this page?'); endif ?>
                </div>
            </div>
        <?php endif ?>
    <?php else: ?>
        <div class="card card-body text-sm text-slate-600">The SEO checklist, preview links and history appear once the page is created. New pages start as drafts.</div>
    <?php endif ?>
</aside>
</div>
<?php $this->stop(); ?>
