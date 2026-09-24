<?php
/**
 * @var \App\Models\TourPackage $package @var list<\App\Models\TourPackageItem> $items
 * @var bool $canEdit @var bool $canDelete @var bool $canPublish @var list<string> $nextStatuses
 */
$this->layout('layouts.app', ['title' => $package->name, 'currentPath' => '/tours/packages']);
$this->start('content');

$color = ['draft' => 'slate', 'active' => 'green', 'archived' => 'amber'];
$base = '/tours/packages/' . e_attr($package->publicId);
$editable = $canEdit && !$package->isArchived();

$actions = '';
if ($editable) {
    $actions .= '<a href="' . $base . '/edit" class="btn btn-primary btn-sm">Edit</a> ';
}
if ($canDelete && in_array($package->status, ['draft', 'archived'], true)) {
    $actions .= '<form method="post" action="' . $base . '" class="inline" data-confirm="Delete this package?">'
        . csrf_field() . '<input type="hidden" name="_method" value="DELETE"><button class="btn btn-ghost btn-sm text-red-600">Delete</button></form>';
}
?>
<?= component('page-header', [
    'title' => $package->name,
    'subtitle' => $package->destination . ' · ' . $package->durationLabel(),
    'breadcrumbs' => [['label' => 'Tour packages', 'href' => '/tours/packages'], ['label' => $package->name]],
    'actions' => $actions,
]) ?>

