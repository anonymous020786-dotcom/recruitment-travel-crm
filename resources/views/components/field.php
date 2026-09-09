<?php
/**
 * Label + control + error, wired to the session error bag.
 *
 * component('field', [
 *   'name' => 'email', 'label' => 'Email address', 'type' => 'email',
 *   'value' => old('email'), 'required' => true, 'hint' => '', 'autocomplete' => 'username',
 *   'control' => 'input'|'textarea'|'select', 'options' => ['a' => 'A'], 'attrs' => '',
 * ])
 */
$name = $name ?? '';
$id = $id ?? $name;
$control = $control ?? 'input';
$err = error($name);
$value = $value ?? old($name, '');
$invalid = $err !== null ? ' aria-invalid="true" aria-describedby="' . e_attr($id) . '-error"' : '';
$req = !empty($required) ? ' required' : '';
$extra = isset($attrs) ? ' ' . $attrs : '';
$ac = isset($autocomplete) ? ' autocomplete="' . e_attr($autocomplete) . '"' : '';
?>
<div class="mb-4">
    <?php if (!empty($label)): ?>
        <label class="form-label" for="<?= e_attr($id) ?>">
            <?= e($label) ?><?php if (!empty($required)): ?> <span class="text-red-500" aria-hidden="true">*</span><?php endif ?>
        </label>
    <?php endif ?>

    <?php if ($control === 'textarea'): ?>
        <textarea class="form-textarea" id="<?= e_attr($id) ?>" name="<?= e_attr($name) ?>" rows="<?= (int) ($rows ?? 4) ?>"<?= $req . $invalid . $extra ?>><?= e($value) ?></textarea>
    <?php elseif ($control === 'select'): ?>
        <select class="form-select" id="<?= e_attr($id) ?>" name="<?= e_attr($name) ?>"<?= $req . $invalid . $extra ?>>
            <?php if (isset($placeholder)): ?><option value=""><?= e($placeholder) ?></option><?php endif ?>
            <?php foreach (($options ?? []) as $optVal => $optLabel): ?>
                <option value="<?= e_attr((string) $optVal) ?>"<?= (string) $optVal === (string) $value ? ' selected' : '' ?>><?= e((string) $optLabel) ?></option>
            <?php endforeach ?>
        </select>
    <?php else: ?>
        <input class="form-input" type="<?= e_attr($type ?? 'text') ?>" id="<?= e_attr($id) ?>" name="<?= e_attr($name) ?>" value="<?= e_attr((string) $value) ?>"<?= $ac . $req . $invalid . $extra ?>>
    <?php endif ?>

    <?php if ($err !== null): ?>
        <p class="form-error" id="<?= e_attr($id) ?>-error"><?= e($err) ?></p>
    <?php elseif (!empty($hint)): ?>
        <p class="form-hint"><?= e($hint) ?></p>
    <?php endif ?>
</div>
