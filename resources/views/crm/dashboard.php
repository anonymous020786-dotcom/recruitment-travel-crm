<?php
/**
 * @var array{overdue:int,today:int,upcoming:int} $followupCounts
 * @var list<\App\Models\Followup> $dueFollowups
 * @var array<string,mixed> $snap  see DashboardService::snapshot()
 */
$this->layout('layouts.app', ['title' => 'Dashboard', 'currentPath' => '/dashboard']);
$this->start('content');

$followupCounts = $followupCounts ?? ['overdue' => 0, 'today' => 0, 'upcoming' => 0];
$dueFollowups = $dueFollowups ?? [];
$snap = $snap ?? [];
$months = (array) ($snap['months'] ?? []);
$monthLabel = static fn (string $ym): string => date('M', (int) strtotime($ym . '-01'));

/** A horizontal bar list: label, count, bar scaled to the largest value. */
$bars = static function (array $rows, string $color = 'bg-brand-500', ?callable $href = null): string {
    if ($rows === []) {
        return '<p class="text-sm text-slate-500">Nothing to show yet.</p>';
    }
    $max = max(1, max($rows));
    $html = '<ul class="space-y-2">';
    foreach ($rows as $label => $n) {
        $pct = max(2, (int) round($n / $max * 100));
        $name = ucwords(str_replace('_', ' ', (string) $label));
        $link = $href !== null ? $href((string) $label) : null;
        $html .= '<li class="text-sm"><div class="flex items-baseline justify-between gap-2"><span class="text-slate-700">'
            . ($link ? '<a href="' . e_attr($link) . '" class="hover:underline">' . e($name) . '</a>' : e($name)) . '</span>'
            . '<span class="font-medium tabular-nums text-slate-900">' . (int) $n . '</span></div>'
            . '<div class="mt-1 h-2 rounded bg-slate-100"><div class="h-2 rounded ' . $color . '" style="width:' . $pct . '%"></div></div></li>';
    }

    return $html . '</ul>';
};

/** Vertical bars, one per month, with the value above and the month below. */
$trend = static function (array $values, array $months, string $color) use ($monthLabel): string {
    $max = max(1, $values === [] ? 1 : max($values));
    $html = '<div class="flex h-28 items-end gap-2" role="img" aria-label="' . e_attr(implode(', ', array_map(static fn ($m, $v) => $m . ': ' . $v, $months, $values))) . '">';
    foreach ($values as $i => $v) {
        $h = $v === 0 ? 2 : max(6, (int) round($v / $max * 100));
        $html .= '<div class="flex h-full flex-1 flex-col items-center justify-end gap-1"><span class="text-xs tabular-nums text-slate-600">' . (int) $v . '</span>'
            . '<div class="w-full rounded-t ' . $color . '" style="height:' . $h . '%"></div>'
            . '<span class="text-xs text-slate-400">' . e($monthLabel((string) ($months[$i] ?? ''))) . '</span></div>';
    }

    return $html . '</div>';
};

$money = static fn (string $cur, string $v): string => e($cur . ' ' . number_format((float) $v, 2));
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
    <?php if (isset($snap['leads'])): ?>
        <?= component('stat', ['label' => 'Open leads', 'value' => (int) $snap['leads']['open'], 'href' => '/leads']) ?>
    <?php endif ?>
    <?php if (isset($snap['candidates'])): ?>
        <?= component('stat', ['label' => 'Candidates', 'value' => (int) $snap['candidates']['total'], 'href' => '/candidates', 'hint' => '+' . (int) $snap['candidates']['new_month'] . ' this month']) ?>
    <?php endif ?>
    <?php if (isset($snap['pipeline'])): ?>
        <?= component('stat', ['label' => 'Live applications', 'value' => (int) $snap['pipeline']['live'], 'href' => '/applications']) ?>
    <?php endif ?>
    <?php if (isset($snap['interviews'])): ?>
        <?= component('stat', ['label' => 'Interviews next 7 days', 'value' => (int) $snap['interviews']['next7'], 'href' => '/interviews', 'hint' => (int) $snap['interviews']['today'] . ' today']) ?>
    <?php endif ?>
    <?php if (isset($snap['travel'])): ?>
        <?= component('stat', ['label' => 'Placed this month', 'value' => (int) $snap['travel']['placed_month'], 'href' => '/placements', 'hint' => (int) $snap['travel']['placed'] . ' in total']) ?>
    <?php endif ?>
    <?php if (isset($snap['tours'])): ?>
        <?= component('stat', ['label' => 'Tours in the next 30 days', 'value' => (int) $snap['tours']['upcoming'], 'href' => '/tours/bookings?when=upcoming']) ?>
    <?php endif ?>