<div class="mb-4 flex flex-wrap items-center gap-2">
    <?= component('badge', ['label' => $package->statusLabel(), 'color' => $color[$package->status] ?? 'slate', 'dot' => true]) ?>
    <?php if ($package->isPublic): ?><?= component('badge', ['label' => 'Public', 'color' => 'emerald']) ?><?php endif ?>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-4">
        <?= component('card', ['title' => 'Details', 'body' => (function () use ($package) {
            $rows = [
                'Destination' => e($package->destination),
                'Duration' => e($package->durationLabel()),
                'Departs from' => e($package->startLocation ?? '—'),
                'Price per person' => e($package->priceLabel()),
                'Hotel' => e($package->hotelSummary ?? '—'),
                'Transport' => e($package->transportSummary ?? '—'),
                'Meals' => e($package->mealsSummary ?? '—'),
            ];
            $html = '<dl class="grid gap-x-4 gap-y-2 sm:grid-cols-2 text-sm">';
            foreach ($rows as $k => $v) {
                $html .= '<div><dt class="text-slate-500">' . e($k) . '</dt><dd class="text-slate-900">' . $v . '</dd></div>';
            }

            return $html . '</dl>';
        })()]) ?>

        <div id="itinerary">
            <?= component('card', ['title' => 'Itinerary', 'body' => (function () use ($items, $editable, $base) {
                $html = '';
                if ($editable) {
                    $html .= '<form method="post" action="' . $base . '/items" class="mb-4 grid gap-2 sm:grid-cols-6" data-once>' . csrf_field()
                        . '<input type="number" name="day_no" min="1" max="365" placeholder="Day" aria-label="Day" class="form-input">'
                        . '<input type="text" name="title" required maxlength="180" placeholder="e.g. Desert safari with BBQ dinner" aria-label="Title" class="form-input sm:col-span-3">'
                        . '<div class="sm:col-span-2"><button class="btn btn-secondary btn-sm">Add line</button></div>'
                        . '<textarea name="description" rows="2" maxlength="2000" placeholder="Details (optional)" aria-label="Details" class="form-input sm:col-span-6"></textarea></form>';
                }
                if ($items === []) {
                    return $html . '<p class="text-sm text-slate-500">No itinerary yet. A package needs at least one line before it can be published.</p>';
                }
                $html .= '<ol class="divide-y divide-slate-100">';
                foreach ($items as $i) {
                    /** @var \App\Models\TourPackageItem $i */
                    $html .= '<li class="py-3 text-sm"><div class="flex items-start justify-between gap-3"><div>'
                        . '<p class="font-medium text-slate-900">' . ($i->dayNo !== null ? '<span class="mr-2 rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Day ' . (int) $i->dayNo . '</span>' : '') . e($i->title) . '</p>'
                        . ($i->description ? '<p class="mt-1 whitespace-pre-line text-slate-600">' . e($i->description) . '</p>' : '') . '</div>';
                    if ($editable) {
                        $html .= '<form method="post" action="' . $base . '/items/' . (int) $i->id . '">' . csrf_field()
                            . '<input type="hidden" name="_method" value="DELETE"><button class="btn btn-ghost btn-sm text-red-600">Remove</button></form>';
                    }
                    $html .= '</div>';
                    if ($editable) {
                        $html .= '<details class="mt-2"><summary class="cursor-pointer text-xs font-medium text-brand-600">Edit<span class="sr-only"> ' . e($i->title) . '</span></summary>'
                            . '<form method="post" action="' . $base . '/items/' . (int) $i->id . '" class="mt-2 grid gap-2 sm:grid-cols-6" data-once>' . csrf_field() . '<input type="hidden" name="_method" value="PUT">'
                            . '<input type="number" name="day_no" min="1" max="365" value="' . e_attr((string) ($i->dayNo ?? '')) . '" placeholder="Day" aria-label="Day" class="form-input">'
                            . '<input type="text" name="title" required maxlength="180" value="' . e_attr($i->title) . '" aria-label="Title" class="form-input sm:col-span-3">'
                            . '<div class="sm:col-span-2"><button class="btn btn-secondary btn-sm">Save line</button></div>'
                            . '<textarea name="description" rows="2" maxlength="2000" aria-label="Details" class="form-input sm:col-span-6">' . e((string) $i->description) . '</textarea></form></details>';
                    }
                    $html .= '</li>';
                }

                return $html . '</ol>';
            })()]) ?>
        </div>

        <?php foreach (['Inclusions' => $package->inclusionsHtml, 'Exclusions' => $package->exclusionsHtml, 'Terms & conditions' => $package->termsHtml] as $title => $html): ?>
            <?php if ($html): // Sanitised at write time (HtmlSanitizer::fromPlainText) — paragraphs of escaped text only. ?>
                <?= component('card', ['title' => $title, 'body' => '<div class="space-y-2 text-sm text-slate-700">' . $html . '</div>']) ?>
            <?php endif ?>
        <?php endforeach ?>
    </div>

    <div class="space-y-4">
        <?= component('card', ['title' => 'Lifecycle', 'body' => (function () use ($package, $canEdit, $nextStatuses, $base) {
            $labels = ['draft' => 'Move back to draft', 'active' => $package->status === 'archived' ? 'Reactivate' : 'Activate', 'archived' => 'Archive'];
            $html = '<p class="mb-2 text-sm text-slate-600">Status: <span class="font-medium text-slate-900">' . e($package->statusLabel()) . '</span></p>';
            if (!$canEdit || $nextStatuses === []) {
                return $html;
            }
            $html .= '<div class="flex flex-wrap gap-2">';
            foreach ($nextStatuses as $s) {
                $html .= '<form method="post" action="' . $base . '/status" class="inline" data-once>' . csrf_field()
                    . '<input type="hidden" name="status" value="' . e_attr($s) . '"><button class="btn btn-secondary btn-sm">' . e($labels[$s] ?? ucfirst($s)) . '</button></form>';
            }

            return $html . '</div>';
        })()]) ?>

        <?= component('card', ['title' => 'Public listing', 'body' => (function () use ($package, $canPublish, $base) {
            $html = '<p class="mb-2 text-sm text-slate-600">' . ($package->isPublic ? 'Shown on the public site.' : 'Not shown on the public site.') . '</p>'
                . '<p class="mb-2 text-xs text-slate-400">Slug: <span class="font-mono">' . e($package->slug) . '</span></p>';
            if ($canPublish) {
                $on = !$package->isPublic;
                $blocked = $on ? ($package->status !== 'active' ? 'Only an active package can be published'
                    : ($package->price === null ? 'Set a price first'
                    : ($package->itemCount === 0 ? 'Add an itinerary line first' : ''))) : '';
                $html .= '<form method="post" action="' . $base . '/publish">' . csrf_field()
                    . '<input type="hidden" name="public" value="' . ($on ? '1' : '0') . '">'
                    . '<button class="btn btn-secondary btn-sm"' . ($blocked !== '' ? ' disabled title="' . e_attr($blocked) . '"' : '') . '>' . ($on ? 'Publish' : 'Unpublish') . '</button></form>'
                    . ($blocked !== '' ? '<p class="mt-2 text-xs text-slate-400">' . e($blocked) . '.</p>' : '');
            }

            return $html;
        })()]) ?>
    </div>
</div>
<?php $this->stop(); ?>
