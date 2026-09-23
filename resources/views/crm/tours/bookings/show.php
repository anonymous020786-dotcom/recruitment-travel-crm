<?php
/**
 * @var \App\Models\TourBooking $booking @var list<array{from:?string,to:string,reason:?string,by:?string,at:string}> $history
 * @var bool $canEdit @var bool $canStatus @var bool $canCancel @var list<string> $nextStatuses
 */
$this->layout('layouts.app', ['title' => $booking->bookingNumber, 'currentPath' => '/tours/bookings']);
$this->start('content');

$color = ['inquiry' => 'slate', 'quoted' => 'amber', 'confirmed' => 'green', 'travelling' => 'blue', 'completed' => 'indigo', 'cancelled' => 'red'];
$base = '/tours/bookings/' . e_attr($booking->publicId);
$label = static fn (string $s): string => ucfirst($s);

$actions = $canEdit ? '<a href="' . $base . '/edit" class="btn btn-primary btn-sm">Edit</a>' : '';
?>
<?= component('page-header', [
    'title' => $booking->customerName,
    'subtitle' => $booking->bookingNumber . ' · ' . $booking->tripLabel(),
    'breadcrumbs' => [['label' => 'Tour bookings', 'href' => '/tours/bookings'], ['label' => $booking->bookingNumber]],
    'actions' => $actions,
]) ?>

<div class="mb-4 flex flex-wrap items-center gap-2">
    <?= component('badge', ['label' => $booking->statusLabel(), 'color' => $color[$booking->status] ?? 'slate', 'dot' => true]) ?>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-4">
        <?= component('card', ['title' => 'Trip', 'body' => (function () use ($booking) {
            $rows = [
                'Package' => $booking->packagePublicId !== null
                    ? '<a href="/tours/packages/' . e_attr($booking->packagePublicId) . '" class="text-brand-600 hover:underline">' . e((string) $booking->packageName) . '</a>'
                    : 'Custom trip',
                'Destination' => e($booking->packageDestination ?? '—'),
                'Travel date' => e($booking->travelDate ?? '—'),
                'Return date' => e($booking->returnDate ?? '—'),
                'Travellers' => e($booking->travellersLabel()),
                'Total' => (float) $booking->totalAmount > 0 ? '<span class="font-medium">' . e($booking->amountLabel()) . '</span>' : '—',
                'Handled by' => e($booking->assignedToName ?? '—'),
                'Created' => e(substr($booking->createdAt, 0, 16)),
            ];
            $html = '<dl class="grid gap-x-4 gap-y-2 sm:grid-cols-2 text-sm">';
            foreach ($rows as $k => $v) {
                $html .= '<div><dt class="text-slate-500">' . e($k) . '</dt><dd class="text-slate-900">' . $v . '</dd></div>';
            }
            $html .= '</dl>';
            if ($booking->notes) {
                $html .= '<p class="mt-3 whitespace-pre-line border-t border-slate-100 pt-3 text-sm text-slate-600">' . e($booking->notes) . '</p>';
            }

            return $html;
        })()]) ?>

        <?php if ($invoices !== null): ?>
            <div id="invoices">
                <?= $this->partial('crm.invoices._card', ['invoices' => $invoices, 'type' => 'tour_booking', 'reference' => $booking->bookingNumber, 'canCreate' => $canInvoice]) ?>
            </div>
        <?php endif ?>

        <div id="history">
            <?= component('card', ['title' => 'Status history', 'body' => (function () use ($history, $label) {
                $html = '<ol class="space-y-3 text-sm">';
                foreach ($history as $h) {
                    $html .= '<li class="flex gap-3"><span class="mt-0.5 text-slate-400" aria-hidden="true">•</span><div>'
                        . '<p class="text-slate-800">' . ($h['from'] !== null ? e($label($h['from'])) . ' → ' : '') . '<strong>' . e($label($h['to'])) . '</strong></p>'
                        . ($h['reason'] ? '<p class="text-slate-600">' . e($h['reason']) . '</p>' : '')
                        . '<p class="text-xs text-slate-400">' . ($h['by'] ? e($h['by']) . ' · ' : '') . e(substr($h['at'], 0, 16)) . '</p></div></li>';
                }

                return $html . '</ol>';
            })()]) ?>
        </div>
    </div>

    <div class="space-y-4">
        <?= component('card', ['title' => 'Customer', 'body' => (function () use ($booking) {
            $html = '<p class="text-sm font-medium text-slate-900">' . e($booking->customerName) . '</p>';
            if ($booking->customerPhone) {
                $html .= '<p class="text-sm text-slate-600"><a href="tel:' . e_attr(preg_replace('/[^0-9+]/', '', $booking->customerPhone) ?? '') . '" class="hover:underline">' . e($booking->customerPhone) . '</a></p>';
            }
            if ($booking->customerEmail) {
                $html .= '<p class="text-sm text-slate-600"><a href="mailto:' . e_attr($booking->customerEmail) . '" class="hover:underline">' . e($booking->customerEmail) . '</a></p>';
            }

            return $html;
        })()]) ?>

        <?= component('card', ['title' => 'Move booking', 'body' => (function () use ($booking, $canStatus, $canCancel, $nextStatuses, $label, $base) {
            if (!$canStatus) {
                return '<p class="text-sm text-slate-500">You do not have permission to change the status.</p>';
            }
            $moves = array_values(array_filter($nextStatuses, static fn (string $s): bool => $s !== 'cancelled' || $canCancel));
            if ($moves === []) {
                return '<p class="text-xs text-slate-500">No further moves are possible from ' . e($label($booking->status)) . '.</p>';
            }
            $html = '<form method="post" action="' . $base . '/status" class="space-y-2" data-once>' . csrf_field()
                . '<input type="hidden" name="record_version" value="' . (int) $booking->recordVersion . '">'
                . '<select name="status" aria-label="Move to" class="form-select w-full">';
            foreach ($moves as $s) {
                $html .= '<option value="' . e_attr($s) . '">' . e($label($s)) . '</option>';
            }
            $html .= '</select>'
                . '<input type="text" name="reason" maxlength="255" placeholder="Reason (required to cancel)" aria-label="Reason" class="form-input w-full">'
                . '<button class="btn btn-secondary btn-sm">Move</button></form>'
                . '<p class="mt-2 text-xs text-slate-400">Quoting needs a price; confirming needs a price and a future travel date; a trip can start on or after its travel date.</p>';

            return $html;
        })()]) ?>
    </div>
</div>
<?php $this->stop(); ?>
