<?php
/**
 * Travel card on an application: flights, departure, arrival, placement and travel profile.
 * @var \App\Models\Application $app
 * @var array{flights:list<\App\Models\FlightBooking>,departure:?\App\Models\DepartureRecord,placement:?\App\Models\Placement,profile:?\App\Models\TravelProfile,flightMoves:array<string,list<string>>,canTickets:bool,canDeparture:bool,canPlacement:bool,canProfile:bool} $travel
 */
$flightColor = ['planned' => 'slate', 'booked' => 'blue', 'issued' => 'green', 'changed' => 'amber', 'flown' => 'indigo', 'cancelled' => 'red'];
$placementColor = ['active' => 'green', 'completed' => 'indigo', 'terminated' => 'red', 'absconded' => 'red'];
$base = '/applications/' . e_attr($app->publicId);
$fmt = static fn (?string $dt): string => $dt === null ? '—' : e(substr($dt, 0, 16));
$local = static fn (?string $dt): string => $dt === null ? '' : e_attr(str_replace(' ', 'T', substr($dt, 0, 16)));

/** The itinerary inputs, shared by "add" and "edit". */
$flightFields = static function (?\App\Models\FlightBooking $f) use ($local): string {
    $v = static fn (?string $x): string => e_attr($x ?? '');

    return '<div class="grid gap-2 sm:grid-cols-2">'
        . '<input type="text" name="pnr" maxlength="20" value="' . $v($f?->pnr) . '" placeholder="Booking reference (PNR)" aria-label="PNR" class="form-input font-mono uppercase">'
        . '<input type="text" name="airline" maxlength="120" value="' . $v($f?->airline) . '" placeholder="Airline" aria-label="Airline" class="form-input">'
        . '<input type="text" name="flight_number" maxlength="20" value="' . $v($f?->flightNumber) . '" placeholder="Flight number" aria-label="Flight number" class="form-input uppercase">'
        . '<input type="text" name="baggage_allowance" maxlength="60" value="' . $v($f?->baggageAllowance) . '" placeholder="Baggage (e.g. 30 kg)" aria-label="Baggage" class="form-input">'
        . '<input type="text" name="departure_airport" maxlength="3" value="' . $v($f?->departureAirport) . '" placeholder="From (DEL)" aria-label="Departure airport" class="form-input uppercase">'
        . '<input type="text" name="arrival_airport" maxlength="3" value="' . $v($f?->arrivalAirport) . '" placeholder="To (DXB)" aria-label="Arrival airport" class="form-input uppercase">'
        . '<label class="text-xs text-slate-500">Departs<input type="datetime-local" name="departure_at" value="' . $local($f?->departureAt) . '" class="form-input mt-1 w-full"></label>'
        . '<label class="text-xs text-slate-500">Arrives<input type="datetime-local" name="arrival_at" value="' . $local($f?->arrivalAt) . '" class="form-input mt-1 w-full"></label>'
        . '<input type="number" name="ticket_price" min="0" step="0.01" value="' . $v($f?->ticketPrice) . '" placeholder="Ticket price" aria-label="Ticket price" class="form-input">'
        . '<input type="text" name="currency" maxlength="3" value="' . $v($f?->currency) . '" placeholder="Currency (INR)" aria-label="Currency" class="form-input uppercase">'
        . '</div>'
        . '<textarea name="notes" rows="2" maxlength="2000" placeholder="Notes (optional)" aria-label="Notes" class="form-input w-full">' . e($f?->notes ?? '') . '</textarea>';
};

$html = '';
$flights = $travel['flights'];
$departure = $travel['departure'];
$placement = $travel['placement'];
$hasLive = false;
foreach ($flights as $f) {
    $hasLive = $hasLive || $f->isLive();
}

