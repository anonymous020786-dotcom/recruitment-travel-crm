<?php
/**
 * @var list<array<string,mixed>> $rows @var int $total @var int $bytes @var int $page @var int $perPage @var string $q
 * @var array<string,mixed>|null $selected @var list<string> $usage @var float $maxMb @var bool $canManage @var bool $canPublish
 */
$this->layout('layouts.app', ['title' => 'Media — Pages', 'currentPath' => '/admin/cms']);
$this->start('content');
$size = static fn (int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : number_format($b / 1024, 0) . ' KB';
$site = rtrim((string) config('app.url', ''), '/');
?>
<?= component('page-header', ['title' => 'Website', 'subtitle' => 'Images for your pages. Every upload is cleaned, resized and gets a fast WebP copy. ' . number_format($total) . ' file(s), ' . $size($bytes) . '.']) ?>
<?= $this->partial('crm.admin.cms._tabs', ['active' => 'media']) ?>

<?php if (($e = error('form')) !== null): ?><div class="mb-4"><?= component('alert', ['type' => 'danger', 'message' => $e]) ?></div><?php endif ?>

<?php if ($canManage): ?>
    <form method="post" action="/admin/cms/media" enctype="multipart/form-data" class="card card-body mb-6 grid max-w-3xl gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end" data-once>
        <?= csrf_field() ?>
        <div><label class="form-label" for="md-file">Image</label><input id="md-file" class="form-input" type="file" name="file" accept="image/jpeg,image/png,image/webp,image/gif" required>
            <?php if (($e = error('file')) !== null): ?><p class="mt-1 text-xs text-red-600" role="alert"><?= e($e) ?></p><?php endif ?></div>
        <div><label class="form-label" for="md-alt">Describe it <span class="font-normal text-slate-400">(for screen readers and search)</span></label><input id="md-alt" class="form-input" name="alt" value="<?= e_attr((string) old('alt', '')) ?>" maxlength="200">
            <?php if (($e = error('alt')) !== null): ?><p class="mt-1 text-xs text-red-600" role="alert"><?= e($e) ?></p><?php endif ?></div>
        <button type="submit" class="btn btn-primary">Upload</button>
        <p class="text-xs text-slate-500 sm:col-span-3">JPEG, PNG, WebP or GIF, up to <?= e((string) $maxMb) ?> MB. Big pictures are scaled down to 2560 px; location data and other hidden metadata is removed.</p>
    </form>
<?php endif ?>

<?php if ($selected !== null): $md = '![' . ($selected['alt_text'] ?? '') . '](' . $selected['url'] . ')'; ?>
    <section class="card card-body mb-6 grid gap-4 lg:grid-cols-[16rem_1fr]" aria-label="Selected file">
        <img class="h-auto max-h-64 w-full rounded border border-slate-200 object-contain" src="<?= e_attr((string) $selected['thumb_url']) ?>" alt="<?= e_attr((string) ($selected['alt_text'] ?? '')) ?>">
        <div class="space-y-3 text-sm">
            <p class="font-medium text-slate-900"><?= e((string) $selected['original_name']) ?> <span class="font-normal text-slate-500">· <?= (int) $selected['width'] ?>×<?= (int) $selected['height'] ?> · <?= e($size((int) $selected['size_bytes'])) ?></span></p>
            <div><label class="form-label" for="md-code">Insert on a page</label><input id="md-code" class="form-input font-mono text-xs" readonly value="<?= e_attr($md) ?>" onfocus="this.select()"></div>
            <div><label class="form-label" for="md-url">Address (featured image, settings)</label><input id="md-url" class="form-input font-mono text-xs" readonly value="<?= e_attr((string) $selected['url']) ?>" onfocus="this.select()"></div>
            <?php if ($canManage): ?>
                <form method="post" action="/admin/cms/media/<?= e_attr((string) $selected['public_id']) ?>" class="grid gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-end"><?= csrf_field() ?><input type="hidden" name="_method" value="PUT">
                    <div><label class="form-label" for="md-ealt">Description</label><input id="md-ealt" class="form-input" name="alt" value="<?= e_attr((string) ($selected['alt_text'] ?? '')) ?>" maxlength="200"></div>
                    <div><label class="form-label" for="md-etitle">Title</label><input id="md-etitle" class="form-input" name="title" value="<?= e_attr((string) ($selected['title'] ?? '')) ?>" maxlength="120"></div>
                    <button type="submit" class="btn btn-secondary">Save</button>
                </form>
            <?php endif ?>
            <p class="text-xs text-slate-500"><?= $usage === [] ? 'Not used anywhere yet.' : 'Used in: ' . e(implode('; ', $usage)) ?></p>
            <?php if ($canPublish && $usage === []): ?>
                <form method="post" action="/admin/cms/media/<?= e_attr((string) $selected['public_id']) ?>/delete" data-confirm="Delete this image for good?"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm text-red-600">Delete</button></form>
            <?php endif ?>
        </div>
    </section>
<?php endif ?>

<form method="get" action="/admin/cms/media" class="mb-4 flex max-w-lg gap-2" role="search">
    <label class="sr-only" for="md-q">Search media</label>
    <input id="md-q" class="form-input" type="search" name="q" value="<?= e_attr($q) ?>" placeholder="Search file name or description" maxlength="80">
    <button type="submit" class="btn btn-secondary">Search</button>
</form>

<?php if ($rows === []): ?>
    <div class="card card-body text-sm text-slate-600"><?= $q !== '' ? 'Nothing matches.' : 'No images yet.' ?></div>
<?php else: ?>
    <ul class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
        <?php foreach ($rows as $m): ?>
            <li><a class="card block overflow-hidden" href="/admin/cms/media?file=<?= e_attr((string) $m['public_id']) ?><?= $q !== '' ? '&amp;q=' . rawurlencode($q) : '' ?>">
                <img class="aspect-square w-full bg-slate-100 object-cover" src="<?= e_attr((string) $m['thumb_url']) ?>" alt="<?= e_attr((string) ($m['alt_text'] ?? '')) ?>" loading="lazy" decoding="async">
                <span class="block truncate px-2 py-1 text-xs text-slate-600"><?= e((string) $m['original_name']) ?></span>
            </a></li>
        <?php endforeach ?>
    </ul>
    <?= component('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'baseUrl' => '/admin/cms/media', 'query' => array_filter(['q' => $q])]) ?>
<?php endif ?>
<?php $this->stop(); ?>
