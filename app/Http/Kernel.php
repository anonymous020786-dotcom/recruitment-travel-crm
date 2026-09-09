<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Middleware\EnforceHttps;
use App\Http\Middleware\ForceJson;
use App\Http\Middleware\MaintenanceGuard;
use App\Http\Middleware\Passthrough;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\SecurityHeaders;

/**
 * HTTP kernel configuration: global middleware (every request), named groups
 * (attached by route group), and aliases (referenced per route, optionally with
 * `alias:arg1,arg2`).
 *
 * Entries are class names resolved by the Pipeline via the container. As each
 * Phase 1 step lands its real middleware, swap the Passthrough placeholder for
 * the concrete class here — routes do not change.
 */
final class Kernel
{
    /**
     * Runs on every request, outermost first.
     *
     * @var list<string>
     */
    public array $global = [
        RequestId::class,
        EnforceHttps::class,
        MaintenanceGuard::class,
    ];

    /**
     * Route-group bundles.
     *
     * @var array<string,list<string>>
     */
    public array $groups = [
        'web.public' => [
            SecurityHeaders::class . ':public',
        ],
        'web.crm' => [
            SecurityHeaders::class . ':crm',
            // StartSession, VerifyCsrf  (Step 1.5)
        ],
        'api' => [
            SecurityHeaders::class . ':api',
            ForceJson::class,
            // StartSession, VerifyCsrf  (Step 1.5)
        ],
    ];

    /**
     * Per-route aliases.
     *
     * @var array<string,string>
     */
    public array $aliases = [
        'headers'  => SecurityHeaders::class, // headers:crm|public|api
        'auth'     => Passthrough::class,   // Authenticate        (Step 1.6)
        'guest'    => Passthrough::class,   // RedirectIfAuthenticated (Step 1.6)
        'can'      => Passthrough::class,   // Authorize:<permission> (Step 1.7)
        'branch'   => Passthrough::class,   // BindBranchScope     (Step 1.7)
        'throttle' => Passthrough::class,   // RateLimit:<bucket>  (Step 1.6)
        'verified' => Passthrough::class,
    ];

    /**
     * Expand a route's declared middleware (groups + aliases + raw class names,
     * each possibly `name:args`) into an ordered, de-duplicated list the
     * Pipeline can resolve.
     *
     * @param list<string> $names
     * @return list<string>
     */
    public function expand(array $names): array
    {
        $out = $this->global;

        foreach ($names as $name) {
            [$base, $suffix] = array_pad(explode(':', $name, 2), 2, null);

            if (isset($this->groups[$base])) {
                foreach ($this->groups[$base] as $groupEntry) {
                    $out[] = $groupEntry;
                }
                continue;
            }

            if (isset($this->aliases[$base])) {
                $class = $this->aliases[$base];
                $out[] = $suffix !== null ? "{$class}:{$suffix}" : $class;
                continue;
            }

            // Raw class name (with optional :args).
            $out[] = $name;
        }

        return array_values(array_unique($out));
    }
}
