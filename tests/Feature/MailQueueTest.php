<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\EmailMessage;
use App\Mail\MailQueue;
use App\Mail\Transport\Transport;
use Tests\Support\DbTestCase;

final class MailQueueTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Start from an empty queue — other tests leave transactional mail behind.
        $this->db->affectingStatement('DELETE FROM email_log');
    }

    private function queue(Transport $transport): MailQueue
    {
        return new MailQueue($this->db, $transport, $this->app->get(\App\Support\Logger::class), [
            'from_address' => 'crm@test.local', 'from_name' => 'CRM',
            'batch_size' => 30, 'max_attempts' => 3, 'retry_backoff_minutes' => [1, 5, 15],
        ]);
    }

    private function seed(string $to = 'q@test.local'): int
    {
        return (int) $this->db->insertRow('email_log', [
            'to_email' => $to, 'subject' => 'Test', 'template' => 't',
            'body_html' => '<p>hi</p>', 'body_text' => 'hi', 'status' => 'queued', 'attempts' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement("DELETE FROM email_log WHERE to_email LIKE '%@test.local'");
    }

    public function test_successful_send_marks_sent(): void
    {
        $id = $this->seed();
        $ok = new class implements Transport {
            public array $sent = [];
            public function send(EmailMessage $m): void { $this->sent[] = $m->recipient(); }
        };

        self::assertSame(1, $this->queue($ok)->process());

        $row = $this->db->selectOne('SELECT status, sent_at, attempts FROM email_log WHERE id = ?', [$id]);
        self::assertSame('sent', $row['status']);
        self::assertNotNull($row['sent_at']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame(['q@test.local'], $ok->sent);
    }

    public function test_transient_failure_schedules_a_retry(): void
    {
        $id = $this->seed();
        $failing = new class implements Transport {
            public function send(EmailMessage $m): void { throw new \RuntimeException('451 try later'); }
        };

        self::assertSame(0, $this->queue($failing)->process());

        $row = $this->db->selectOne('SELECT status, attempts, next_attempt_at, error FROM email_log WHERE id = ?', [$id]);
        self::assertSame('queued', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertNotNull($row['next_attempt_at']);
        self::assertStringContainsString('451', (string) $row['error']);

        // Not picked up again until next_attempt_at passes.
        self::assertSame(0, $this->queue($failing)->process());
        self::assertSame(1, (int) $this->db->selectValue('SELECT attempts FROM email_log WHERE id = ?', [$id]));
    }

    public function test_gives_up_after_max_attempts(): void
    {
        $id = $this->seed();
        $failing = new class implements Transport {
            public function send(EmailMessage $m): void { throw new \RuntimeException('550 nope'); }
        };
        $q = $this->queue($failing);

        for ($i = 0; $i < 3; $i++) {
            $q->process();
            $this->db->affectingStatement('UPDATE email_log SET next_attempt_at = NULL WHERE id = ?', [$id]);
        }

        self::assertSame('failed', $this->db->selectValue('SELECT status FROM email_log WHERE id = ?', [$id]));
        self::assertSame(3, (int) $this->db->selectValue('SELECT attempts FROM email_log WHERE id = ?', [$id]));
    }

    public function test_prune_removes_old_terminal_rows(): void
    {
        $id = $this->seed('old@test.local');
        $this->db->affectingStatement(
            "UPDATE email_log SET status = 'sent', created_at = (UTC_TIMESTAMP() - INTERVAL 40 DAY) WHERE id = ?",
            [$id],
        );
        self::assertGreaterThanOrEqual(1, $this->queue(new class implements Transport {
            public function send(EmailMessage $m): void {}
        })->prune(30));
    }
}
