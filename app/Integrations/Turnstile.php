<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Support\HttpClient;
use App\Support\Logger;

/**
 * Cloudflare Turnstile — human verification for public forms.
 *
 * The client widget produces a token in the `cf-turnstile-response` field; this
 * class verifies it server-side with the secret key. The client token is NEVER
 * trusted on its own.
 */
final class Turnstile
{
    /** @param array<string,mixed> $config config('integrations.turnstile') */
    public function __construct(
        private readonly array $config,
        private readonly HttpClient $http,
        private readonly ?Logger $logger = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return ($this->config['site_key'] ?? '') !== '' && ($this->config['secret_key'] ?? '') !== '';
    }

    public function siteKey(): string
    {
        return (string) ($this->config['site_key'] ?? '');
    }

    /**
     * Verify a token. Returns true when the challenge passed.
     *
     * When Turnstile is not configured and `optional_when_unconfigured` is on
     * (dev/local), verification is skipped and returns true.
     */
    public function verify(?string $token, ?string $remoteIp = null): bool
    {
        if (!$this->isConfigured()) {
            return (bool) ($this->config['optional_when_unconfigured'] ?? true);
        }

        if ($token === null || $token === '' || strlen($token) > 2048) {
            return false;
        }

        $result = $this->http->postForm((string) $this->config['verify_url'], array_filter([
            'secret'   => (string) $this->config['secret_key'],
            'response' => $token,
            'remoteip' => $remoteIp,
        ], static fn ($v) => $v !== null && $v !== ''));

        $success = ($result['json']['success'] ?? false) === true;

        if (!$success) {
            $this->logger?->info('turnstile verification failed', [
                'codes' => $result['json']['error-codes'] ?? null,
                'http'  => $result['status'],
            ]);
        }

        return $success;
    }
}
