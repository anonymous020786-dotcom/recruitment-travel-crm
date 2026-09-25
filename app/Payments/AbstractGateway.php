<?php

declare(strict_types=1);

namespace App\Payments;

use App\Integrations\Credentials;
use App\Support\HttpClient;

/** Shared plumbing for the gateway adapters: credentials, HTTP, constant-time comparison. */
abstract class AbstractGateway implements Gateway
{
    public function __construct(
        protected readonly Credentials $credentials,
        protected readonly HttpClient $http,
        /** @var (callable():int)|null */
        protected $clock = null,
    ) {
    }

    /** Whether the gateway runs against the provider's sandbox (the `mode` field; services without one are always live). */
    protected function sandbox(): bool
    {
        return $this->credentials->get($this->key(), 'mode') === 'test';
    }

    /** @throws GatewayException when a required credential is missing */
    protected function need(string $field): string
    {
        $v = $this->credentials->get($this->key(), $field);
        if ($v === null || $v === '') {
            throw new GatewayException('The ' . $this->key() . ' credentials are incomplete (' . $field . ' is missing).');
        }

        return $v;
    }

    protected function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }

    protected static function same(string $a, string $b): bool
    {
        return $a !== '' && hash_equals($a, $b);
    }

    /** @return array<string,mixed> */
    protected static function decode(string $raw): array
    {
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /** Give up on a non-2xx answer with the provider's own message (never our credentials). */
    protected function fail(array $response, string $what): never
    {
        $message = (string) ($response['json']['error']['description'] ?? $response['json']['error']['message'] ?? $response['json']['message'] ?? '');
        throw new GatewayException(ucfirst($this->key()) . ' did not accept the request to ' . $what . ($response['status'] === 0 ? ' (could not be reached)' : ' (HTTP ' . $response['status'] . ($message !== '' ? ': ' . mb_substr($message, 0, 160) : '') . ')') . '.');
    }

    public function handleReturn(array $query, array $post): ?PaymentEvent
    {
        return null;   // most gateways only redirect the customer back; the webhook decides
    }
}
