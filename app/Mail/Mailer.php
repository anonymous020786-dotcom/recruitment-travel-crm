<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Mail transport abstraction. V1 ships a `log` driver (writes to storage/logs)
 * and queues real mail into the `email_log` table for cron/process-email-queue.php.
 * A concrete SMTP driver is added later behind this same interface.
 */
interface Mailer
{
    /** Send now (or, for the queue driver, enqueue). Returns true on accept. */
    public function send(string $toEmail, string $subject, string $html, ?string $text = null, ?string $template = null): bool;
}
