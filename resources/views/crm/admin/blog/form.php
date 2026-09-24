<?php
/** @var ?array<string,mixed> $post */
$editing = $post !== null;
$this->layout('layouts.app', ['title' => $editing ? 'Edit ' . $post['title'] : 'Write a post', 'currentPath' => '/admin/blog']);
$this->start('content');

$action = $editing ? '/admin/blog/' . e_attr($post['public_id']) : '/admin/blog';
$status = $editing ? (string) $post['status'] : 'draft';
$slugLocked = $editing && $post['published_at'] !== null;
$readOnly = $status === 'archived';
$val = static fn (string $key, string $col): string => (string) old($key, $editing ? (string) ($post[$col] ?? '') : '');
$colors = ['draft' => 'amber', 'published' => 'green', 'archived' => 'slate'];
?>
<?= component('page-header', [
    'title' => $editing ? $post['title'] : 'Write a post',
    'breadcrumbs' => [['label' => 'Blog', 'href' => '/admin/blog'], ['label' => $editing ? 'Edit' : 'New post']],
]) ?>

<?php if (error('form')): ?><div class="mb-4"><?= component('alert', ['type' => 'danger', 'message' => error('form')]) ?></div><?php endif ?>

<?php if ($editing): ?>
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <?= component('badge', ['label' => ucfirst($status), 'color' => $colors[$status] ?? 'slate', 'dot' => true]) ?>
        <?php $id = e_attr($post['public_id']); $btn = static fn (string $verb, string $label, string $cls, string $confirm = ''): string
            => '<form method="post" action="/admin/blog/' . $id . '/' . $verb . '" class="inline">' . csrf_field()
                . '<button type="submit" class="btn ' . $cls . ' btn-sm"' . ($confirm !== '' ? ' data-confirm="' . e_attr($confirm) . '"' : '') . '>' . e($label) . '</button></form>'; ?>
        <?php if ($status === 'published'): ?>
            <a class="btn btn-secondary btn-sm" href="/blog/<?= e_attr($post['slug']) ?>" target="_blank" rel="noopener">View on the site<span class="sr-only"> (opens in a new tab)</span></a>
            <?= $btn('unpublish', 'Move back to draft', 'btn-secondary', 'Take this post off the public site and make it a draft?') ?>
            <?= $btn('archive', 'Archive', 'btn-ghost', 'Archive this post? It stays out of the public site.') ?>
        <?php elseif ($status === 'draft'): ?>
            <?= $btn('publish', 'Publish', 'btn-primary', 'Publish this post on the public blog now?') ?>
            <?= $btn('archive', 'Archive', 'btn-ghost', 'Archive this draft?') ?>
        <?php else: ?>
            <?= $btn('unpublish', 'Restore to draft', 'btn-secondary') ?>
            <?= $btn('publish', 'Publish again', 'btn-primary', 'Publish this post on the public blog now?') ?>
        <?php endif ?>
    </div>
    <?php if ($readOnly): ?><div class="mb-4"><?= component('alert', ['type' => 'info', 'message' => 'This post is archived. Restore it to a draft to edit it.']) ?></div><?php endif ?>
<?php endif ?>

<div class="grid gap-6 lg:grid-cols-2">
    <form method="post" action="<?= $action ?>" class="card card-body" data-once>
        <?= csrf_field() ?>
        <?php if ($editing): ?><input type="hidden" name="_method" value="PUT"><?php endif ?>
        <fieldset <?= $readOnly ? 'disabled' : '' ?>>
            <?= component('field', ['name' => 'title', 'label' => 'Title', 'required' => true, 'value' => $val('title', 'title'), 'attrs' => 'maxlength="180"']) ?>
            <div class="mb-4">
                <label class="form-label" for="slug">Web address</label>
                <div class="flex items-center gap-1 text-sm text-slate-500"><span>/blog/</span>
                    <input class="form-input" id="slug" name="slug" value="<?= e_attr($val('slug', 'slug')) ?>" maxlength="150" pattern="[a-z0-9]+(-[a-z0-9]+)*" <?= $slugLocked ? 'readonly' : '' ?> aria-describedby="slug-help<?= error('slug') ? ' slug-err' : '' ?>" placeholder="made-from-the-title"></div>
                <p id="slug-help" class="mt-1 text-xs text-slate-500"><?= $slugLocked ? 'Locked because the post has been published — changing it would break links.' : 'Optional. Leave empty to make it from the title. Fixed once published.' ?></p>
                <?php if (error('slug')): ?><p id="slug-err" class="mt-1 text-xs text-red-600" role="alert"><?= e((string) error('slug')) ?></p><?php endif ?>
            </div>
            <?= component('field', ['name' => 'excerpt', 'label' => 'Summary', 'value' => $val('excerpt', 'excerpt'), 'attrs' => 'maxlength="300"', 'hint' => 'One or two sentences for the blog list and search results. Optional — the start of the article is used if empty.']) ?>
            <div class="mb-4">
                <label class="form-label" for="body">Article</label>
                <textarea class="form-textarea font-mono text-sm" id="body" name="body" rows="18" required maxlength="60000" aria-describedby="body-help<?= error('body') ? ' body-err' : '' ?>"><?= e($val('body', 'body_source')) ?></textarea>
                <p id="body-help" class="mt-1 text-xs text-slate-500">Blank line = new paragraph. <code>## Heading</code>, <code>### Sub-heading</code>, <code>- bullet</code>, <code>1. numbered</code>, <code>**bold**</code>, <code>*italic*</code>, <code>[link text](/overseas-jobs)</code>.</p>
                <?php if (error('body')): ?><p id="body-err" class="mt-1 text-xs text-red-600" role="alert"><?= e((string) error('body')) ?></p><?php endif ?>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Save draft' ?></button>
                <a href="/admin/blog" class="btn btn-ghost">Back</a>
            </div>
        </fieldset>
    </form>

    <?php if ($editing): ?>
        <section class="card card-body" aria-labelledby="preview-h">
            <h2 id="preview-h" class="mb-3 text-sm font-semibold text-slate-900">Preview <span class="font-normal text-slate-500">(as saved)</span></h2>
            <div class="space-y-3 text-sm leading-relaxed text-slate-800 [&_a]:text-brand-600 [&_a]:underline [&_h3]:mt-4 [&_h3]:text-lg [&_h3]:font-semibold [&_h4]:mt-3 [&_h4]:font-semibold [&_ol]:list-decimal [&_ol]:pl-6 [&_ul]:list-disc [&_ul]:pl-6">
                <?= $post['body_html'] /* generated by BlogFormatter on save */ ?>
            </div>
        </section>
    <?php endif ?>
</div>
<?php $this->stop(); ?>
