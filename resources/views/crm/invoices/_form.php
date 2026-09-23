<?php
/**
 * Invoice header + line rows, shared by create and edit.
 * @var \App\Models\Invoice|null $invoice @var list<array<string,string>> $lines
 * @var array{currency?:string,lines?:list<array<string,string>>} $prefill
 */
$invoice = $invoice ?? null;
$prefill = $prefill ?? [];
$rows = $lines ?? ($prefill['lines'] ?? []);

// After a failed submit the typed rows come back through old().
$oldDesc = old('line_description');
if (is_array($oldDesc)) {
    $oldQty = (array) old('line_quantity', []);
    $oldPrice = (array) old('line_unit_price', []);
    $rows = [];
    foreach ($oldDesc as $i => $d) {
        $rows[] = ['description' => (string) $d, 'quantity' => (string) ($oldQty[$i] ?? '1'), 'unit_price' => (string) ($oldPrice[$i] ?? '')];
    }
}
for ($pad = max(4, count($rows) + 2); count($rows) < $pad;) {
    $rows[] = ['description' => '', 'quantity' => '1', 'unit_price' => ''];
}

$val = static fn (string $k, ?string $fallback = ''): string => (string) old($k, $fallback);
?>
<div class="grid gap-x-5 sm:grid-cols-3">
    <?= component('field', ['name' => 'currency', 'label' => 'Currency', 'value' => $val('currency', $invoice?->currency ?? ($prefill['currency'] ?? 'INR')), 'attrs' => 'maxlength="3"']) ?>
    <?= component('field', ['name' => 'due_on', 'label' => 'Due date', 'type' => 'date', 'value' => $val('due_on', $invoice?->dueOn ?? ''), 'hint' => 'Blank = ' . (int) \App\Services\InvoiceService::DEFAULT_TERMS_DAYS . ' days after issue.']) ?>
    <div></div>
</div>

<h3 class="mb-2 mt-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Lines</h3>
<div class="table-wrap mb-4">
    <table class="data">
        <thead><tr><th>Description</th><th class="w-28">Qty</th><th class="w-40">Unit price</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $n => $r): ?>
            <tr>
                <td><input type="text" name="line_description[]" maxlength="255" value="<?= e_attr($r['description']) ?>" aria-label="Description, line <?= $n + 1 ?>" class="form-input w-full"></td>
                <td><input type="number" name="line_quantity[]" min="0.01" max="100000" step="0.01" value="<?= e_attr($r['quantity']) ?>" aria-label="Quantity, line <?= $n + 1 ?>" class="form-input w-full"></td>
                <td><input type="number" name="line_unit_price[]" min="0" max="99999999" step="0.01" value="<?= e_attr($r['unit_price']) ?>" aria-label="Unit price, line <?= $n + 1 ?>" class="form-input w-full"></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php if ($e = error('lines')): ?><p class="mb-3 text-sm text-red-600"><?= e((string) $e) ?></p><?php endif ?>

<div class="grid gap-x-5 sm:grid-cols-3">
    <?= component('field', ['name' => 'discount_total', 'label' => 'Discount (amount)', 'type' => 'number', 'value' => $val('discount_total', $invoice?->discountTotal ?? '0.00'), 'attrs' => 'min="0" step="0.01"']) ?>
    <?= component('field', ['name' => 'tax_total', 'label' => 'Tax (amount)', 'type' => 'number', 'value' => $val('tax_total', $invoice?->taxTotal ?? '0.00'), 'attrs' => 'min="0" step="0.01"']) ?>
    <div></div>
    <div class="sm:col-span-3">
        <?= component('field', ['name' => 'notes', 'label' => 'Notes', 'control' => 'textarea', 'rows' => 2, 'value' => $val('notes', $invoice?->notes ?? ''), 'hint' => 'Shown on the invoice. Totals are calculated for you when you save.', 'attrs' => 'maxlength="500"']) ?>
    </div>
</div>
