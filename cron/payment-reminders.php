<?php

declare(strict_types=1);

/**
 * Payment reminders (daily): tells the person who raised an invoice and the branch's accounts team about
 * invoices that are past their due date and still owe money — weekly, never more often (see
 * PaymentReminderService). Safe to run repeatedly.
 */

use App\Services\PaymentReminderService;
use App\Support\Application;
use App\Support\CronRunner;

/** @var Application $app */
$app = require __DIR__ . '/_bootstrap.php';

exit($app->get(CronRunner::class)->run('payment-reminders', 900, function (callable $progress) use ($app): int {
    $sent = $app->get(PaymentReminderService::class)->remindOverdue(gmdate('Y-m-d'));
    $progress($sent);

    return $sent;
}));