// ---- flights ----
$html .= '<h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Flights</h3>';
if ($flights === []) {
    $html .= '<p class="text-sm text-slate-500">No flight has been arranged yet.</p>';
}
foreach ($flights as $f) {
    /** @var \App\Models\FlightBooking $f */
    $fb = '/flights/' . e_attr($f->publicId);
    $html .= '<div class="mt-2 rounded border border-slate-100 p-3 text-sm"><div class="flex flex-wrap items-center justify-between gap-2">'
        . '<p class="font-medium text-slate-900">' . e($f->route())
        . ($f->airline || $f->flightNumber ? ' <span class="font-normal text-slate-600">· ' . e(trim(($f->airline ?? '') . ' ' . ($f->flightNumber ?? ''))) . '</span>' : '') . '</p>'
        . component('badge', ['label' => $f->statusLabel(), 'color' => $flightColor[$f->status] ?? 'slate', 'dot' => true]) . '</div>'
        . '<p class="mt-1 text-slate-600">Departs ' . $fmt($f->departureAt) . ' · arrives ' . $fmt($f->arrivalAt)
        . ($f->pnr ? ' · PNR <span class="font-mono">' . e($f->pnr) . '</span>' : '')
        . ($f->baggageAllowance ? ' · ' . e($f->baggageAllowance) : '')
        . ($f->ticketPrice !== null ? ' · ' . e(($f->currency ?? '') . ' ' . number_format((float) $f->ticketPrice, 2)) : '') . '</p>';
    if ($f->notes) {
        $html .= '<p class="mt-1 whitespace-pre-line text-xs text-slate-500">' . e($f->notes) . '</p>';
    }

    $moves = $travel['flightMoves'][$f->publicId] ?? [];
    if ($travel['canTickets'] && $f->isLive()) {
        $html .= '<div class="mt-3 space-y-2 border-t border-slate-100 pt-3">';
        if ($moves !== []) {
            $html .= '<div class="flex flex-wrap gap-2">';
            foreach ($moves as $m) {
                $danger = $m === 'cancelled';
                $html .= '<form method="post" action="' . $fb . '/status" class="inline" data-once' . ($danger ? ' data-confirm="Cancel this flight?"' : '') . '>' . csrf_field()
                    . '<input type="hidden" name="status" value="' . e_attr($m) . '">'
                    . '<button class="btn btn-ghost btn-sm' . ($danger ? ' text-red-600' : '') . '">' . e($m === 'changed' ? 'Mark changed' : ($danger ? 'Cancel flight' : 'Mark ' . $m)) . '</button></form>';
            }
            $html .= '</div>';
        }
        $html .= '<details><summary class="cursor-pointer text-xs font-medium text-slate-600">Edit itinerary</summary>'
            . '<form method="post" action="' . $fb . '" class="mt-2 space-y-2" data-once>' . csrf_field() . $flightFields($f)
            . '<button class="btn btn-secondary btn-sm">Save</button></form></details></div>';
    }
    $html .= '</div>';
}

if ($travel['canTickets'] && !$hasLive && in_array($app->status, \App\Services\TravelService::TICKETING, true)) {
    $html .= '<details class="mt-3 rounded border border-slate-200 p-3"><summary class="cursor-pointer text-sm font-medium text-brand-600">Add a flight</summary>'
        . '<form method="post" action="' . $base . '/flights" class="mt-3 space-y-2" data-once>' . csrf_field()
        . '<select name="status" aria-label="Ticket status" class="form-select w-full">'
        . '<option value="planned">Planned — not ticketed yet</option><option value="booked">Booked</option><option value="issued">Ticket issued</option></select>'
        . $flightFields(null)
        . '<p class="text-xs text-slate-400">A booked or issued ticket needs the PNR and departure time, and moves the application to “Ticket booked”.</p>'
        . '<button class="btn btn-primary btn-sm">Add flight</button></form></details>';
}

// ---- departure / arrival ----
if ($departure !== null || ($app->status === 'ticket_booked' && $travel['canDeparture'])) {
    $html .= '<h3 class="mb-2 mt-5 text-xs font-semibold uppercase tracking-wide text-slate-500">Departure &amp; arrival</h3>';
}
if ($departure !== null) {
    $html .= '<p class="text-sm text-slate-700">Departed <strong>' . $fmt($departure->departedAt) . '</strong> · '
        . ($departure->hasArrived() ? 'arrived <strong>' . $fmt($departure->arrivedAt) . '</strong>' : '<span class="text-amber-700">arrival not confirmed yet</span>') . '</p>';
    if ($departure->notes) {
        $html .= '<p class="mt-1 whitespace-pre-line text-xs text-slate-500">' . e($departure->notes) . '</p>';
    }
}
if ($app->status === 'ticket_booked' && $travel['canDeparture']) {
    $html .= '<form method="post" action="' . $base . '/departure" class="mt-2 space-y-2" data-once>' . csrf_field()
        . '<label class="text-xs text-slate-500">Departed at (blank = now)<input type="datetime-local" name="departed_at" class="form-input mt-1 w-full"></label>'
        . '<input type="text" name="notes" maxlength="2000" placeholder="Notes (optional)" aria-label="Notes" class="form-input w-full">'
        . '<button class="btn btn-primary btn-sm">Record departure</button></form>';
}
if ($app->status === 'departed' && $departure !== null && !$departure->hasArrived() && $travel['canDeparture']) {
    $html .= '<form method="post" action="' . $base . '/arrival" class="mt-2 space-y-2" data-once>' . csrf_field()
        . '<label class="text-xs text-slate-500">Arrived at (blank = now)<input type="datetime-local" name="arrived_at" class="form-input mt-1 w-full"></label>'
        . '<input type="text" name="notes" maxlength="2000" placeholder="Notes (optional)" aria-label="Notes" class="form-input w-full">'
        . '<button class="btn btn-primary btn-sm">Confirm arrival</button></form>';
}

