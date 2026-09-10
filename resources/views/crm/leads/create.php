<?php
/** @var array $duplicates */
$this->layout('layouts.app', ['title' => 'New lead', 'currentPath' => '/leads']);
$this->start('content');

$duplicates = $duplicates ?: (session()?->get('_duplicates') ?? []);
?>
<?= component('page-header', [
    'title' => 'New lead',
    'breadcrumbs' => [['label' => 'Leads', 'href' => '/leads'], ['label' => 'New lead']],
]) ?>

<?php if ($duplicates): ?>
    <div class="mb-4">
        <?= component('alert', [
            'type' => 'warning',
            'title' => 'Possible duplicate' . (count($duplicates) > 1 ? 's' : '') . ' found',
            'slot' => (function () use ($duplicates) {
                $rows = '';
                foreach ($duplicates as $d) {
                    $rows .= '<li><a class="font-medium" href="/leads/' . e_attr($d['public_id']) . '">'
                        . e($d['name']) . '</a> · ' . e($d['lead_number']) . ' · ' . e($d['phone'])
                        . ' · ' . e($d['status_label']) . '</li>';
                }
                return '<ul class="mt-1 list-disc pl-5 space-y-0.5">' . $rows . '</ul>'
                    . '<p class="mt-2">Review the above, or tick the box below to create this lead anyway.</p>';
            })(),
        ]) ?>
    </div>
<?php endif ?>

<form method="post" action="/leads" data-once>
    <?= csrf_field() ?>

    <div class="card card-body">
        <?= $this->partial('crm.leads._form', compact('sources', 'assignees', 'countries')) ?>

        <?php if ($duplicates): ?>
            <label class="mt-2 flex items-center gap-2 text-sm">
                <input type="checkbox" name="confirm_not_duplicate" value="1">
                This is not a duplicate — create it anyway
            </label>
        <?php endif ?>
    </div>

    <div class="mt-4 flex items-center gap-2">
        <button type="submit" class="btn btn-primary">Create lead</button>
        <a href="/leads" class="btn btn-ghost">Cancel</a>
    </div>
</form>
<?php $this->stop(); ?>
