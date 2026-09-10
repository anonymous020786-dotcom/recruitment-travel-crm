<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Support\Application;
use Closure;

/**
 * Emits the security response headers for a request profile.
 *
 *   security-headers:crm     private app pages — strict CSP (nonce, no inline
 *                            script), frame-ancestors 'none', X-Robots noindex,
 *                            Cache-Control private/no-store
 *   security-headers:public  marketing site — relaxed img/style for OG assets,
 *                            frame-ancestors 'self', indexable, public cache
 *   security-headers:api     JSON endpoints — strict CSP, noindex, no-store
 *
 * A per-request CSP nonce is generated and exposed as the `csp_nonce` request
 * attribute so the view layer can attach it to its own <script>/<style> tags.
 */
final class SecurityHeaders implements Middleware
{
    private string $profile;

    /** @param list<string> $args */
    public function __construct(
        private readonly Application $app,
        array $args = [],
    ) {
        $this->profile = $args[0] ?? 'crm';
    }

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $request->setAttribute('csp_nonce', $nonce);
        if ($this->app->bound(\App\View\View::class)) {
            $this->app->get(\App\View\View::class)->share('cspNonce', $nonce);
        }

        $response = $next($request);

        $cfg = $this->app->config();
        $headers = (array) $cfg->get('security.headers', []);

        $out = [
            'X-Content-Type-Options' => $headers['x_content_type_options'] ?? 'nosniff',
            'Referrer-Policy'        => $headers['referrer_policy'] ?? 'strict-origin-when-cross-origin',
            'Permissions-Policy'     => $headers['permissions_policy'] ?? 'geolocation=(), microphone=(), camera=()',
            'Cross-Origin-Opener-Policy'   => $headers['cross_origin_opener_policy'] ?? 'same-origin',
        ];

        // HSTS only over HTTPS and only in production.
        $hsts = (array) ($headers['hsts'] ?? []);
        if (($hsts['enabled'] ?? false) && $request->isSecure() && $this->app->isProduction()) {
            $value = 'max-age=' . (int) ($hsts['max_age'] ?? 31536000);
            if ($hsts['include_subdomains'] ?? false) {
                $value .= '; includeSubDomains';
            }
            if ($hsts['preload'] ?? false) {
                $value .= '; preload';
            }
            $out['Strict-Transport-Security'] = $value;
        }

        // Profile-specific.
        if ($this->profile === 'public') {
            $out['X-Frame-Options'] = 'SAMEORIGIN';
            $out['Content-Security-Policy'] = $this->csp('public', $nonce);
            if ($response->getHeader('Cache-Control') === null) {
                $ttl = (int) $cfg->get('seo.public_cache_seconds', 300);
                $out['Cache-Control'] = "public, max-age={$ttl}";
            }
        } else {
            $out['X-Frame-Options'] = 'DENY';
            $out['X-Robots-Tag'] = (string) $cfg->get('seo.crm_robots', 'noindex, nofollow');
            $out['Content-Security-Policy'] = $this->csp('crm', $nonce);
            $out['Cross-Origin-Resource-Policy'] = $headers['cross_origin_resource_policy'] ?? 'same-origin';
            $out['Cache-Control'] = 'private, no-store, max-age=0';
            $out['Pragma'] = 'no-cache';
        }

        foreach ($out as $name => $value) {
            if ($value !== null && $value !== '') {
                $response->withHeader($name, (string) $value);
            }
        }

        return $response;
    }

    private function csp(string $key, string $nonce): string
    {
        $directives = (array) $this->app->config()->get("security.csp.{$key}", []);

        // Merge enabled public-site integration hosts (GA / Turnstile / Tawk).
        if ($key === 'public' && $this->app->bound(\App\Integrations\IntegrationsService::class)) {
            foreach ($this->app->get(\App\Integrations\IntegrationsService::class)->publicCspAdditions() as $directive => $sources) {
                $directives[$directive] = array_values(array_unique(
                    array_merge((array) ($directives[$directive] ?? []), $sources),
                ));
            }
        }

        $parts = [];
        foreach ($directives as $directive => $sources) {
            $rendered = array_map(
                static fn (string $s) => str_replace('{nonce}', $nonce, $s),
                (array) $sources,
            );
            $parts[] = $directive . ' ' . implode(' ', $rendered);
        }

        return implode('; ', $parts);
    }
}
