<?php
/**
 * @var list<\App\Models\Followup> $overdue
 * @var list<\App\Models\Followup> $today
 * @var list<\App\Models\Followup> $upcoming
 * @var array{overdue:int,today:int,upcoming:int} $counts
 */
$this->layout('layouts.app', ['title' => 'Follow-ups', 'currentPath' => '/followups']);
$this->start('content');

$row = function (\App\Models\Followup $f): string {
    $wa = 'https://wa.me/' . preg_replace('/\D/', '', (string) $f->leadPhone);
    return '<li class="py-3">'
        . '<div class="flex flex-wrap items-start justify-between gap-2">'
        . '<div class="min-w-0">'
        . '<a href="/leads/' . e_attr((string) $f->leadPublicId) . '#followups" class="font-medium text-slate-900 hover:underline">'
        . e((string) $f->leadName) . '</a> <span class="text-xs text-slate-400">' . e((string) $f->leadNumber) . '</span>'
        . '<p class="text-sm text-slate-600">' . e($f->subject ?: $f->channelLabel() . ' follow-up') . '</p>'
        . '<p class="text-xs text-slate-500">' . e($f->channelLabel()) . ' · due ' . e($f->dueLabel()) . '</p>'
        . '</div>'
        . '<div class="flex shrink-0 gap-1">'
        . ($f->leadPhone ? '<a href="tel:' . e_attr((string) $f->leadPhone) . '" class="btn btn-ghost btn-sm">Call</a>'
            . '<a href="' . e_attr($wa) . '" target="_blank" rel="noopener" aria-label="WhatsApp ' . e_attr((string) $f->leadName) . '" class="btn btn-ghost btn-sm">WA</a>' : '')
        . '</div></div>'
        . '<form method="post" action="/followups/' . (int) $f->id . '/complete" class="mt-2 grid gap-2 sm:grid-cols-[1fr_auto]" data-once>'
        . csrf_field()
        . '<input type="text" name="outcome" required maxlength="255" placeholder="What happened?" aria-label="Outcome for ' . e_attr((string) $f->leadName) . '" class="form-input">'
        . '<div class="flex items-center gap-2">'
        . '<label class="flex items-center gap-1 whitespace-nowrap text-xs text-slate-500"><input type="checkbox" name="log_as_note" value="1"> note</label>'
        . '<button class="btn btn-primary btn-sm">Done</button>'
        . '</div></form>'
        . '<form method="post" action="/followups/' . (int) $f->id . '/cancel" class="mt-1" data-confirm="Cancel this follow-up?">'
        . csrf_field() . '<button class="btn btn-ghost btn-sm text-red-600">Cancel</button></form>'
        . '</li>';
};

$section = function (string $title, array $items, string $emptyMsg, string $accent) use ($row): string {
    $body = $items === []
        ? '<p class="text-sm text-slate-500">' . e($emptyMsg) . '</p>'
        : '<ul class="divide-y divide-slate-100">' . implode('', array_map($row, $items)) . '</ul>';
    return component('card', ['title' => $title . ' (' . count($items) . ')', 'body' => $body]);
};
?>
<?= component('page-header', ['title' => 'My follow-ups', 'subtitle' => 'Everything assigned to you that is still open.']) ?>

<div class="grid gap-4 sm:grid-cols-3">
    <?= component('stat', ['label' => 'Overdue', 'value' => (int) $counts['overdue'], 'hint' => 'Past their due date']) ?>
    <?= component('stat', ['label' => 'Due today', 'value' => (int) $counts['today']]) ?>
    <?= component('stat', ['label' => 'Next 7 days', 'value' => (int) $counts['upcoming']]) ?>
</div>

<div class="mt-4 space-y-4">
    <?= $section('Overdue', $overdue, 'Nothing overdue — nice.', 'rose') ?>
    <?= $section('Due today', $today, 'No follow-ups due today.', 'amber') ?>
    <?= $section('Upcoming (7 days)', $upcoming, 'Nothing scheduled in the next week.', 'slate') ?>
</div>
<?php $this->stop(); ?>
