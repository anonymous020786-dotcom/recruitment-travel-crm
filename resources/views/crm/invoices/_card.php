<?php
/**
 * Invoices of one application / tour booking.
 * @var list<\App\Models\Invoice> $invoices @var string $type @var string $reference @var bool $canCreate
 */
$color = ['draft' => 'slate', 'issued' => 'blue', 'partially_paid' => 'amber', 'paid' => 'green', 'void' => 'red'];

$html = '';
if ($canCreate) {
    $html .= '<p class="mb-3"><a href="/invoices/create?' . e_attr(http_build_query(['type' => $type, 'reference' => $reference])) . '" class="btn btn-secondary btn-sm">New invoice</a></p>';
}
if ($invoices === []) {
    $html .= '<p class="text-sm text-slate-500">No invoices yet.</p>';
} else {
    $html .= '<ul class="divide-y divide-slate-100">';
    foreach ($invoices as $i) {
        /** @var \App\Models\Invoice $i */
        $html .= '<li class="flex items-center justify-between gap-2 py-2 text-sm"><div>'
            . '<a href="/invoices/' . e_attr($i->publicId) . '" class="font-mono font-medium text-slate-900">' . e($i->invoiceNumber) . '</a>'
            . '<p class="text-xs text-slate-500">' . e($i->money($i->grandTotal))
            . (in_array($i->status, ['issued', 'partially_paid'], true) ? ' · ' . e($i->money($i->outstanding())) . ' outstanding' : '') . '</p></div>'
            . component('badge', ['label' => $i->statusLabel(), 'color' => $color[$i->status] ?? 'slate']) . '</li>';
    }
    $html .= '</ul>';
}

echo component('card', ['title' => 'Invoices', 'body' => $html]);
