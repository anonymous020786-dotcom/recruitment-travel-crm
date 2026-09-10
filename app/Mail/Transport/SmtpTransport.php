<?php

declare(strict_types=1);

namespace App\Mail\Transport;

use App\Mail\EmailMessage;
use RuntimeException;

/**
 * Raw SMTP over a stream socket — no external library. Supports implicit TLS
 * (ssl://, port 465) and STARTTLS (tls, port 587), AUTH LOGIN / PLAIN.
 *
 * The low-level I/O methods are protected so a test can subclass and feed a
 * scripted conversation.
 */
class SmtpTransport implements Transport
{
    /** @var resource|null */
    private $socket = null;

    /**
     * @param array{host:string,port:int,encryption:string,username:string,password:string,timeout:int} $config
     */
    public function __construct(
        private readonly array $config,
        private readonly string $ehloDomain = 'localhost',
    ) {
    }

    public function send(EmailMessage $message): void
    {
        try {
            $this->connect();
            $this->ehlo();

            if (strtolower($this->config['encryption'] ?? '') === 'tls') {
                $this->command('STARTTLS', [220]);
                $this->enableCrypto();
                $this->ehlo();
            }

            if (($this->config['username'] ?? '') !== '') {
                $this->authenticate();
            }

            $this->command('MAIL FROM:<' . $message->sender() . '>', [250]);
            $this->command('RCPT TO:<' . $message->recipient() . '>', [250, 251]);
            $this->command('DATA', [354]);

            $data = $message->toMime();
            // Dot-stuffing per RFC 5321.
            $data = preg_replace('/^\./m', '..', $data);
            $this->write($data . "\r\n.");
            $this->expect([250]);

            $this->command('QUIT', [221], false);
        } catch (RuntimeException $e) {
            $this->close();
            throw $e;
        }

        $this->close();
    }

    // ---- SMTP steps ----------------------------------------------

    protected function connect(): void
    {
        $encryption = strtolower($this->config['encryption'] ?? '');
        $scheme = $encryption === 'ssl' ? 'ssl' : 'tcp';
        $host = $this->config['host'] ?? 'localhost';
        $port = (int) ($this->config['port'] ?? 25);
        $timeout = (int) ($this->config['timeout'] ?? 15);

        $context = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            "{$scheme}://{$host}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            throw new RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, $timeout);
        $this->socket = $socket;
        $this->expect([220]);
    }

    protected function enableCrypto(): void
    {
        if ($this->socket === null || !stream_socket_enable_crypto(
            $this->socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        )) {
            throw new RuntimeException('STARTTLS negotiation failed.');
        }
    }

    protected function ehlo(): void
    {
        $this->command('EHLO ' . $this->ehloDomain, [250]);
    }

    protected function authenticate(): void
    {
        $user = (string) $this->config['username'];
        $pass = (string) $this->config['password'];

        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode($user), [334]);
        $this->command(base64_encode($pass), [235]);
    }

    // ---- I/O (overridable in tests) --------------------------

    /** @param list<int> $expectedCodes */
    protected function command(string $line, array $expectedCodes, bool $expectReply = true): string
    {
        $this->write($line);

        return $expectReply ? $this->expect($expectedCodes) : '';
    }

    protected function write(string $data): void
    {
        if ($this->socket === null) {
            throw new RuntimeException('SMTP socket not open.');
        }
        if (@fwrite($this->socket, $data . "\r\n") === false) {
            throw new RuntimeException('SMTP write failed.');
        }
    }

    /** @param list<int> $expectedCodes */
    protected function expect(array $expectedCodes): string
    {
        $response = $this->readResponse();
        $code = (int) substr($response, 0, 3);

        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException('Unexpected SMTP reply: ' . trim($response));
        }

        return $response;
    }

    protected function readResponse(): string
    {
        if ($this->socket === null) {
            throw new RuntimeException('SMTP socket not open.');
        }

        $lines = '';
        do {
            $line = fgets($this->socket, 4096);
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                throw new RuntimeException($meta['timed_out'] ? 'SMTP read timed out.' : 'SMTP connection closed.');
            }
            $lines .= $line;
        } while (isset($line[3]) && $line[3] === '-'); // multi-line: "250-..." continues

        return $lines;
    }

    protected function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }
}
