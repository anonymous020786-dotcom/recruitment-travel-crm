<?php
/**
 * @var list<array<string,mixed>> $rows @var int $total @var int $page @var int $perPage @var string $tab @var string $q
 * @var array<string,int> $counts @var bool $canManage @var bool $canPublish
 */
$this->layout('layouts.app', ['title' => 'Pages', 'currentPath' => '/admin/cms']);
$this->start('content');

$tabs = ['all' => 'All', 'draft' => 'Drafts', 'review' => 'In review', 'live' => 'Live', 'scheduled' => 'Scheduled', 'expired' => 'Taken down', 'archived' => 'Archived', 'trash' => 'Trash'];
$tone = ['draft' => 'slate', 'review' => 'amber', 'live' => 'green', 'scheduled' => 'blue', 'expired' => 'slate', 'archived' => 'slate', 'trash' => 'red'];
$stateLabel = ['draft' => 'Draft', 'review' => 'In review', 'live' => 'Live', 'scheduled' => 'Scheduled', 'expired' => 'Taken down', 'archived' => 'Archived', 'trash' => 'Trash'];
$bulk = [];
if ($tab === 'trash') {
    $canManage && $bulk['untrash'] = 'Restore from trash';
} else {
    $canManage && $bulk['review'] = 'Submit for review';
    $canPublish && $bulk += ['publish' => 'Publish now', 'unpublish' => 'Unpublish', 'archive' => 'Archive'];
    $canManage && $bulk['trash'] = 'Move to trash';
}
?>
<?= component('page-header', [
    'title' => 'Pages',
    'subtitle' => 'The pages of your public website — services, policies, landing pages. Jobs, packages and the blog have their own screens.',
    'actions' => $canManage ? '<a class="btn btn-primary" href="/admin/cms/create">New page</a>' : '',
]) ?>

<nav class="mb-4 flex flex-wrap gap-1 border-b border-slate-200" aria-label="Page status">
    <?php foreach ($tabs as $key => $label): ?>
        <a href="/admin/cms<?= $key === 'all' ? '' : '?tab=' . e_attr($key) ?>" class="-mb-px border-b-2 px-3 py-2 text-sm font-medium <?= $key === $tab ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-600' ?>"<?= $key === $tab ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="text-xs text-slate-400"><?= (int) ($counts[$key] ?? 0) ?></span></a>
    <?php endforeach ?>
</nav>

<form method="get" action="/admin/cms" class="mb-4 flex max-w-lg gap-2" role="search">
    <input type="hidden" name="tab" value="<?= e_attr($tab) ?>">
    <label class="sr-only" for="cms-q">Search pages</label>
    <input id="cms-q" class="form-input" type="search" name="q" value="<?= e_attr($q) ?>" placeholder="Search title or address" maxlength="80">
    <button type="submit" class="btn btn-secondary">Search</button>
</form>

<?php if (($e = error('form')) !== null || ($e = error('ids')) !== null || ($e = error('action')) !== null): ?><div class="mb-4"><?= component('alert', ['type' => 'danger', 'message' => $e]) ?></div><?php endif ?>

<?php if ($rows === []): ?>
    <div class="card card-body text-sm text-slate-600"><?= $q !== '' ? 'No page matches “' . e($q) . '”.' : 'Nothing here yet.' ?></div>
<?php else: ?>
    <form method="post" action="/admin/cms/bulk">
        <?= csrf_field() ?><input type="hidden" name="tab" value="<?= e_attr($tab) ?>">
        <div class="table-wrap">
            <table class="data" aria-label="Pages">
                <thead><tr>
                    <?php if ($bulk !== []): ?><th class="w-8"><span class="sr-only">Select</span></th><?php endif ?>
                    <th>Page</th><th>Status</th><th>Words</th><th>Updated</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $st = (string) $r['state']; ?>
                    <tr>
                        <?php if ($bulk !== []): ?><td><label class="sr-only" for="pg-<?= e_attr((string) $r['public_id']) ?>">Select <?= e((string) $r['title']) ?></label><input id="pg-<?= e_attr((string) $r['public_id']) ?>" type="checkbox" name="ids[]" value="<?= e_attr((string) $r['public_id']) ?>"></td><?php endif ?>
                        <td>
                            <a class="font-medium text-slate-900" href="/admin/cms/<?= e_attr((string) $r['public_id']) ?>/edit"><?= e((string) $r['title']) ?></a>
                            <p class="font-mono text-xs text-slate-500">/<?= e((string) $r['path']) ?><?= $r['robots'] === 'noindex' ? ' · noindex' : '' ?></p>
                        </td>
                        <td><?= component('badge', ['label' => $stateLabel[$st] ?? $st, 'color' => $tone[$st] ?? 'slate', 'dot' => true]) ?>
                            <?php if ($st === 'scheduled'): ?><p class="text-xs text-slate-500">goes live <?= e(substr((string) $r['publish_at'], 0, 16)) ?> UTC</p><?php endif ?>
                            <?php if ($st === 'live' && $r['unpublish_at'] !== null): ?><p class="text-xs text-slate-500">until <?= e(substr((string) $r['unpublish_at'], 0, 16)) ?> UTC</p><?php endif ?></td>
                        <td class="text-sm text-slate-600"><?= number_format((int) $r['word_count']) ?></td>
                        <td class="text-xs text-slate-500"><?= e(substr((string) $r['updated_at'], 0, 16)) ?><?= $r['updated_by_name'] ? '<br>' . e((string) $r['updated_by_name']) : '' ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php if ($bulk !== []): ?>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <label class="sr-only" for="cms-bulk">Action for the ticked pages</label>
                <select id="cms-bulk" class="form-select w-56" name="action">
                    <option value="">With the ticked pages…</option>
                    <?php foreach ($bulk as $v => $l): ?><option value="<?= e_attr((string) $v) ?>"><?= e($l) ?></option><?php endforeach ?>
                </select>
                <button type="submit" class="btn btn-secondary">Apply</button>
            </div>
        <?php endif ?>
    </form>
    <?= component('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'baseUrl' => '/admin/cms', 'query' => array_filter(['tab' => $tab === 'all' ? '' : $tab, 'q' => $q])]) ?>
<?php endif ?>
<?php $this->stop(); ?>
