<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Db;
use App\Support\Logger;

/**
 * Default mailer: inserts a row into `email_log` (status 'queued'); the actual
 * delivery is done by cron/process-email-queue.php. In non-production, also
 * writes the message to the application log for easy inspection.
 */
final class QueueMailer implements Mailer
{
    public function __construct(
        private readonly Db $db,
        private readonly Logger $logger,
        private readonly bool $logBody = false,
    ) {
    }

    public function send(string $toEmail, string $subject, string $html, ?string $text = null, ?string $template = null): bool
    {
        $this->db->affectingStatement(
            "INSERT INTO email_log (to_email, subject, template, body_html, body_text, status, attempts, created_at)
             VALUES (:to, :subject, :template, :html, :text, 'queued', 0, UTC_TIMESTAMP())",
            [
                'to'       => mb_substr($toEmail, 0, 180),
                'subject'  => mb_substr($subject, 0, 255),
                'template' => $template,
                'html'     => $html,
                'text'     => $text ?? strip_tags($html),
            ],
        );

        if ($this->logBody) {
            $this->logger->info('mail queued: {subject} -> {to}', [
                'subject' => $subject,
                'to'      => $toEmail,
                'body'    => strip_tags($text ?? $html),
            ]);
        } else {
            $this->logger->info('mail queued: {subject} -> {to}', ['subject' => $subject, 'to' => $toEmail]);
        }

        return true;
    }
}
