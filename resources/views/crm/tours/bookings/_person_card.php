<?php
/**
 * Tour bookings of the same person, shown on a candidate — one human, one identity across recruitment and tours.
 * @var list<\App\Models\TourBooking> $tourBookings
 */
$color = ['inquiry' => 'slate', 'quoted' => 'amber', 'confirmed' => 'green', 'travelling' => 'blue', 'completed' => 'indigo', 'cancelled' => 'red'];

$html = '<ul class="divide-y divide-slate-100">';
foreach ($tourBookings as $b) {
    /** @var \App\Models\TourBooking $b */
    $html .= '<li class="flex items-center justify-between gap-2 py-2 text-sm"><div>'
        . '<a href="/tours/bookings/' . e_attr($b->publicId) . '" class="font-medium text-slate-900">' . e($b->tripLabel()) . '</a>'
        . ' <span class="font-mono text-xs text-slate-400">' . e($b->bookingNumber) . '</span>'
        . ($b->travelDate ? '<p class="text-xs text-slate-500">' . e($b->travelDate) . '</p>' : '') . '</div>'
        . component('badge', ['label' => $b->statusLabel(), 'color' => $color[$b->status] ?? 'slate']) . '</li>';
}

echo component('card', ['title' => 'Tour bookings', 'body' => $html . '</ul>']);
