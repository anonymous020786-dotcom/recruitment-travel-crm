<?php

declare(strict_types=1);

namespace App\Integrations;

/**
 * Provides third-party integration data + rendered snippets for the PUBLIC site.
 * Never used on authenticated CRM pages.
 */
final class IntegrationsService
{
    /** @param array<string,mixed> $config config('integrations') */
    public function __construct(private readonly array $config)
    {
    }

    // ---- WhatsApp ----------------------------------------------------

    public function whatsappNumber(): string
    {
        return (string) ($this->config['whatsapp']['number'] ?? '');
    }

    public function whatsappEnabled(): bool
    {
        return $this->whatsappNumber() !== '' && (bool) ($this->config['whatsapp']['button_enabled'] ?? true);
    }

    public function whatsappLink(?string $text = null): string
    {
        $text ??= (string) ($this->config['whatsapp']['default_text'] ?? '');

        return 'https://wa.me/' . $this->whatsappNumber()
            . ($text !== '' ? '?text=' . rawurlencode($text) : '');
    }

    // ---- Analytics -------------------------------------------------

    public function ga4Id(): string
    {
        return (string) ($this->config['analytics']['ga4_id'] ?? '');
    }

    public function analyticsEnabled(): bool
    {
        return $this->ga4Id() !== '';
    }

    public function analyticsRequiresConsent(): bool
    {
        return (bool) ($this->config['analytics']['require_consent'] ?? true);
    }

    // ---- Tawk ---------------------------------------------------

    public function tawkEnabled(): bool
    {
        return ($this->config['tawk']['property_id'] ?? '') !== '';
    }

    public function tawkSrc(): string
    {
        return 'https://embed.tawk.to/'
            . rawurlencode((string) ($this->config['tawk']['property_id'] ?? ''))
            . '/' . rawurlencode((string) ($this->config['tawk']['widget_id'] ?? 'default'));
    }

    // ---- CSP additions for the public group ----------------------

    /**
     * Extra CSP source lists to merge into the public policy for whichever
     * integrations are enabled.
     *
     * @return array<string,list<string>>  directive => sources
     */
    public function publicCspAdditions(): array
    {
        $additions = [];
        $map = (array) ($this->config['csp'] ?? []);

        $enabled = array_filter([
            'analytics' => $this->analyticsEnabled(),
            'turnstile' => ($this->config['turnstile']['site_key'] ?? '') !== '',
            'tawk'      => $this->tawkEnabled(),
        ]);

        foreach (array_keys($enabled) as $key) {
            foreach ((array) ($map[$key] ?? []) as $directive => $sources) {
                $additions[$directive] = array_values(array_unique(
                    array_merge($additions[$directive] ?? [], (array) $sources),
                ));
            }
        }

        return $additions;
    }
}
