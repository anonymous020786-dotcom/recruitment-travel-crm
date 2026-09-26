<?php
/** @var array<string,list<array<string,mixed>>> $menus @var array<string,string> $labels @var int $max @var bool $canPublish */
$this->layout('layouts.app', ['title' => 'Menus — Pages', 'currentPath' => '/admin/cms']);
$this->start('content');
$err = static fn (string $k): string => ($e = error($k)) === null ? '' : '<p class="mt-1 text-xs text-red-600" role="alert">' . e($e) . '</p>';
?>
<?= component('page-header', ['title' => 'Website', 'subtitle' => 'The links in your public site\'s header and footer. While a menu has no active link, the built-in links are shown.']) ?>
<?= $this->partial('crm.admin.cms._tabs', ['active' => 'menus']) ?>

<div class="grid gap-6 lg:grid-cols-2">
<?php foreach ($labels as $menu => $label): $items = $menus[$menu]; ?>
    <section class="card card-body" aria-labelledby="menu-<?= e_attr($menu) ?>">
        <h2 id="menu-<?= e_attr($menu) ?>" class="mb-3 text-sm font-semibold text-slate-900"><?= e($label) ?> <span class="font-normal text-slate-400"><?= count($items) ?>/<?= (int) $max ?></span></h2>
        <?php if ($items === []): ?><p class="mb-3 text-sm text-slate-500">Using the built-in links.</p><?php endif ?>
        <ul class="space-y-2">
            <?php foreach ($items as $i => $it): $id = (int) $it['id']; ?>
                <li class="rounded-lg border border-slate-200 p-2">
                    <?php if ($canPublish): ?>
                        <form method="post" action="/admin/cms/menus/<?= $id ?>" class="grid gap-2 sm:grid-cols-[1fr_1.4fr_auto]"><?= csrf_field() ?><input type="hidden" name="_method" value="PUT">
                            <label class="sr-only" for="mi-l-<?= $id ?>">Label</label><input id="mi-l-<?= $id ?>" class="form-input py-1 text-sm" name="label" value="<?= e_attr((string) $it['label']) ?>" maxlength="60">
                            <label class="sr-only" for="mi-u-<?= $id ?>">Address</label><input id="mi-u-<?= $id ?>" class="form-input py-1 font-mono text-xs" name="url" value="<?= e_attr((string) $it['url']) ?>" maxlength="300">
                            <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                            <span class="flex flex-wrap gap-3 text-xs text-slate-600 sm:col-span-3">
                                <label class="flex items-center gap-1"><input type="checkbox" name="is_active" value="1" <?= (int) $it['is_active'] === 1 ? 'checked' : '' ?>> Shown</label>
                                <label class="flex items-center gap-1"><input type="checkbox" name="new_tab" value="1" <?= (int) $it['new_tab'] === 1 ? 'checked' : '' ?>> New tab</label>
                            </span>
                        </form>
                        <?= $err('item_' . $id) ?>
                        <div class="mt-1 flex gap-1">
                            <?php if ($i > 0): ?><form method="post" action="/admin/cms/menus/<?= $id ?>/move" class="inline"><?= csrf_field() ?><input type="hidden" name="dir" value="up"><button type="submit" class="btn btn-ghost btn-sm">↑<span class="sr-only"> Move <?= e((string) $it['label']) ?> up</span></button></form><?php endif ?>
                            <?php if ($i < count($items) - 1): ?><form method="post" action="/admin/cms/menus/<?= $id ?>/move" class="inline"><?= csrf_field() ?><input type="hidden" name="dir" value="down"><button type="submit" class="btn btn-ghost btn-sm">↓<span class="sr-only"> Move <?= e((string) $it['label']) ?> down</span></button></form><?php endif ?>
                            <form method="post" action="/admin/cms/menus/<?= $id ?>/delete" class="ml-auto inline" data-confirm="Remove this link?"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm text-red-600">Remove<span class="sr-only"> <?= e((string) $it['label']) ?></span></button></form>
                        </div>
                    <?php else: ?>
                        <span class="text-sm"><?= e((string) $it['label']) ?></span> <span class="font-mono text-xs text-slate-500"><?= e((string) $it['url']) ?></span>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>

        <?php if ($canPublish && count($items) < $max): ?>
            <form method="post" action="/admin/cms/menus" class="mt-4 grid gap-2 border-t border-slate-100 pt-4 sm:grid-cols-[1fr_1.4fr_auto]" data-once><?= csrf_field() ?>
                <input type="hidden" name="menu" value="<?= e_attr($menu) ?>"><input type="hidden" name="is_active" value="1">
                <label class="sr-only" for="new-l-<?= e_attr($menu) ?>">New link label</label><input id="new-l-<?= e_attr($menu) ?>" class="form-input py-1 text-sm" name="label" placeholder="Label" maxlength="60" required>
                <label class="sr-only" for="new-u-<?= e_attr($menu) ?>">New link address</label><input id="new-u-<?= e_attr($menu) ?>" class="form-input py-1 font-mono text-xs" name="url" placeholder="/visa-services" maxlength="300" required>
                <button type="submit" class="btn btn-primary btn-sm">Add</button>
            </form>
            <?php if (old('menu') === $menu): ?><?= $err('label') ?><?= $err('url') ?><?= $err('menu') ?><?php endif ?>
        <?php endif ?>
    </section>
<?php endforeach ?>
</div>
<?php $this->stop(); ?>
