<?php

declare(strict_types=1);

namespace App\Mail\Transport;

use App\Mail\EmailMessage;
use App\Support\Logger;

/**
 * Writes the message to storage/logs instead of sending it. The default when no
 * SMTP credentials are configured (local / staging).
 */
final class LogTransport implements Transport
{
    public function __construct(private readonly Logger $logger)
    {
    }

    public function send(EmailMessage $message): void
    {
        $this->logger->info("mail (log transport)\n  to: {to}\n  subject: {subject}\n  ---\n{body}", [
            'to'      => $message->recipient(),
            'subject' => $message->subject,
            'body'    => $message->textBody(),
        ]);
    }
}
