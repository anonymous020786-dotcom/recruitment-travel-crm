<?php
/**
 * @var list<array<string,mixed>> $rows @var int $total @var int $page @var int $perPage @var string $status @var string $search
 * @var array{total:int,draft:int,published:int,archived:int} $counts
 */
$this->layout('layouts.app', ['title' => 'Blog', 'currentPath' => '/admin/blog']);
$this->start('content');

$colors = ['draft' => 'amber', 'published' => 'green', 'archived' => 'slate'];
?>
<?= component('page-header', [
    'title' => 'Blog',
    'subtitle' => number_format($total) . ' matching',
    'actions' => can('blog.manage') ? '<a href="/admin/blog/create" class="btn btn-primary btn-sm">Write a post</a>' : '',
]) ?>

<div class="mb-4 grid gap-3 sm:grid-cols-4">
    <?= component('stat', ['label' => 'All posts', 'value' => $counts['total'], 'href' => '/admin/blog']) ?>
    <?= component('stat', ['label' => 'Published', 'value' => $counts['published'], 'href' => '/admin/blog?status=published']) ?>
    <?= component('stat', ['label' => 'Drafts', 'value' => $counts['draft'], 'href' => '/admin/blog?status=draft']) ?>
    <?= component('stat', ['label' => 'Archived', 'value' => $counts['archived'], 'href' => '/admin/blog?status=archived']) ?>
</div>

<form method="get" action="/admin/blog" class="card card-body mb-4 flex flex-wrap items-end gap-3">
    <div>
        <label class="form-label" for="q">Search</label>
        <input class="form-input" type="search" id="q" name="q" value="<?= e_attr($search) ?>" placeholder="Title or address" maxlength="80">
    </div>
    <div>
        <label class="form-label" for="status">Status</label>
        <select class="form-select" id="status" name="status">
            <option value="">Any</option>
            <?php foreach (['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'] as $k => $label): ?><option value="<?= e_attr($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary">Filter</button>
    <?php if ($search !== '' || $status !== ''): ?><a href="/admin/blog" class="btn btn-ghost">Clear</a><?php endif ?>
</form>

<?php if ($rows === []): ?>
    <?= component('card', ['body' => component('empty-state', ['title' => 'No posts here yet', 'message' => 'Articles about visas, medicals, jobs and destinations bring visitors to the public site.'])]) ?>
<?php else: ?>
    <div class="table-wrap">
        <table class="data" aria-label="Blog posts">
            <thead><tr><th>Title</th><th>Status</th><th>Author</th><th>Published</th><th>Updated</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $p): ?>
                <tr>
                    <td>
                        <a class="font-medium text-slate-900" href="/admin/blog/<?= e_attr($p['public_id']) ?>/edit"><?= e($p['title']) ?></a>
                        <p class="text-xs text-slate-500">/blog/<?= e($p['slug']) ?></p>
                    </td>
                    <td><?= component('badge', ['label' => ucfirst((string) $p['status']), 'color' => $colors[$p['status']] ?? 'slate', 'dot' => true]) ?></td>
                    <td class="text-sm text-slate-600"><?= e($p['author_name'] ?? '—') ?></td>
                    <td class="text-sm text-slate-600"><?= $p['published_at'] ? e(date('d M Y', strtotime((string) $p['published_at'] . ' UTC'))) : '—' ?></td>
                    <td class="text-sm text-slate-600"><?= e(date('d M Y', strtotime((string) $p['updated_at'] . ' UTC'))) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?= component('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'baseUrl' => '/admin/blog', 'query' => array_filter(['q' => $search, 'status' => $status])]) ?>
<?php endif ?>
<?php $this->stop(); ?>
