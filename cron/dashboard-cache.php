<?php

declare(strict_types=1);

/**
 * Dashboard cache warmer (every 10 min): rebuilds the dashboard snapshot for every distinct
 * (branch scope, widget set) among the active users, so the first person to open the dashboard after a
 * quiet spell is served from cache. Does nothing when `app.dashboard_cache_seconds` is 0. Note: a snapshot
 * only stays warm for that many seconds, so on a busy install set DASHBOARD_CACHE_SECONDS to about 600.
 */

use App\Auth\BranchScopeResolver;
use App\Repositories\UserRepository;
use App\Services\DashboardService;
use App\Support\Application;
use App\Support\CronRunner;
use App\Support\Db;

/** @var Application $app */
$app = require __DIR__ . '/_bootstrap.php';

return CronRunner::finish($app->get(CronRunner::class)->run('dashboard-cache', 200, function (callable $progress) use ($app): int {
    $users = $app->get(UserRepository::class);
    $scopes = $app->get(BranchScopeResolver::class);

    $ids = array_column($app->get(Db::class)->select('SELECT id FROM users WHERE is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 500'), 'id');
    $viewers = (static function () use ($ids, $users, $scopes): \Generator {
        foreach ($ids as $id) {
            $u = $users->findById((int) $id);
            if ($u !== null) {
                yield [$u, $scopes->resolve($u)];
            }
        }
    })();

    $built = $app->get(DashboardService::class)->warm($viewers);
    $progress($built);

    return $built;
}));
