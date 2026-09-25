<?php

declare(strict_types=1);

namespace App\Payments;

use App\Integrations\Credentials;
use App\Support\HttpClient;

/**
 * The gateways the application knows, and which of them can be used right now: switched on and fully configured in Admin →
 * Integrations, and able to collect the invoice's currency.
 */
final class GatewayRegistry
{
    /** @var array<string,class-string<Gateway>> */
    public const ADAPTERS = [
        'razorpay' => Gateways\Razorpay::class,
        'payu' => Gateways\PayU::class,
        'cashfree' => Gateways\Cashfree::class,
        'phonepe' => Gateways\PhonePe::class,
        'stripe' => Gateways\Stripe::class,
    ];

    /** @var array<string,Gateway> */
    private array $built = [];

    public function __construct(private readonly Credentials $credentials, private readonly HttpClient $http)
    {
    }

    /** The adapter for a key, only when it is switched on and configured; null otherwise (or for an unknown key). */
    public function usable(string $key): ?Gateway
    {
        if (!isset(self::ADAPTERS[$key]) || !$this->credentials->isEnabled($key) || !$this->credentials->isConfigured($key)) {
            return null;
        }

        return $this->built[$key] ??= new (self::ADAPTERS[$key])($this->credentials, $this->http);
    }

    /**
     * Gateways that can collect this currency now.
     *
     * @return array<string,string> key => label
     */
    public function forCurrency(string $currency): array
    {
        $out = [];
        foreach (array_keys(self::ADAPTERS) as $key) {
            $g = $this->usable($key);
            if ($g !== null && in_array(strtoupper($currency), $g->currencies(), true)) {
                $out[$key] = (string) $this->credentials->service($key)['label'];
            }
        }

        return $out;
    }

    public static function knows(string $key): bool
    {
        return isset(self::ADAPTERS[$key]);
    }
}
