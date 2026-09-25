<?php
/**
 * The "Collect online" card on an invoice.
 *
 * @var \App\Models\Invoice $invoice
 * @var array{gateways:array<string,string>,links:list<array<string,mixed>>,can_create:bool,base:string,new_link:?string} $online
 */
$tone = ['paid' => 'green', 'pending' => 'blue', 'created' => 'slate', 'failed' => 'red', 'cancelled' => 'slate', 'expired' => 'slate', 'mismatch' => 'amber'];
$id = e_attr($invoice->publicId);
?>
<div id="online">
    <?= component('card', ['title' => 'Collect online', 'body' => (function () use ($online, $invoice, $id, $tone): string {
        $html = '';
        if ($online['new_link'] !== null) {
            $url = $online['base'] . '/pay/' . $online['new_link'];
            $text = 'Pay ' . $invoice->currency . ' ' . number_format((float) $invoice->outstanding(), 2) . ' for invoice ' . $invoice->invoiceNumber . ': ' . $url;
            $html .= '<div class="mb-4 rounded-lg bg-green-50 p-3 text-sm"><p class="font-medium text-green-900">Pay link ready</p>'
                . '<label class="sr-only" for="paylink">Pay link</label><input id="paylink" class="form-input mt-1" readonly value="' . e_attr($url) . '" onfocus="this.select()">'
                . '<p class="mt-2"><a class="btn btn-secondary btn-sm" href="https://wa.me/' . e_attr(preg_replace('/\D+/', '', (string) $invoice->customerPhone)) . '?text=' . rawurlencode($text) . '" target="_blank" rel="noopener noreferrer">Share on WhatsApp<span class="sr-only"> (opens in a new tab)</span></a></p></div>';
        }
        if ($online['can_create']) {
            if ($online['gateways'] === []) {
                $html .= '<p class="mb-3 text-sm text-slate-500">No payment gateway that can collect ' . e($invoice->currency) . ' is set up yet. The super admin adds one under Admin → Integrations.</p>';
            } else {
                $html .= '<form method="post" action="/invoices/' . $id . '/online-payments" class="mb-4 grid gap-2 sm:grid-cols-3" data-once>' . csrf_field()
                    . '<div><label class="form-label" for="gw">Gateway</label><select class="form-select" id="gw" name="gateway">';
                foreach ($online['gateways'] as $key => $label) {
                    $html .= '<option value="' . e_attr($key) . '">' . e($label) . '</option>';
                }
                $html .= '</select></div><div><label class="form-label" for="gw-amount">Amount <span class="text-xs font-normal text-slate-500">(empty = full balance)</span></label>'
                    . '<input class="form-input" id="gw-amount" name="amount" inputmode="decimal" placeholder="' . e_attr($invoice->outstanding()) . '"></div>'
                    . '<div class="flex items-end"><button type="submit" class="btn btn-primary">Create pay link</button></div></form>';
            }
        }
        if ($online['links'] === []) {
            return $html . '<p class="text-sm text-slate-500">No pay links yet.</p>';
        }
        $html .= '<ul class="divide-y divide-slate-100">';
        foreach ($online['links'] as $l) {
            $open = in_array($l['status'], ['created', 'pending', 'failed'], true);
            $html .= '<li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"><div>'
                . '<span class="font-medium text-slate-900">' . e($l['gateway_label']) . '</span> · ' . e($invoice->currency . ' ' . number_format((float) $l['amount'], 2))
                . '<p class="text-xs text-slate-500">' . e(substr((string) $l['created_at'], 0, 16)) . ($l['failure_reason'] ? ' · ' . e((string) $l['failure_reason']) : '') . '</p></div><div class="flex items-center gap-2">'
                . component('badge', ['label' => ucfirst((string) $l['status']), 'color' => $tone[$l['status']] ?? 'slate', 'dot' => true])
                . ($open ? '<a class="text-xs text-brand-600" href="/pay/' . e_attr((string) $l['public_id']) . '" target="_blank" rel="noopener">Open<span class="sr-only"> pay page (opens in a new tab)</span></a>' : '')
                . ($open && $online['can_create'] ? '<form method="post" action="/online-payments/' . e_attr((string) $l['public_id']) . '/cancel" class="inline">' . csrf_field() . '<button class="btn btn-ghost btn-sm" data-confirm="Cancel this pay link?">Cancel</button></form>' : '')
                . '</div></li>';
        }

        return $html . '</ul>';
    })()]) ?>
</div>
