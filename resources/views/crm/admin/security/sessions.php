<?php
/** @var list<array<string,mixed>> $rows @var string $currentId @var bool $canManage */
$this->layout('layouts.app', ['title' => 'Sessions — Security', 'currentPath' => '/admin/security']);
$this->start('content');

$ipText = static fn (mixed $b): string => is_string($b) && $b !== '' && ($t = @inet_ntop($b)) !== false ? (str_starts_with($t, '::ffff:') ? substr($t, 7) : $t) : '—';
$ago = static function (int $ts): string {
    $d = max(0, time() - $ts);

    return $d < 90 ? 'just now' : ($d < 5400 ? intdiv($d, 60) . ' min ago' : ($d < 172800 ? intdiv($d, 3600) . ' h ago' : intdiv($d, 86400) . ' days ago'));
};
?>
<?= component('page-header', ['title' => 'Security', 'subtitle' => 'Everyone who is signed in right now. End a session, or sign a person out of every device.']) ?>
<?= $this->partial('crm.admin.security._tabs', ['active' => 'sessions']) ?>

<?php if (($e = error('form')) !== null): ?><div class="mb-4"><?= component('alert', ['type' => 'danger', 'message' => $e]) ?></div><?php endif ?>

<?php if ($canManage && $rows !== []): ?>
    <form method="post" action="/admin/security/sessions/sign-out-others" class="mb-4" data-confirm="Sign EVERYONE else out? They will have to sign in again."><?= csrf_field() ?>
        <button type="submit" class="btn btn-secondary btn-sm">Sign everyone else out</button> <span class="text-xs text-slate-500">Asks you to confirm your password.</span>
    </form>
<?php endif ?>

<?php if ($rows === []): ?>
    <div class="card card-body text-sm text-slate-600">Nobody is signed in.</div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data" aria-label="Active sessions">
            <thead><tr><th>Person</th><th>Address</th><th>Device</th><th>Active</th><th>Signed in</th><?php if ($canManage): ?><th><span class="sr-only">Actions</span></th><?php endif ?></tr></thead>
            <tbody>
            <?php foreach ($rows as $s): $mine = (string) $s['id'] === $currentId; ?>
                <tr>
                    <td><span class="font-medium text-slate-900"><?= e((string) $s['user_name']) ?></span><?= $mine ? ' ' . component('badge', ['label' => 'You', 'color' => 'blue']) : '' ?>
                        <p class="text-xs text-slate-500"><?= e((string) $s['role_label']) ?> · <?= e((string) $s['user_email']) ?></p></td>
                    <td class="font-mono text-xs"><?= e($ipText($s['ip_address'])) ?></td>
                    <td class="max-w-xs truncate text-xs text-slate-500" title="<?= e_attr((string) ($s['user_agent'] ?? '')) ?>"><?= e(mb_substr((string) ($s['user_agent'] ?? ''), 0, 70)) ?></td>
                    <td class="text-xs text-slate-600"><?= e($ago((int) $s['last_activity'])) ?></td>
                    <td class="text-xs text-slate-500"><?= e(substr((string) $s['created_at'], 0, 16)) ?></td>
                    <?php if ($canManage): ?>
                        <td class="text-right whitespace-nowrap">
                            <?php if (!$mine): ?>
                                <form method="post" action="/admin/security/sessions/revoke" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e_attr((string) $s['id']) ?>"><button type="submit" class="btn btn-ghost btn-sm">End session</button></form>
                                <form method="post" action="/admin/security/users/<?= e_attr((string) $s['user_public_id']) ?>/sign-out" class="inline" data-confirm="Sign <?= e_attr((string) $s['user_name']) ?> out of every device?"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm text-red-600">Sign out everywhere</button></form>
                            <?php endif ?>
                        </td>
                    <?php endif ?>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>
<?php $this->stop(); ?>
