<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Integrations\Turnstile;
use App\Support\HttpClient;
use PHPUnit\Framework\TestCase;

final class TurnstileTest extends TestCase
{
    private function http(array $response): HttpClient
    {
        return new class ($response) extends HttpClient {
            public array $calls = [];
            public function __construct(private array $stub)
            {
            }
            protected function send(string $method, string $url, ?string $body, array $headers): array
            {
                $this->calls[] = compact('method', 'url', 'body');
                return $this->stub;
            }
        };
    }

    private const CONFIGURED = [
        'site_key' => 'sk', 'secret_key' => 'secret',
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'optional_when_unconfigured' => true,
    ];

    public function test_passes_when_unconfigured_and_optional(): void
    {
        $t = new Turnstile(['site_key' => '', 'secret_key' => '', 'optional_when_unconfigured' => true], $this->http([]));
        self::assertTrue($t->verify(null));
        self::assertFalse($t->isConfigured());
    }

    public function test_fails_closed_when_unconfigured_but_required(): void
    {
        $t = new Turnstile(['site_key' => '', 'secret_key' => '', 'optional_when_unconfigured' => false], $this->http([]));
        self::assertFalse($t->verify('anything'));
    }

    public function test_configured_success(): void
    {
        $http = $this->http(['status' => 200, 'body' => '', 'json' => ['success' => true], 'ok' => true]);
        $t = new Turnstile(self::CONFIGURED, $http);
        self::assertTrue($t->verify('valid-token', '1.2.3.4'));
        self::assertStringContainsString('siteverify', $http->calls[0]['url']);
    }

    public function test_configured_failure(): void
    {
        $t = new Turnstile(self::CONFIGURED, $this->http(['status' => 200, 'json' => ['success' => false, 'error-codes' => ['invalid-input-response']], 'ok' => true, 'body' => '']));
        self::assertFalse($t->verify('bad-token'));
    }

    public function test_empty_or_oversized_token_rejected_without_a_call(): void
    {
        $http = $this->http(['json' => ['success' => true]]);
        $t = new Turnstile(self::CONFIGURED, $http);
        self::assertFalse($t->verify(''));
        self::assertFalse($t->verify(str_repeat('x', 3000)));
        self::assertSame([], $http->calls);
    }
}
