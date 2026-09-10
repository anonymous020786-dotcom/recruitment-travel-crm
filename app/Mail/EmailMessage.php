<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * A ready-to-send message. Builds a MIME multipart/alternative body when both
 * HTML and plain text are present.
 */
final class EmailMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $html,
        public readonly ?string $text = null,
        public readonly string $fromAddress = 'no-reply@localhost',
        public readonly string $fromName = 'CRM',
        public readonly ?string $replyTo = null,
    ) {
    }

    public function textBody(): string
    {
        return $this->text ?? trim(preg_replace('/\s*\n\s*\n\s*/', "\n\n", strip_tags(
            str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $this->html),
        )) ?? '');
    }

    /** Full RFC 5322 message (headers + body) ready for SMTP DATA. */
    public function toMime(): string
    {
        $boundary = 'b_' . bin2hex(random_bytes(12));
        $date = gmdate('D, d M Y H:i:s') . ' +0000';
        $messageId = '<' . bin2hex(random_bytes(16)) . '@' . $this->hostFromAddress() . '>';

        $headers = [
            'Date: ' . $date,
            'Message-ID: ' . $messageId,
            'From: ' . $this->encodeHeaderName($this->fromName) . ' <' . $this->fromAddress . '>',
            'To: <' . $this->sanitizeAddress($this->to) . '>',
            'Subject: ' . $this->encodeSubject($this->subject),
            'MIME-Version: 1.0',
        ];
        if ($this->replyTo !== null) {
            $headers[] = 'Reply-To: <' . $this->sanitizeAddress($this->replyTo) . '>';
        }
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . $this->quotedPrintable($this->textBody()) . "\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . $this->quotedPrintable($this->html) . "\r\n\r\n"
            . "--{$boundary}--\r\n";

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    public function recipient(): string
    {
        return $this->sanitizeAddress($this->to);
    }

    public function sender(): string
    {
        return $this->sanitizeAddress($this->fromAddress);
    }

    // ---- internals -------------------------------------------------

    private function sanitizeAddress(string $address): string
    {
        // Strip CR/LF to prevent header injection.
        return trim(str_replace(["\r", "\n"], '', $address));
    }

    private function hostFromAddress(): string
    {
        $parts = explode('@', $this->fromAddress);

        return $parts[1] ?? 'localhost';
    }

    private function encodeHeaderName(string $name): string
    {
        return preg_match('/^[\x20-\x7E]*$/', $name) === 1
            ? '"' . str_replace('"', '', $name) . '"'
            : '=?UTF-8?B?' . base64_encode($name) . '?=';
    }

    private function encodeSubject(string $subject): string
    {
        $subject = str_replace(["\r", "\n"], ' ', $subject);

        return preg_match('/^[\x20-\x7E]*$/', $subject) === 1
            ? $subject
            : '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }

    private function quotedPrintable(string $text): string
    {
        return quoted_printable_encode(str_replace("\r\n", "\n", $text));
    }
}
