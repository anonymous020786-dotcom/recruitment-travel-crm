<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Mail\EmailMessage;
use PHPUnit\Framework\TestCase;

final class EmailMessageTest extends TestCase
{
    public function test_mime_is_multipart_alternative_with_both_parts(): void
    {
        $mime = (new EmailMessage('a@b.com', 'Hi', '<p>Hello <b>world</b></p>', 'Hello world'))->toMime();

        self::assertStringContainsString('Content-Type: multipart/alternative; boundary="', $mime);
        self::assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $mime);
        self::assertStringContainsString('Content-Type: text/html; charset=UTF-8', $mime);
        self::assertStringContainsString('Subject: Hi', $mime);
        self::assertStringContainsString('MIME-Version: 1.0', $mime);
    }

    public function test_header_injection_is_stripped(): void
    {
        $m = new EmailMessage(
            "victim@x.com\r\nBcc: attacker@evil.com",
            "Subject\r\nX-Injected: yes",
            '<p>x</p>',
        );
        $mime = $m->toMime();
        // No CRLF-injected headers — the payload is neutralised into a single
        // (broken) address / subject line.
        self::assertStringNotContainsString("\r\nBcc:", $mime);
        self::assertStringNotContainsString("\r\nX-Injected:", $mime);
        self::assertStringNotContainsString("\nBcc:", $mime);
        self::assertSame('victim@x.comBcc: attacker@evil.com', $m->recipient());
    }

    public function test_text_body_derived_from_html_when_absent(): void
    {
        $m = new EmailMessage('a@b.com', 'S', '<p>Line one</p><p>Line two</p>');
        $text = $m->textBody();
        self::assertStringContainsString('Line one', $text);
        self::assertStringContainsString('Line two', $text);
        self::assertStringNotContainsString('<p>', $text);
    }

    public function test_non_ascii_subject_is_encoded(): void
    {
        $mime = (new EmailMessage('a@b.com', 'Café ☕', '<p>x</p>'))->toMime();
        self::assertStringContainsString('Subject: =?UTF-8?B?', $mime);
    }

    public function test_utf8_html_uses_quoted_printable(): void
    {
        $mime = (new EmailMessage('a@b.com', 'S', '<p>café — €5</p>'))->toMime();
        self::assertStringContainsString('Content-Transfer-Encoding: quoted-printable', $mime);
        self::assertStringNotContainsString('café — €5', $mime); // encoded, not raw
    }
}
