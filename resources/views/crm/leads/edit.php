<?php
/** @var \App\Models\Lead $lead */
$this->layout('layouts.app', ['title' => 'Edit ' . $lead->name, 'currentPath' => '/leads']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => 'Edit lead',
    'breadcrumbs' => [
        ['label' => 'Leads', 'href' => '/leads'],
        ['label' => $lead->leadNumber, 'href' => '/leads/' . $lead->publicId],
        ['label' => 'Edit'],
    ],
]) ?>

<form method="post" action="/leads/<?= e_attr($lead->publicId) ?>" data-once>
    <?= csrf_field() ?>
    <input type="hidden" name="_method" value="PUT">
    <input type="hidden" name="record_version" value="<?= (int) $lead->recordVersion ?>">

    <div class="card card-body">
        <?php $this->partial('crm.leads._form', compact('lead', 'sources', 'assignees', 'countries')); ?>
    </div>

    <div class="mt-4 flex items-center gap-2">
        <button type="submit" class="btn btn-primary">Save changes</button>
        <a href="/leads/<?= e_attr($lead->publicId) ?>" class="btn btn-ghost">Cancel</a>
    </div>
</form>
<?php $this->stop(); ?>
