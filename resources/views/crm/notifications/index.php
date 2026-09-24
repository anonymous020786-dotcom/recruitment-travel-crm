<?php
/** @var list<array<string,mixed>> $rows @var int $total @var int $page @var int $perPage @var bool $unreadOnly @var int $unread */
$this->layout('layouts.app', ['title' => 'Notifications', 'currentPath' => '/notifications']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => 'Notifications',
    'subtitle' => $unread === 0 ? 'You are all caught up.' : $unread . ' unread',
    'actions' => $unread > 0
        ? '<form method="post" action="/notifications/read-all" class="inline">' . csrf_field() . '<button type="submit" class="btn btn-secondary btn-sm">Mark all as read</button></form>'
        : '',
]) ?>

<div class="mb-4 flex gap-2" role="group" aria-label="Filter notifications">
    <a href="/notifications" class="btn btn-sm <?= $unreadOnly ? 'btn-ghost' : 'btn-secondary' ?>"<?= $unreadOnly ? '' : ' aria-current="true"' ?>>All</a>
    <a href="/notifications?filter=unread" class="btn btn-sm <?= $unreadOnly ? 'btn-secondary' : 'btn-ghost' ?>"<?= $unreadOnly ? ' aria-current="true"' : '' ?>>Unread</a>
</div>

<?php if ($rows === []): ?>
    <?= component('card', ['body' => component('empty-state', [
        'title' => $unreadOnly ? 'No unread notifications' : 'No notifications yet',
        'message' => 'Reminders, approvals and alerts will show up here.',
    ])]) ?>
<?php else: ?>
    <ul class="card divide-y divide-slate-100">
        <?php foreach ($rows as $n): $isNew = $n['read_at'] === null; ?>
            <li class="flex items-start gap-3 px-4 py-3 <?= $isNew ? 'bg-brand-50/40' : '' ?>">
                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full <?= $isNew ? 'bg-brand-600' : 'bg-transparent' ?>" aria-hidden="true"></span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm <?= $isNew ? 'font-semibold text-slate-900' : 'text-slate-700' ?>">
                        <a href="/notifications/<?= (int) $n['id'] ?>/open" class="hover:underline"><?= e($n['title']) ?><?php if ($isNew): ?><span class="sr-only"> (unread)</span><?php endif ?></a>
                    </p>
                    <?php if (!empty($n['body'])): ?><p class="mt-0.5 text-sm text-slate-500"><?= e($n['body']) ?></p><?php endif ?>
                </div>
                <time class="shrink-0 text-xs text-slate-400" datetime="<?= e_attr(str_replace(' ', 'T', (string) $n['created_at'])) ?>Z"><?= e(date('d M H:i', strtotime((string) $n['created_at'] . ' UTC'))) ?></time>
            </li>
        <?php endforeach ?>
    </ul>
    <?= component('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'baseUrl' => '/notifications', 'query' => $unreadOnly ? ['filter' => 'unread'] : []]) ?>
<?php endif ?>
<?php $this->stop(); ?>
