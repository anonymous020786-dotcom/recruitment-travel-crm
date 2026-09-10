<?php

declare(strict_types=1);

namespace App\Mail\Transport;

use App\Mail\EmailMessage;

/**
 * The component that actually delivers a message. Selected by config('mail.driver')
 * and used by the queue processor (cron/process-email-queue.php), not by app code.
 */
interface Transport
{
    /** @throws \RuntimeException on a permanent or transient failure */
    public function send(EmailMessage $message): void;
}
