<?php

declare(strict_types=1);

/**
 * Lead follow-up reminders. Runs frequently (every ~15 min):
 *   - creates a deduped in-app notification for every pending follow-up that is
 *     due today or overdue, for its assignee;
 *   - once per day (at cron.followup_digest_hour UTC) emails each assignee a
 *     digest of what is due / overdue.
 * Idempotent: the notification dedupe key is per lead+date, and the daily email
 * only fires in the first run of the digest hour.
 */

use App\Mail\MailComposer;
use App\Notifications\NotificationService;
use App\Repositories\LeadFollowupRepository;
use App\Support\Application;
use App\Support\CronRunner;

/** @var Application $app */
$app = require __DIR__ . '/_bootstrap.php';

return CronRunner::finish($app->get(CronRunner::class)->run('followups', 280, function (callable $progress) use ($app): int {
    $today = gmdate('Y-m-d');
    $rows = $app->get(LeadFollowupRepository::class)->dueForReminder($today);
    if ($rows === []) {
        return 0;
    }

    $notify = $app->get(NotificationService::class);
    $appUrl = rtrim((string) $app->config()->get('app.url', ''), '/');

    $digestHour = (int) $app->config()->get('cron.followup_digest_hour', 8);
    $emailNow = (bool) $app->config()->get('mail.followup_reminders', true)
        && (int) gmdate('G') === $digestHour
        && (int) gmdate('i') < 15;

    /** @var array<int,array{name:string,email:?string,items:list<array<string,string>>,overdue:int}> $byUser */
    $byUser = [];

    foreach ($rows as $r) {
        $userId = (int) $r['assigned_to'];
        $leadId = (int) $r['lead_id'];
        $due = (string) $r['due_date'];

        $notify->followupReminder($userId, $leadId, (string) $r['lead_name'], (string) $r['lead_number'], $due);
        $progress(1);

        if (!$emailNow) {
            continue;
        }
        $byUser[$userId] ??= ['name' => (string) $r['assignee_name'], 'email' => $r['assignee_email'] ?? null, 'items' => [], 'overdue' => 0];
        $byUser[$userId]['items'][] = [
            'lead'    => (string) $r['lead_name'],
            'number'  => (string) $r['lead_number'],
            'due'     => $due . ($r['due_time'] ? ' ' . substr((string) $r['due_time'], 0, 5) : ''),
            'channel' => (string) $r['channel'],
            'subject' => (string) ($r['subject'] ?? ''),
            'url'     => $appUrl . '/leads/' . (string) $r['lead_public_id'] . '#followups',
        ];
        if ($due < $today) {
            $byUser[$userId]['overdue']++;
        }
    }

    if ($emailNow && $byUser !== []) {
        $mail = $app->get(MailComposer::class);
        foreach ($byUser as $u) {
            if (!is_string($u['email']) || $u['email'] === '') {
                continue;
            }
            $count = count($u['items']);
            $mail->send($u['email'], 'followup-due', [
                'subject' => "{$count} lead follow-up" . ($count === 1 ? '' : 's') . ' need attention',
                'name'    => $u['name'],
                'items'   => array_slice($u['items'], 0, 25),
                'overdue' => $u['overdue'],
            ]);
        }
    }

    return count($rows);
}));
