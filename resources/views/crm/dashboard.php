<?php
/**
 * @var array{overdue:int,today:int,upcoming:int} $followupCounts
 * @var list<\App\Models\Followup> $dueFollowups
 * @var int $openLeads
 * @var bool $showLeads
 */
$this->layout('layouts.app', ['title' => 'Dashboard', 'currentPath' => '/dashboard']);
$this->start('content');

$followupCounts = $followupCounts ?? ['overdue' => 0, 'today' => 0, 'upcoming' => 0];
$dueFollowups = $dueFollowups ?? [];
?>
<?= component('page-header', [
    'title' => 'Dashboard',
    'subtitle' => 'Welcome back, ' . e(user()?->name ?? ''),
]) ?>

<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <?= component('stat', [
        'label' => 'Follow-ups overdue', 'value' => (int) $followupCounts['overdue'],
        'href' => '/followups', 'hint' => $followupCounts['overdue'] > 0 ? 'Needs attention' : 'All clear',
    ]) ?>
    <?= component('stat', ['label' => 'Follow-ups due today', 'value' => (int) $followupCounts['today'], 'href' => '/followups']) ?>
    <?= component('stat', ['label' => 'Next 7 days', 'value' => (int) $followupCounts['upcoming'], 'href' => '/followups']) ?>
    <?php if (!empty($showLeads)): ?>
        <?= component('stat', ['label' => 'Open leads (in scope)', 'value' => (int) ($openLeads ?? 0), 'href' => '/leads']) ?>
    <?php else: ?>
        <?= component('stat', ['label' => 'Active candidates', 'value' => '—', 'hint' => 'Wired in Phase 10']) ?>
    <?php endif ?>
</div>

<div class="mt-6 grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <?= component('card', [
            'title' => 'Follow-ups needing action',
            'body' => (function () use ($dueFollowups) {
                if ($dueFollowups === []) {
                    return component('empty-state', [
                        'title' => 'Nothing due',
                        'message' => 'No overdue or same-day follow-ups. Scheduled ones show on the Follow-ups page.',
                    ]);
                }
                $today = gmdate('Y-m-d');
                $rows = '';
                foreach ($dueFollowups as $f) {
                    $overdue = $f->isOverdue($today);
                    $rows .= '<li class="flex items-center justify-between gap-2 py-2 text-sm">'
                        . '<div class="min-w-0">'
                        . '<a href="/leads/' . e_attr((string) $f->leadPublicId) . '#followups" class="font-medium text-slate-900 hover:underline">'
                        . e((string) $f->leadName) . '</a>'
                        . '<p class="text-xs text-slate-500">' . e($f->subject ?: $f->channelLabel() . ' follow-up')
                        . ' · due ' . e($f->dueLabel()) . '</p></div>'
                        . component('badge', ['label' => $overdue ? 'Overdue' : 'Today', 'color' => $overdue ? 'rose' : 'amber', 'dot' => true])
                        . '</li>';
                }
                return '<ul class="divide-y divide-slate-100">' . $rows . '</ul>'
                    . '<a href="/followups" class="btn btn-secondary btn-sm mt-3">Open follow-ups</a>';
            })(),
        ]) ?>
    </div>
    <?= component('card', [
        'title' => 'Your access',
        'body' => '<p class="text-sm text-slate-600">Role: <span class="font-medium text-slate-900">'
            . e(user()?->roleName ?? '') . '</span></p>'
            . '<p class="mt-2 text-sm text-slate-600">Operational widgets and charts are delivered in Phase 10.</p>',
    ]) ?>
</div>
<?php $this->stop(); ?>
