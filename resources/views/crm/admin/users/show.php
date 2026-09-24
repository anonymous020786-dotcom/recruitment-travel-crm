<?php
/**
 * @var array<string,mixed> $u @var list<string> $branches @var list<array<string,mixed>> $logins @var int $sessionCount
 * @var bool $canManage @var bool $isSelf @var string $roleLabel
 */
$this->layout('layouts.app', ['title' => $u['name'], 'currentPath' => '/admin/users']);
$this->start('content');

$base = '/admin/users/' . e_attr($u['public_id']);
$locked = $u['locked_until'] !== null && strtotime((string) $u['locked_until'] . ' UTC') > time();
$temp = session()?->get('temporary_password');
$post = static fn (string $path, string $label, string $cls = 'btn-secondary', string $extra = '', string $confirm = ''): string
    => '<form method="post" action="' . $path . '" class="inline">' . csrf_field() . $extra
        . '<button type="submit" class="btn ' . $cls . ' btn-sm"' . ($confirm !== '' ? ' data-confirm="' . e_attr($confirm) . '"' : '') . '>' . e($label) . '</button></form>';
?>
<?= component('page-header', [
    'title' => $u['name'],
    'subtitle' => $u['email'] . ' · ' . $roleLabel,
    'breadcrumbs' => [['label' => 'Users', 'href' => '/admin/users'], ['label' => $u['name']]],
    'actions' => $canManage ? '<a href="' . $base . '/edit" class="btn btn-secondary btn-sm">Edit</a>' : '',
]) ?>

<?php if ($temp): ?>
    <div class="mb-4"><?= component('alert', ['type' => 'warning', 'message' => 'Temporary password (shown once — copy it now): ' . $temp]) ?></div>
<?php endif ?>

<div class="mb-4 flex flex-wrap gap-2">
    <?= component('badge', ['label' => (bool) $u['is_active'] ? 'Active' : 'Deactivated', 'color' => (bool) $u['is_active'] ? 'green' : 'slate', 'dot' => true]) ?>
    <?php if ($locked): ?><?= component('badge', ['label' => 'Locked until ' . date('d M H:i', strtotime((string) $u['locked_until'] . ' UTC')), 'color' => 'red']) ?><?php endif ?>
    <?php if ((bool) $u['two_factor_enabled']): ?><?= component('badge', ['label' => 'Two-factor on', 'color' => 'blue']) ?><?php endif ?>
    <?php if ((bool) $u['must_change_password']): ?><?= component('badge', ['label' => 'Must change password', 'color' => 'amber']) ?><?php endif ?>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        <?= component('card', ['title' => 'Account', 'body' => (function () use ($u, $branches, $roleLabel) {
            $rows = [
                'Role' => e($roleLabel),
                'Phone' => $u['phone'] ? e($u['phone']) : '—',
                'Branches' => (bool) $u['is_org_wide'] ? 'All branches (organisation-wide)' : e($branches !== [] ? implode(', ', $branches) : '—'),
                'Primary branch' => e($u['primary_branch'] ?? '—'),
                'Created' => e(date('d M Y', strtotime((string) $u['created_at'] . ' UTC'))),
                'Last sign-in' => $u['last_login_at'] ? e(date('d M Y H:i', strtotime((string) $u['last_login_at'] . ' UTC'))) . ' UTC' : 'Never',
                'Password changed' => $u['password_changed_at'] ? e(date('d M Y', strtotime((string) $u['password_changed_at'] . ' UTC'))) : 'Never (still on the temporary password)',
            ];
            $html = '<dl class="grid gap-3 sm:grid-cols-2">';
            foreach ($rows as $k => $v) {
                $html .= '<div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">' . e($k) . '</dt><dd class="text-sm text-slate-900">' . $v . '</dd></div>';
            }

            return $html . '</dl>';
        })()]) ?>

        <?= component('card', ['title' => 'Recent sign-ins', 'body' => $logins === []
            ? '<p class="text-sm text-slate-500">No sign-ins recorded yet.</p>'
            : '<ul class="divide-y divide-slate-100 text-sm">' . implode('', array_map(static fn (array $l): string => '<li class="flex items-center justify-between gap-3 py-2"><span class="truncate text-slate-700">' . e(mb_substr((string) $l['user_agent'], 0, 80)) . '</span><span class="shrink-0 text-xs text-slate-500">' . e(date('d M H:i', strtotime((string) $l['created_at'] . ' UTC'))) . '</span></li>', $logins)) . '</ul>']) ?>
    </div>

    <div class="space-y-4">
        <?php if ($canManage && !$isSelf): ?>
            <?= component('card', ['title' => 'Actions', 'body' => '<div class="flex flex-col items-start gap-2">'
                . ((bool) $u['is_active']
                    ? $post($base . '/status', 'Deactivate account', 'btn-danger', '<input type="hidden" name="active" value="0">', 'Deactivate this account and sign the person out everywhere?')
                    : $post($base . '/status', 'Reactivate account', 'btn-primary', '<input type="hidden" name="active" value="1">'))
                . ($locked ? $post($base . '/unlock', 'Unlock account') : '')
                . ((bool) $u['is_active'] ? $post($base . '/reset-link', 'Email a password reset link') . $post($base . '/temporary-password', 'Issue a temporary password', 'btn-secondary', '', 'Set a new temporary password and sign the person out?') : '')
                . '<p class="text-xs text-slate-500">' . (int) $sessionCount . ' active session' . ($sessionCount === 1 ? '' : 's') . '</p>'
                . ($sessionCount > 0 ? $post($base . '/sign-out', 'Sign out everywhere') : '')
                . ((bool) $u['two_factor_enabled'] ? $post($base . '/reset-2fa', 'Remove two-factor', 'btn-secondary', '', 'Remove two-factor authentication for this person?') : '')
                . '</div>']) ?>
        <?php elseif ($isSelf): ?>
            <?= component('card', ['title' => 'This is you', 'body' => '<p class="text-sm text-slate-600">Manage your own password and security from <a class="text-brand-600 hover:underline" href="/account/security">your account</a>. You cannot deactivate yourself or change your own role.</p>']) ?>
        <?php else: ?>
            <?= component('card', ['title' => 'Read only', 'body' => '<p class="text-sm text-slate-500">You cannot change this account.</p>']) ?>
        <?php endif ?>
    </div>
</div>
<?php $this->stop(); ?>
