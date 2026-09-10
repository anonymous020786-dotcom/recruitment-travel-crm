<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Integrations\IntegrationsService;
use PHPUnit\Framework\TestCase;

final class IntegrationsServiceTest extends TestCase
{
    private function svc(array $overrides = []): IntegrationsService
    {
        $base = require dirname(__DIR__, 3) . '/config/integrations.php';

        return new IntegrationsService(array_replace_recursive($base, $overrides));
    }

    public function test_whatsapp_disabled_when_no_number(): void
    {
        self::assertFalse($this->svc()->whatsappEnabled());
    }

    public function test_whatsapp_link_built_from_number_and_text(): void
    {
        $svc = $this->svc(['whatsapp' => ['number' => '919812345678', 'button_enabled' => true]]);
        self::assertTrue($svc->whatsappEnabled());
        self::assertStringStartsWith('https://wa.me/919812345678?text=', $svc->whatsappLink('Hello there'));
        self::assertStringContainsString('Hello%20there', $svc->whatsappLink('Hello there'));
    }

    public function test_analytics_and_tawk_toggle_on_config(): void
    {
        self::assertFalse($this->svc()->analyticsEnabled());
        self::assertFalse($this->svc()->tawkEnabled());

        $svc = $this->svc([
            'analytics' => ['ga4_id' => 'G-ABC123'],
            'tawk' => ['property_id' => 'abc', 'widget_id' => '1a'],
        ]);
        self::assertTrue($svc->analyticsEnabled());
        self::assertSame('G-ABC123', $svc->ga4Id());
        self::assertSame('https://embed.tawk.to/abc/1a', $svc->tawkSrc());
    }

    public function test_csp_additions_only_for_enabled_integrations(): void
    {
        self::assertSame([], $this->svc()->publicCspAdditions());

        $svc = $this->svc([
            'analytics' => ['ga4_id' => 'G-X'],
            'turnstile' => ['site_key' => 'sk'],
        ]);
        $csp = $svc->publicCspAdditions();
        self::assertContains('https://www.googletagmanager.com', $csp['script-src']);
        self::assertContains('https://challenges.cloudflare.com', $csp['script-src']);
        self::assertContains('https://www.google-analytics.com', $csp['connect-src']);
    }
}
