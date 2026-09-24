<?php
/** @var \App\Support\Page $page @var \App\Support\ListQuery $query @var array{total:int,active:int,inactive:int,locked:int} $counts @var list<array{id:int,name:string,label:string}> $roles */
$this->layout('layouts.app', ['title' => 'Users', 'currentPath' => '/admin/users']);
$this->start('content');

$hasFilters = $query->hasSearch() || $query->filters !== [];
?>
<?= component('page-header', [
    'title' => 'Users',
    'subtitle' => number_format($page->total) . ' matching',
    'actions' => can('users.manage') ? '<a href="/admin/users/create" class="btn btn-primary btn-sm">Add user</a>' : '',
]) ?>

<div class="mb-4 grid gap-3 sm:grid-cols-4">
    <?= component('stat', ['label' => 'All accounts', 'value' => $counts['total'], 'href' => '/admin/users']) ?>
    <?= component('stat', ['label' => 'Active', 'value' => $counts['active'], 'href' => '/admin/users?status=active']) ?>
    <?= component('stat', ['label' => 'Deactivated', 'value' => $counts['inactive'], 'href' => '/admin/users?status=inactive']) ?>
    <?= component('stat', ['label' => 'Locked out', 'value' => $counts['locked'], 'href' => '/admin/users?status=locked']) ?>
</div>

<form method="get" action="/admin/users" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div>
        <label class="form-label" for="q">Search</label>
        <input class="form-input" type="search" id="q" name="q" value="<?= e_attr($query->search) ?>" placeholder="Name, email or phone">
    </div>
    <div>
        <label class="form-label" for="role">Role</label>
        <select class="form-select" id="role" name="role">
            <option value="">Any</option>
            <?php foreach ($roles as $r): ?><option value="<?= e_attr($r['name']) ?>" <?= $query->filter('role') === $r['name'] ? 'selected' : '' ?>><?= e($r['label']) ?></option><?php endforeach ?>
        </select>
    </div>
    <div>
        <label class="form-label" for="status">Status</label>
        <select class="form-select" id="status" name="status">
            <option value="">Any</option>
            <?php foreach (['active' => 'Active', 'inactive' => 'Deactivated', 'locked' => 'Locked out'] as $k => $label): ?><option value="<?= e_attr($k) ?>" <?= $query->filter('status') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?>
        </select>
    </div>
    <div class="flex items-end gap-2">
        <button type="submit" class="btn btn-primary">Filter</button>
        <?php if ($hasFilters): ?><a href="/admin/users" class="btn btn-ghost">Clear</a><?php endif ?>
    </div>
</form>

<?php if ($page->isEmpty()): ?>
    <?= component('card', ['body' => component('empty-state', ['title' => 'No users match these filters', 'message' => 'Try widening your search.'])]) ?>
<?php else: ?>
    <div class="table-wrap">
        <table class="data" aria-label="Users">
            <thead><tr><th>Name</th><th>Role</th><th>Branch</th><th>Last sign-in</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($page->items as $u): $locked = $u['locked_until'] !== null && strtotime((string) $u['locked_until'] . ' UTC') > time(); ?>
                <tr>
                    <td>
                        <a href="/admin/users/<?= e_attr($u['public_id']) ?>" class="font-medium text-slate-900"><?= e($u['name']) ?></a>
                        <p class="text-xs text-slate-500"><?= e($u['email']) ?></p>
                    </td>
                    <td class="text-sm text-slate-700"><?= e($u['role_label']) ?></td>
                    <td class="text-sm text-slate-600"><?= (bool) $u['is_org_wide'] ? 'All branches' : e($u['primary_branch'] ?? '—') ?></td>
                    <td class="whitespace-nowrap text-xs text-slate-600"><?= $u['last_login_at'] ? e(date('d M Y H:i', strtotime((string) $u['last_login_at'] . ' UTC'))) : 'Never' ?></td>
                    <td class="space-x-1">
                        <?= component('badge', ['label' => (bool) $u['is_active'] ? 'Active' : 'Deactivated', 'color' => (bool) $u['is_active'] ? 'green' : 'slate', 'dot' => true]) ?>
                        <?php if ($locked): ?><?= component('badge', ['label' => 'Locked', 'color' => 'red']) ?><?php endif ?>
                        <?php if ((bool) $u['two_factor_enabled']): ?><?= component('badge', ['label' => '2FA', 'color' => 'blue']) ?><?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?= component('pagination', ['page' => $page->page, 'perPage' => $page->perPage, 'total' => $page->total, 'baseUrl' => '/admin/users', 'query' => $query->toQueryArray()]) ?>
<?php endif ?>
<?php $this->stop(); ?>