</div>

<?php if (!empty($snap['finance']['summary'])): ?>
    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($snap['finance']['summary'] as $s): $cur = (string) $s['currency']; ?>
            <a href="/invoices" class="card card-body block no-underline hover:ring-brand-200">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Receivables · <?= e($cur) ?></p>
                <p class="mt-1 text-2xl font-semibold text-slate-900"><?= $money($cur, (string) $s['outstanding']) ?> <span class="text-sm font-normal text-slate-500">outstanding</span></p>
                <p class="mt-1 text-xs text-slate-500">Billed <?= $money($cur, (string) $s['billed']) ?> · collected 30 days <?= $money($cur, (string) ($snap['finance']['collected'][$cur] ?? '0')) ?>
                    <?php if ((float) $s['overdue'] > 0): ?>· <span class="font-medium text-red-600">overdue <?= $money($cur, (string) $s['overdue']) ?></span><?php endif ?></p>
            </a>
        <?php endforeach ?>
    </div>
<?php endif ?>

<div class="mt-6 grid gap-4 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        <?php if (!empty($snap['attention'])): ?>
            <?= component('card', ['title' => 'Needs attention', 'body' => (function () use ($snap) {
                $html = '<ul class="divide-y divide-slate-100">';
                foreach ($snap['attention'] as $a) {
                    $html .= '<li class="flex items-center justify-between gap-2 py-2 text-sm"><a href="' . e_attr($a['href']) . '" class="text-slate-800 hover:underline">' . e($a['label']) . '</a>'
                        . component('badge', ['label' => (string) $a['count'], 'color' => $a['tone'], 'dot' => true]) . '</li>';
                }

                return $html . '</ul>';
            })()]) ?>
        <?php endif ?>

        <?php if (isset($snap['pipeline'])): ?>
            <?= component('card', ['title' => 'Application pipeline', 'body' => $bars($snap['pipeline']['by_status'], 'bg-brand-500', static fn (string $s): string => '/applications?status=' . $s)]) ?>
        <?php endif ?>

        <?php if (isset($snap['candidates']) || isset($snap['leads']) || isset($snap['travel'])): ?>
            <?= component('card', ['title' => 'Last six months', 'body' => (function () use ($snap, $months, $trend) {
                $html = '<div class="grid gap-6 sm:grid-cols-3">';
                foreach ([['leads', 'New leads', 'bg-sky-400'], ['candidates', 'New candidates', 'bg-brand-500'], ['travel', 'Placements', 'bg-emerald-500']] as [$k, $title, $color]) {
                    if (isset($snap[$k])) {
                        $html .= '<div><p class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-500">' . e($title) . '</p>' . $trend((array) $snap[$k]['monthly'], $months, $color) . '</div>';
                    }
                }

                return $html . '</div>';
            })()]) ?>
        <?php endif ?>

        <?php if (isset($snap['leads']) && $snap['leads']['by_status'] !== []): ?>
            <?= component('card', ['title' => 'Leads by status', 'body' => $bars($snap['leads']['by_status'], 'bg-sky-400', static fn (string $s): string => '/leads?status=' . $s)]) ?>
        <?php endif ?>

        <?php if (isset($snap['tours']) && $snap['tours']['by_status'] !== []): ?>
            <?= component('card', ['title' => 'Tour bookings', 'body' => $bars($snap['tours']['by_status'], 'bg-amber-400', static fn (string $s): string => '/tours/bookings?status=' . $s)]) ?>
        <?php endif ?>
    </div>

    <div class="space-y-4">
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

        <?php if (isset($snap['travel'])): ?>
            <?= component('card', ['title' => 'Travel desk', 'body' => $bars($snap['travel']['stages'], 'bg-indigo-500', static fn (string $s): string => '/travel?status=' . $s)]) ?>
        <?php endif ?>

        <?= component('card', [
            'title' => 'Your access',
            'body' => '<p class="text-sm text-slate-600">Role: <span class="font-medium text-slate-900">' . e(user()?->roleName ?? '') . '</span></p>'
                . '<p class="mt-2 text-xs text-slate-400">Figures cover the branches you can see' . (isset($snap['generated_at']) ? ' · as of ' . e(substr((string) $snap['generated_at'], 11, 5)) . ' UTC' : '') . '.</p>',
        ]) ?>
    </div>
</div>
<?php $this->stop(); ?>
