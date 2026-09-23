<?php
/** @var \App\Models\TourBooking $booking @var array<string,string> $packages @var array<int|string,string> $assignees */
$this->layout('layouts.app', ['title' => 'Edit ' . $booking->bookingNumber, 'currentPath' => '/tours/bookings']);
$this->start('content');
?>
<?= component('page-header', [
    'title' => 'Edit ' . $booking->bookingNumber,
    'subtitle' => $booking->customerName,
    'breadcrumbs' => [
        ['label' => 'Tour bookings', 'href' => '/tours/bookings'],
        ['label' => $booking->bookingNumber, 'href' => '/tours/bookings/' . $booking->publicId],
        ['label' => 'Edit'],
    ],
]) ?>

<form method="post" action="/tours/bookings/<?= e_attr($booking->publicId) ?>" data-once>
    <?= csrf_field() ?>
    <input type="hidden" name="_method" value="PUT">
    <input type="hidden" name="record_version" value="<?= (int) $booking->recordVersion ?>">
    <div class="card card-body"><?= $this->partial('crm.tours.bookings._form', compact('booking', 'packages', 'assignees')) ?></div>
    <div class="mt-4 flex items-center gap-2">
        <button type="submit" class="btn btn-primary">Save changes</button>
        <a href="/tours/bookings/<?= e_attr($booking->publicId) ?>" class="btn btn-ghost">Cancel</a>
    </div>
</form>
<?php $this->stop(); ?>
