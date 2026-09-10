<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Transport\Transport;
use App\Support\Db;
use App\Support\Logger;
use Throwable;

/**
 * Drains the `email_log` table (status `queued`) through the configured
 * transport. Transient failures are retried with backoff up to a cap, then
 * marked `failed`. Called by cron/process-email-queue.php.
 */
final class MailQueue
{
    /**
     * @param array{from_address:string,from_name:string,batch_size:int,
     *   max_attempts:int,retry_backoff_minutes:list<int>} $config
     */
    public function __construct(
        private readonly Db $db,
        private readonly Transport $transport,
        private readonly Logger $logger,
        private readonly array $config,
    ) {
    }

    /** @return int messages sent this run */
    public function process(?int $limit = null): int
    {
        $limit = $limit ?? (int) ($this->config['batch_size'] ?? 30);
        $limit = max(1, min($limit, 200));
        $maxAttempts = (int) ($this->config['max_attempts'] ?? 5);
        $backoff = (array) ($this->config['retry_backoff_minutes'] ?? [1, 5, 15, 60, 240]);

        $rows = $this->db->select(
            "SELECT id, to_email, subject, template, body_html, body_text, from_name, attempts
             FROM email_log
             WHERE status = 'queued' AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP())
             ORDER BY id ASC LIMIT {$limit}",
        );

        $sent = 0;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $message = new EmailMessage(
                to: (string) $row['to_email'],
                subject: (string) $row['subject'],
                html: (string) ($row['body_html'] ?? ''),
                text: $row['body_text'] !== null ? (string) $row['body_text'] : null,
                fromAddress: (string) ($this->config['from_address'] ?? 'no-reply@localhost'),
                fromName: (string) ($row['from_name'] ?? $this->config['from_name'] ?? 'CRM'),
            );

            try {
                $this->transport->send($message);
                $this->db->affectingStatement(
                    "UPDATE email_log SET status = 'sent', sent_at = UTC_TIMESTAMP(), error = NULL,
                     attempts = attempts + 1 WHERE id = :id",
                    ['id' => $id],
                );
                $sent++;
            } catch (Throwable $e) {
                $attempts = (int) $row['attempts'] + 1;
                $error = mb_substr($e->getMessage(), 0, 250);

                if ($attempts >= $maxAttempts) {
                    $this->db->affectingStatement(
                        "UPDATE email_log SET status = 'failed', attempts = :a, error = :err,
                         last_error_at = UTC_TIMESTAMP() WHERE id = :id",
                        ['id' => $id, 'a' => $attempts, 'err' => $error],
                    );
                    $this->logger->error('mail permanently failed after {n} attempts: {to}', [
                        'n' => $attempts, 'to' => $row['to_email'], 'error' => $error,
                    ]);
                } else {
                    $delay = (int) ($backoff[$attempts - 1] ?? end($backoff) ?? 60);
                    $this->db->affectingStatement(
                        "UPDATE email_log SET attempts = :a, error = :err, last_error_at = UTC_TIMESTAMP(),
                         next_attempt_at = (UTC_TIMESTAMP() + INTERVAL :d MINUTE) WHERE id = :id",
                        ['id' => $id, 'a' => $attempts, 'err' => $error, 'd' => $delay],
                    );
                    $this->logger->warning('mail send failed (attempt {n}), retrying in {d}m: {to}', [
                        'n' => $attempts, 'd' => $delay, 'to' => $row['to_email'], 'error' => $error,
                    ]);
                }
            }
        }

        return $sent;
    }

    /** Remove old sent/failed rows (called from cleanup). */
    public function prune(int $days = 30): int
    {
        return $this->db->affectingStatement(
            "DELETE FROM email_log WHERE status IN ('sent','failed')
             AND created_at < (UTC_TIMESTAMP() - INTERVAL :d DAY)",
            ['d' => $days],
        );
    }
}
