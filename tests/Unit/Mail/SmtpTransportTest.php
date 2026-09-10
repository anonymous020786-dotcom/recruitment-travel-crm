<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Mail\EmailMessage;
use App\Mail\Transport\SmtpTransport;
use PHPUnit\Framework\TestCase;

/**
 * Drives SmtpTransport through a scripted server conversation by overriding the
 * socket I/O — no real network.
 */
final class SmtpTransportTest extends TestCase
{
    private function transport(array $serverLines, array $config = []): object
    {
        return new class ($serverLines, $config) extends SmtpTransport {
            public array $written = [];
            /** @var list<string> */
            private array $script;

            public function __construct(array $script, array $config)
            {
                parent::__construct($config + [
                    'host' => 'smtp.test', 'port' => 587, 'encryption' => 'none',
                    'username' => 'u@test', 'password' => 'pw', 'timeout' => 5,
                ], 'test.local');
                $this->script = $script;
            }

            protected function connect(): void
            {
                $this->expect([220]);
            }
            protected function enableCrypto(): void
            {
            }
            protected function write(string $data): void
            {
                $this->written[] = $data;
            }
            protected function readResponse(): string
            {
                return array_shift($this->script) ?? '451 script exhausted';
            }
        };
    }

    private function message(): EmailMessage
    {
        return new EmailMessage('rcpt@x.com', 'Test', '<p>hi</p>', 'hi', 'from@test.local', 'CRM');
    }

    public function test_full_happy_path(): void
    {
        $t = $this->transport([
            "220 smtp.test ESMTP\r\n",
            "250-smtp.test\r\n250 AUTH LOGIN\r\n", // EHLO
            "334 VXNlcm5hbWU6\r\n",                 // AUTH LOGIN
            "334 UGFzc3dvcmQ6\r\n",                 // username
            "235 2.7.0 Authenticated\r\n",          // password
            "250 OK\r\n",                           // MAIL FROM
            "250 OK\r\n",                           // RCPT TO
            "354 End data with <CR><LF>.<CR><LF>\r\n", // DATA
            "250 2.0.0 Ok: queued\r\n",             // body .
            "221 Bye\r\n",                          // QUIT
        ]);

        $t->send($this->message());

        $sent = implode("\n", $t->written);
        self::assertStringContainsString('EHLO test.local', $sent);
        self::assertStringContainsString('AUTH LOGIN', $sent);
        self::assertStringContainsString('MAIL FROM:<from@test.local>', $sent);
        self::assertStringContainsString('RCPT TO:<rcpt@x.com>', $sent);
        self::assertStringContainsString('DATA', $sent);
        self::assertStringContainsString('QUIT', $sent);
    }

    public function test_rejected_recipient_raises(): void
    {
        $t = $this->transport([
            "220 ok\r\n",
            "250 ok\r\n",   // EHLO
            "334 x\r\n", "334 y\r\n", "235 ok\r\n", // AUTH
            "250 ok\r\n",   // MAIL FROM
            "550 No such user\r\n", // RCPT TO
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/550/');
        $t->send($this->message());
    }

    public function test_auth_failure_raises(): void
    {
        $t = $this->transport([
            "220 ok\r\n", "250 ok\r\n",
            "334 x\r\n", "334 y\r\n", "535 Auth failed\r\n",
        ]);

        $this->expectException(\RuntimeException::class);
        $t->send($this->message());
    }
}
