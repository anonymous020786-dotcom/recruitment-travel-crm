<?php
$this->layout('layouts.app', ['title' => 'Confirm password', 'currentPath' => '/account/security']);
$this->start('content');
?>
<div class="mx-auto max-w-md">
    <?= component('page-header', ['title' => 'Confirm your password']) ?>

    <div class="card">
        <div class="card-body">
            <p class="mb-4 text-sm text-slate-600">
                This is a secure area. Please re-enter your password to continue.
            </p>

            <form method="post" action="/confirm-password" data-once novalidate>
                <?= csrf_field() ?>
                <?= component('field', [
                    'name' => 'password', 'label' => 'Password', 'type' => 'password',
                    'required' => true, 'attrs' => 'autocomplete="current-password" autofocus',
                ]) ?>
                <div class="flex items-center gap-2">
                    <button type="submit" class="btn btn-primary">Confirm</button>
                    <a href="/dashboard" class="btn btn-ghost">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<?php $this->stop(); ?>
