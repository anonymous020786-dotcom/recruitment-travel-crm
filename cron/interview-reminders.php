<?php

declare(strict_types=1);

/**
 * Interview reminders (hourly): tells an application's owner about interviews
 * happening tomorrow and today. Deduped per interview + bucket, so each
 * reminder fires exactly once however often the job runs.
 */

use App\Notifications\NotificationService;
use App\Repositories\InterviewRepository;
use App\Support\Application;
use App\Support\CronRunner;

/** @var Application $app */
$app = require __DIR__ . '/_bootstrap.php';

return CronRunner::finish($app->get(CronRunner::class)->run('interview-reminders', 600, function (callable $progress) use ($app): int {
    $repo = $app->get(InterviewRepository::class);
    $notify = $app->get(NotificationService::class);
    $sent = 0;

    foreach (['today' => gmdate('Y-m-d'), 'tomorrow' => gmdate('Y-m-d', strtotime('+1 day'))] as $bucket => $date) {
        foreach ($repo->openOn($date) as $r) {
            $time = isset($r['scheduled_time']) ? ' at ' . substr((string) $r['scheduled_time'], 0, 5) : '';
            $notify->notify(
                userId: (int) $r['assigned_to'],
                type: 'interview_reminder',
                title: "Interview {$bucket}{$time}: {$r['candidate_name']}",
                body: "Round {$r['round_no']} · {$r['job_title']} · " . str_replace('_', ' ', (string) $r['type']),
                linkType: 'application',
                linkId: (int) $r['application_id'],
                linkFragment: 'interviews',
                dedupeKey: "interview:{$r['id']}:{$bucket}",
            );
            $sent++;
            $progress(1);
        }
    }

    return $sent;
}));
