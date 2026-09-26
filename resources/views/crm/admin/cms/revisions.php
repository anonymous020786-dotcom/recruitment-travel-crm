<?php
/**
 * @var array<string,mixed> $page @var list<array<string,mixed>> $revisions @var int $a @var int $b
 * @var list<array{op:string,text:string}>|null $diff @var array{added:int,removed:int}|null $stats @var bool $canManage
 */
$this->layout('layouts.app', ['title' => 'History — ' . $page['title'], 'currentPath' => '/admin/cms']);
$this->start('content');
$id = e_attr((string) $page['public_id']);
?>
<?= component('page-header', [
    'title' => 'History — ' . $page['title'],
    'subtitle' => 'Every save is kept (the newest 50). Compare two versions, or put an old one back as the newest.',
    'breadcrumbs' => [['label' => 'Pages', 'href' => '/admin/cms'], ['label' => $page['title'], 'href' => '/admin/cms/' . $page['public_id'] . '/edit'], ['label' => 'History']],
]) ?>

<?php if (($e = error('form')) !== null): ?><div class="mb-4"><?= component('alert', ['type' => 'danger', 'message' => $e]) ?></div><?php endif ?>

<form method="get" action="/admin/cms/<?= $id ?>/revisions" class="mb-4 flex flex-wrap items-end gap-3">
    <div><label class="form-label" for="rev-a">Compare</label>
        <select id="rev-a" class="form-select" name="a"><?php foreach ($revisions as $r): ?><option value="<?= (int) $r['version'] ?>" <?= $a === (int) $r['version'] ? 'selected' : '' ?>>Version <?= (int) $r['version'] ?></option><?php endforeach ?></select></div>
    <div><label class="form-label" for="rev-b">with</label>
        <select id="rev-b" class="form-select" name="b"><?php foreach ($revisions as $r): ?><option value="<?= (int) $r['version'] ?>" <?= $b === (int) $r['version'] || ($b === 0 && (int) $r['version'] === (int) $page['version']) ? 'selected' : '' ?>>Version <?= (int) $r['version'] ?><?= (int) $r['version'] === (int) $page['version'] ? ' (current)' : '' ?></option><?php endforeach ?></select></div>
    <button type="submit" class="btn btn-secondary">Compare</button>
</form>

<?php if ($diff !== null): ?>
    <section class="mb-6 card card-body" aria-label="Differences">
        <h2 class="mb-2 text-sm font-semibold text-slate-900">Version <?= (int) $a ?> → version <?= (int) $b ?> <span class="font-normal text-slate-500">· <?= (int) $stats['added'] ?> line(s) added, <?= (int) $stats['removed'] ?> removed</span></h2>
        <?php if ($stats['added'] + $stats['removed'] === 0): ?>
            <p class="text-sm text-slate-500">The text is identical (other settings may differ).</p>
        <?php else: ?>
            <div class="overflow-x-auto rounded border border-slate-200 font-mono text-xs">
                <?php foreach ($diff as $d): ?>
                    <div class="whitespace-pre-wrap px-2 py-0.5 <?= $d['op'] === '+' ? 'bg-green-50 text-green-900' : ($d['op'] === '-' ? 'bg-red-50 text-red-900' : 'text-slate-600') ?>"><span class="select-none text-slate-400" aria-hidden="true"><?= $d['op'] === '=' ? '  ' : $d['op'] . ' ' ?></span><span class="sr-only"><?= $d['op'] === '+' ? 'Added: ' : ($d['op'] === '-' ? 'Removed: ' : '') ?></span><?= e($d['text']) ?></div>
                <?php endforeach ?>
            </div>
        <?php endif ?>
    </section>
<?php endif ?>

<div class="table-wrap">
    <table class="data" aria-label="Versions">
        <thead><tr><th>Version</th><th>Saved</th><th>By</th><th>Title</th><th>Note</th><th>Size</th><?php if ($canManage): ?><th><span class="sr-only">Actions</span></th><?php endif ?></tr></thead>
        <tbody>
        <?php foreach ($revisions as $r): $current = (int) $r['version'] === (int) $page['version']; ?>
            <tr>
                <td class="font-medium"><?= (int) $r['version'] ?><?= $current ? ' ' . component('badge', ['label' => 'Current', 'color' => 'green']) : '' ?></td>
                <td class="text-xs text-slate-500"><?= e(substr((string) $r['created_at'], 0, 16)) ?> UTC</td>
                <td class="text-sm text-slate-600"><?= e((string) ($r['created_by_name'] ?? '—')) ?></td>
                <td class="text-sm"><?= e((string) $r['title']) ?></td>
                <td class="text-xs text-slate-500"><?= e((string) ($r['note'] ?? '')) ?></td>
                <td class="text-xs text-slate-500"><?= number_format((int) $r['chars']) ?> chars</td>
                <?php if ($canManage): ?>
                    <td class="text-right"><?php if (!$current): ?>
                        <form method="post" action="/admin/cms/<?= $id ?>/revisions/<?= (int) $r['version'] ?>/restore" data-confirm="Put version <?= (int) $r['version'] ?> back as the newest version?"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm">Restore this version</button></form>
                    <?php endif ?></td>
                <?php endif ?>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php $this->stop(); ?>
