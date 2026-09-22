<?php

declare(strict_types=1);

/**
 * Daily document-expiry sweep (docs/00-ARCHITECTURE.md §13.2):
 *   - notifies the candidate's counselor when a verified document's expiry
 *     falls on one of config('cron.reminder_windows.document') days out
 *     (deduped per document+bucket, so each window fires exactly once);
 *   - flips verified documents whose expiry has already passed to 'expired'
 *     (idempotent — DocumentService::expireDue() only matches status='verified').
 */

use App\Notifications\NotificationService;
use App\Repositories\CandidateDocumentRepository;
use App\Services\DocumentService;
use App\Support\Application;
use App\Support\CronRunner;

/** @var Application $app */
$app = require __DIR__ . '/_bootstrap.php';

exit($app->get(CronRunner::class)->run('document-expiry', 900, function (callable $progress) use ($app): int {
    $today = gmdate('Y-m-d');
    $windows = (array) $app->config()->get('cron.reminder_windows.document', [30, 15, 7, 1]);
    $maxDays = $windows === [] ? 0 : max($windows);

    $notify = $app->get(NotificationService::class);
    $rows = $app->get(CandidateDocumentRepository::class)->dueForExpiryReminder($maxDays, $today);
    $notified = 0;

    foreach ($rows as $r) {
        $counselorId = isset($r['assigned_counselor']) ? (int) $r['assigned_counselor'] : 0;
        if ($counselorId <= 0) {
            continue;
        }
        $days = (int) ((strtotime((string) $r['expires_at']) - strtotime($today)) / 86400);
        if (!in_array($days, $windows, true)) {
            continue;
        }

        $when = $days <= 0 ? 'has expired' : "expires in {$days} day" . ($days === 1 ? '' : 's');
        $notify->notify(
            userId: $counselorId,
            type: 'document_expiring',
            title: "{$r['type_label']} {$when}: {$r['candidate_name']}",
            body: "Expires {$r['expires_at']}.",
            linkType: 'candidate',
            linkId: (int) $r['candidate_id'],
            linkFragment: 'documents',
            dedupeKey: "docexp:{$r['id']}:{$days}",
        );
        $notified++;
        $progress(1);
    }

    $expired = $app->get(DocumentService::class)->expireDue($today);

    return $notified + $expired;
}));
