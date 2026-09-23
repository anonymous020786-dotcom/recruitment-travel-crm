<?php
/**
 * @var \App\Models\Application $app
 * @var list<array{from:?string,to:string,is_override:bool,reason:?string,by:?string,at:string}> $history
 * @var bool $canStatus @var bool $canOverride @var list<string> $nextStatuses @var list<string> $allStatuses
 */
$this->layout('layouts.app', ['title' => $app->applicationNumber, 'currentPath' => '/applications']);
$this->start('content');

$label = static fn (string $s): string => \App\Models\Application::statusLabel($s);
$base = '/applications/' . e_attr($app->publicId);
?>
<?= component('page-header', [
    'title' => $app->candidateName . ' → ' . $app->jobTitle,
    'subtitle' => $app->applicationNumber . ' · ' . $app->employerName,
    'breadcrumbs' => [['label' => 'Applications', 'href' => '/applications'], ['label' => $app->applicationNumber]],
]) ?>

<div class="mb-4 flex flex-wrap items-center gap-2">
    <?= component('badge', ['label' => $app->label(), 'color' => in_array($app->status, ['rejected', 'cancelled'], true) ? 'red' : ($app->status === 'placed' ? 'green' : 'indigo'), 'dot' => true]) ?>
    <?php if ($app->matchScore !== null): ?><?= component('badge', ['label' => 'Match ' . number_format($app->matchScore, 1), 'color' => $app->matchScore >= 75 ? 'green' : ($app->matchScore >= 50 ? 'amber' : 'slate')]) ?><?php endif ?>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-4">
        <?= component('card', ['title' => 'Summary', 'body' => (function () use ($app) {
            $rows = [
                'Candidate' => '<a href="/candidates/' . e_attr($app->candidatePublicId) . '" class="text-brand-600 hover:underline">' . e($app->candidateName) . '</a> <span class="font-mono text-xs text-slate-400">' . e($app->candidateNumber) . '</span>',
                'Job' => '<a href="/jobs/' . e_attr($app->jobPublicId) . '" class="text-brand-600 hover:underline">' . e($app->jobTitle) . '</a> <span class="font-mono text-xs text-slate-400">' . e($app->jobNumber) . '</span>',
                'Employer' => '<a href="/employers/' . e_attr($app->employerPublicId) . '" class="text-brand-600 hover:underline">' . e($app->employerName) . '</a>',
                'Assigned to' => e($app->assignedToName ?? '—'),
                'Applied' => e(substr($app->appliedAt, 0, 16)),
                'Closed' => e($app->closedAt ? substr($app->closedAt, 0, 16) : '—'),
            ];
            $html = '<dl class="grid gap-x-4 gap-y-2 sm:grid-cols-2 text-sm">';
            foreach ($rows as $k => $v) {
                $html .= '<div><dt class="text-slate-500">' . e($k) . '</dt><dd class="text-slate-900">' . $v . '</dd></div>';
            }
            $html .= '</dl>';
            if ($app->cancelReason) {
                $html .= '<p class="mt-3 text-sm text-red-700">Cancelled: ' . e($app->cancelReason) . '</p>';
            }

            return $html;
        })()]) ?>

        <?php if ($app->matchBreakdown !== null): ?>
            <?= component('card', ['title' => 'Match at the time of applying', 'body' => (function () use ($app) {
                $b = $app->matchBreakdown;
                $icon = ['matched' => '✅', 'partial' => '◐', 'missing' => '❌', 'na' => '➖'];
                $html = '<p class="mb-2 text-sm text-slate-600">Score <strong>' . e(number_format((float) ($b['score'] ?? 0), 1)) . '</strong> / 100'
                    . (empty($b['eligible']) ? ' · <span class="text-red-600">missing mandatory: ' . e(implode(', ', (array) ($b['missing_mandatory'] ?? []))) . '</span>' : '') . '</p><ul class="space-y-1 text-xs">';
                foreach ((array) ($b['criteria'] ?? []) as $c) {
                    $html .= '<li><span aria-hidden="true">' . ($icon[$c['state']] ?? '•') . '</span> <span class="font-medium">' . e((string) $c['label']) . '</span> — ' . e((string) $c['detail']) . '</li>';
                }

                return $html . '</ul>';
            })()]) ?>
        <?php endif ?>

        <div id="history">
            <?= component('card', ['title' => 'Status history', 'body' => (function () use ($history, $label) {
                $html = '<ol class="space-y-3 text-sm">';
                foreach ($history as $h) {
                    $html .= '<li class="flex gap-3"><span class="mt-0.5 text-slate-400" aria-hidden="true">•</span><div>'
                        . '<p class="text-slate-800">' . ($h['from'] !== null ? e($label($h['from'])) . ' → ' : '') . '<strong>' . e($label($h['to'])) . '</strong>'
                        . ($h['is_override'] ? ' ' . component('badge', ['label' => 'Override', 'color' => 'amber', 'dot' => true]) : '') . '</p>'
                        . ($h['reason'] ? '<p class="text-slate-600">' . e($h['reason']) . '</p>' : '')
                        . '<p class="text-xs text-slate-400">' . ($h['by'] ? e($h['by']) . ' · ' : '') . e(substr($h['at'], 0, 16)) . '</p></div></li>';
                }

                return $html . '</ol>';
            })()]) ?>
        </div>
    </div>

    <div class="space-y-4">
        <?= component('card', ['title' => 'Move application', 'body' => (function () use ($app, $canStatus, $canOverride, $nextStatuses, $allStatuses, $label, $base) {
            if (!$canStatus) {
                return '<p class="text-sm text-slate-500">You do not have permission to change the status.</p>';
            }
            $html = '<form method="post" action="' . $base . '/status" class="space-y-2" data-once>' . csrf_field()
                . '<input type="hidden" name="record_version" value="' . (int) $app->recordVersion . '">';
            if ($nextStatuses === []) {
                $html .= '<p class="text-xs text-slate-500">No further moves are allowed from ' . e($app->label()) . '.</p>';
            } else {
                $html .= '<select name="status" aria-label="Move to" class="form-select w-full">';
                foreach ($nextStatuses as $s) {
                    $html .= '<option value="' . e_attr($s) . '">' . e($label($s)) . '</option>';
                }
                $html .= '</select>';
            }
            if ($nextStatuses !== []) {
                $html .= '<input type="text" name="reason" maxlength="255" placeholder="Reason (required to reject/cancel)" aria-label="Reason" class="form-input w-full">'
                    . '<button class="btn btn-secondary btn-sm">Move</button>';
            }
            $html .= '</form>';

            if ($canOverride) {
                $html .= '<details class="mt-4 border-t border-slate-100 pt-3"><summary class="cursor-pointer text-xs font-medium text-amber-700">Override (audited)</summary>'
                    . '<form method="post" action="' . $base . '/status" class="mt-2 space-y-2" data-once>' . csrf_field()
                    . '<input type="hidden" name="record_version" value="' . (int) $app->recordVersion . '"><input type="hidden" name="override" value="1">'
                    . '<select name="status" aria-label="Override to" class="form-select w-full">';
                foreach ($allStatuses as $s) {
                    $html .= '<option value="' . e_attr($s) . '">' . e($label($s)) . '</option>';
                }
                $html .= '</select><input type="text" name="reason" required maxlength="255" placeholder="Why is this override needed? (required)" aria-label="Override reason" class="form-input w-full">'
                    . '<button class="btn btn-ghost btn-sm text-amber-700">Override</button></form></details>';
            }

            return $html;
        })()]) ?>
    </div>
</div>
<?php $this->stop(); ?>
