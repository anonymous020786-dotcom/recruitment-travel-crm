<?php
/** @var list<array<string,mixed>> $rows @var bool $canPublish */
$this->layout('layouts.app', ['title' => 'Snippets — Pages', 'currentPath' => '/admin/cms']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => 'Website',
    'subtitle' => 'Reusable blocks — a call to action, an office address, a disclaimer. Write once, insert anywhere with {{snippet:key}}, change everywhere at once.',
    'actions' => $canPublish ? '<a class="btn btn-primary" href="/admin/cms/snippets/create">New snippet</a>' : '',
]) ?>
<?= $this->partial('crm.admin.cms._tabs', ['active' => 'snippets']) ?>

<?php if ($rows === []): ?>
    <div class="card card-body text-sm text-slate-600">No snippets yet.</div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data" aria-label="Snippets">
            <thead><tr><th>Snippet</th><th>Insert with</th><th>Status</th><th>Updated</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $s): ?>
                <tr>
                    <td><a class="font-medium text-slate-900" href="/admin/cms/snippets/<?= e_attr((string) $s['key_name']) ?>/edit"><?= e((string) $s['title']) ?></a><p class="text-xs text-slate-500"><?= number_format((int) $s['chars']) ?> characters</p></td>
                    <td class="font-mono text-xs">{{snippet:<?= e((string) $s['key_name']) ?>}}</td>
                    <td><?= component('badge', ['label' => (int) $s['is_active'] === 1 ? 'On' : 'Off', 'color' => (int) $s['is_active'] === 1 ? 'green' : 'slate', 'dot' => true]) ?></td>
                    <td class="text-xs text-slate-500"><?= e(substr((string) $s['updated_at'], 0, 16)) ?><?= $s['updated_by_name'] ? '<br>' . e((string) $s['updated_by_name']) : '' ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>
<?php $this->stop(); ?>
