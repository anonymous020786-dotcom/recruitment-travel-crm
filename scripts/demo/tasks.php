<?php

declare(strict_types=1);

/**
 * Demo tasks (included by scripts/demo-data.php). Expects $app, $db, $staff (role key => User), $mumbai, $delhi in scope.
 * Standalone tasks go through TaskService; "automatic" ones are inserted the way the payment-reminder cron writes them.
 */

use App\Services\TaskService;
use App\Support\Ulid;

$tasks = $app->get(TaskService::class);
$day = static fn (int $offset): string => gmdate('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' days'));

foreach ([
    ['manager', 'counselor', 'Call Aarav Sharma about passport renewal', 'high', 1, '11:00', 'Passport expires in 5 months; UAE visa needs 6.'],
    ['manager', 'counselor', 'Collect original certificates from Vivaan Khan', 'medium', 3, null, null],
    ['manager', 'recruiter', 'Send 5 shortlisted CVs to Al Noor Contracting', 'urgent', 0, '16:00', 'Employer wants them before end of day.'],
    ['manager', 'docs', 'Attest educational certificates for batch of 6 candidates', 'medium', 6, null, 'Courier to the attestation centre on Monday.'],
    ['manager', 'accounts', 'Reconcile UPI collections for this week', 'low', 2, null, null],
    ['counselor', 'counselor', 'Follow up with website enquiries from the weekend', 'medium', 0, null, null],
    ['admin', 'manager', 'Review the monthly recruitment funnel and agree targets', 'medium', 7, null, null],
    ['recruiter', 'recruiter', 'Confirm interview slots with Gulf Hospitality', 'high', 2, '10:30', null],
] as [$by, $to, $title, $priority, $due, $time, $note]) {
    $tasks->create([
        'title' => $title, 'description' => $note ?? '', 'priority' => $priority, 'due_date' => $day($due), 'due_time' => $time ?? '',
        'assigned_to' => (string) $staff[$to]->id,
    ], $staff[$by]);
}

// a few in the past (the "Overdue" tab) and a few already closed — these are back-dated straight in the table
$insert = static function (string $title, string $to, string $priority, ?string $due, string $status, string $source = 'manual', string $type = 'none', ?int $relatedId = null) use ($db, $staff, $mumbai, $day): void {
    $db->insertRow('tasks', [
        'public_id' => Ulid::generate(), 'title' => $title, 'related_type' => $type, 'related_id' => $relatedId, 'branch_id' => $mumbai, 'assigned_to' => $staff[$to]->id,
        'priority' => $priority, 'due_date' => $due, 'status' => $status, 'completed_at' => $status === 'completed' ? gmdate('Y-m-d H:i:s', strtotime('-1 day')) : null,
        'source' => $source, 'created_by' => $source === 'manual' ? $staff['manager']->id : null,
    ]);
};
$insert('Chase medical report for two candidates', 'docs', 'high', $day(-3), 'pending');
$insert('Send offer letter copy to candidate', 'recruiter', 'medium', $day(-1), 'pending');
$insert('Update visa status for batch 14', 'docs', 'medium', $day(-6), 'completed');
$insert('Book airport pickup for departing candidates', 'counselor', 'low', $day(-2), 'completed');
$insert('Print training material', 'counselor', 'low', $day(-4), 'cancelled');

$invoice = $db->selectOne("SELECT id, invoice_number FROM invoices WHERE status <> 'paid' ORDER BY id LIMIT 1");
if ($invoice !== null) {
    $insert('Collect payment — invoice ' . $invoice['invoice_number'] . ' is overdue', 'accounts', 'high', $day(0), 'pending', 'system', 'invoice', (int) $invoice['id']);
}
$candidate = $db->selectOne('SELECT id FROM candidates ORDER BY id LIMIT 1');
if ($candidate !== null) {
    $insert('Passport expiring soon — start renewal', 'counselor', 'urgent', $day(2), 'pending', 'system', 'candidate', (int) $candidate['id']);
}