// ---- placement ----
if ($placement !== null || ($app->status === 'departed' && $departure !== null && $departure->hasArrived() && $travel['canPlacement'])) {
    $html .= '<h3 class="mb-2 mt-5 text-xs font-semibold uppercase tracking-wide text-slate-500">Placement</h3>';
}
if ($placement !== null) {
    $html .= '<div class="rounded border border-slate-100 p-3 text-sm"><div class="flex flex-wrap items-center justify-between gap-2">'
        . '<p class="font-medium text-slate-900">' . e($placement->employerName) . ' <span class="font-normal text-slate-600">· ' . e($placement->jobTitle) . '</span></p>'
        . component('badge', ['label' => $placement->statusLabel(), 'color' => $placementColor[$placement->status] ?? 'slate', 'dot' => true]) . '</div>'
        . '<p class="mt-1 text-slate-600">Placed ' . e($placement->placedOn)
        . ($placement->monthlySalary !== null ? ' · ' . e(($placement->currency ?? '') . ' ' . number_format((float) $placement->monthlySalary, 2)) . ' / month' : '')
        . ($placement->contractEnd ? ' · contract ends ' . e($placement->contractEnd) : '') . '</p>';
    if ($placement->isActive() && $travel['canPlacement']) {
        $html .= '<details class="mt-3 border-t border-slate-100 pt-3"><summary class="cursor-pointer text-xs font-medium text-slate-600">End this placement</summary>'
            . '<form method="post" action="/placements/' . e_attr($placement->publicId) . '/status" class="mt-2 space-y-2" data-once data-confirm="End this placement?">' . csrf_field()
            . '<select name="status" aria-label="Outcome" class="form-select w-full"><option value="completed">Contract completed</option><option value="terminated">Terminated</option><option value="absconded">Absconded</option></select>'
            . '<input type="text" name="reason" maxlength="255" placeholder="Reason (required for terminated / absconded)" aria-label="Reason" class="form-input w-full">'
            . '<button class="btn btn-ghost btn-sm text-red-600">End placement</button></form></details>';
    }
    $html .= '</div>';
} elseif ($app->status === 'departed' && $departure !== null && $departure->hasArrived() && $travel['canPlacement']) {
    $html .= '<form method="post" action="' . $base . '/placement" class="space-y-2" data-once>' . csrf_field()
        . '<div class="grid gap-2 sm:grid-cols-2">'
        . '<label class="text-xs text-slate-500">Placed on<input type="date" name="placed_on" required max="' . e_attr(gmdate('Y-m-d')) . '" value="' . e_attr(gmdate('Y-m-d')) . '" class="form-input mt-1 w-full"></label>'
        . '<label class="text-xs text-slate-500">Contract ends<input type="date" name="contract_end" class="form-input mt-1 w-full"></label>'
        . '<input type="number" name="monthly_salary" min="0" step="0.01" placeholder="Monthly salary" aria-label="Monthly salary" class="form-input">'
        . '<input type="text" name="currency" maxlength="3" placeholder="Currency (AED)" aria-label="Currency" class="form-input uppercase"></div>'
        . '<button class="btn btn-primary btn-sm">Record placement</button></form>';
}

// ---- travel profile ----
if ($travel['canProfile'] || $travel['profile'] !== null) {
    $p = $travel['profile'];
    $html .= '<h3 class="mb-2 mt-5 text-xs font-semibold uppercase tracking-wide text-slate-500">Travel profile'
        . ($p !== null ? ' · ' . e($p->readinessLabel()) : '') . '</h3>';
    if ($travel['canProfile']) {
        $html .= '<form method="post" action="' . $base . '/travel-profile" class="space-y-2" data-once>' . csrf_field()
            . '<input type="text" name="preferred_departure_city" maxlength="90" value="' . e_attr($p?->preferredDepartureCity ?? '') . '" placeholder="Preferred departure city" aria-label="Preferred departure city" class="form-input w-full">'
            . '<textarea name="notes" rows="2" maxlength="2000" placeholder="Travel notes (seat, meal, escort…)" aria-label="Travel notes" class="form-input w-full">' . e($p?->notes ?? '') . '</textarea>'
            . '<button class="btn btn-secondary btn-sm">Save profile</button></form>';
    } elseif ($p !== null) {
        $html .= '<p class="text-sm text-slate-600">' . e($p->preferredDepartureCity ?? '—') . '</p>';
    }
}

echo component('card', ['title' => 'Travel', 'body' => $html]);
