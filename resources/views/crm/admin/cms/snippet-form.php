<?php
/** @var array<string,mixed>|null $s @var list<array{public_id:string,title:string,path:string}> $usedOn @var bool $canPublish */
$creating = $s === null;
$this->layout('layouts.app', ['title' => ($creating ? 'New snippet' : $s['title']) . ' — Snippets', 'currentPath' => '/admin/cms']);
$this->start('content');
$dis = $canPublish ? '' : ' disabled';
$err = static fn (string $k): string => ($e = error($k)) === null ? '' : '<p class="mt-1 text-xs text-red-600" role="alert">' . e($e) . '</p>';
$v = static fn (string $k, string $col, mixed $d = ''): string => (string) old($k, $s[$col] ?? $d);
?>
<?= component('page-header', [
    'title' => $creating ? 'New snippet' : (string) $s['title'],
    'breadcrumbs' => [['label' => 'Pages', 'href' => '/admin/cms'], ['label' => 'Snippets', 'href' => '/admin/cms/snippets'], ['label' => $creating ? 'New' : (string) $s['title']]],
]) ?>
<?php if (($e = error('form')) !== null): ?><div class="mb-4"><?= component('alert', ['type' => 'danger', 'message' => $e]) ?></div><?php endif ?>

<div class="grid gap-6 lg:grid-cols-3">
    <form method="post" action="<?= $creating ? '/admin/cms/snippets' : '/admin/cms/snippets/' . e_attr((string) $s['key_name']) ?>" class="card card-body space-y-4 lg:col-span-2" data-once>
        <?= csrf_field() ?><?php if (!$creating): ?><input type="hidden" name="_method" value="PUT"><?php endif ?>
        <div><label class="form-label" for="sn-key">Key</label>
            <input id="sn-key" class="form-input font-mono" name="key" value="<?= e_attr($v('key', 'key_name')) ?>" maxlength="60" placeholder="office-address" spellcheck="false"<?= $creating ? $dis : ' disabled' ?>>
            <p class="mt-1 text-xs text-slate-500">Lowercase letters, numbers and hyphens. It cannot change later — pages refer to it.</p><?= $err('key') ?></div>
        <div><label class="form-label" for="sn-title">Name</label><input id="sn-title" class="form-input" name="title" value="<?= e_attr($v('title', 'title')) ?>" maxlength="120" required<?= $dis ?>><?= $err('title') ?></div>
        <div><label class="form-label" for="sn-body">Content</label><textarea id="sn-body" class="form-input font-mono text-sm" name="body" rows="12" required<?= $dis ?>><?= e($v('body', 'body_source')) ?></textarea>
            <p class="mt-1 text-xs text-slate-500">Same formatting as pages, including {{contact}}, {{phone}}, {{jobs:3}}… (but not another snippet).</p><?= $err('body') ?></div>
        <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="is_active" value="1" <?= $v('is_active', 'is_active', '1') === '1' ? 'checked' : '' ?><?= $dis ?>> Switched on (off = shows nothing where it is used)</label>
        <?php if ($canPublish): ?><div><button type="submit" class="btn btn-primary">Save snippet</button></div><?php endif ?>
    </form>

    <?php if (!$creating): ?>
        <aside class="space-y-4">
            <div class="card card-body">
                <h2 class="text-sm font-semibold text-slate-900">Insert with</h2>
                <label class="sr-only" for="sn-code">Code to insert</label>
                <input id="sn-code" class="form-input mt-2 font-mono text-xs" readonly value="{{snippet:<?= e_attr((string) $s['key_name']) ?>}}" onfocus="this.select()">
            </div>
            <div class="card card-body">
                <h2 class="text-sm font-semibold text-slate-900">Used on <?= count($usedOn) ?> page(s)</h2>
                <?php if ($usedOn !== []): ?><ul class="mt-2 space-y-1 text-sm"><?php foreach ($usedOn as $p): ?><li><a href="/admin/cms/<?= e_attr($p['public_id']) ?>/edit"><?= e($p['title']) ?></a> <span class="font-mono text-xs text-slate-400">/<?= e($p['path']) ?></span></li><?php endforeach ?></ul><?php endif ?>
                <?php if ($canPublish): ?>
                    <form method="post" action="/admin/cms/snippets/<?= e_attr((string) $s['key_name']) ?>/delete" class="mt-3" data-confirm="Delete this snippet?"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm text-red-600">Delete snippet</button></form>
                    <p class="mt-1 text-xs text-slate-500">Only possible when no page uses it.</p>
                <?php endif ?>
            </div>
        </aside>
    <?php endif ?>
</div>
<?php $this->stop(); ?>
