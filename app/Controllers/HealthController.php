<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Response;
use App\Support\Application;
use App\Support\Db;
use Throwable;

final class HealthController extends Controller
{
    public function __construct(private readonly Application $app)
    {
    }

    /** Liveness — no DB, safe to expose. */
    public function index(): Response
    {
        return Response::json([
            'status'  => 'ok',
            'app'     => $this->app->config()->get('app.name'),
            'env'     => $this->app->environment(),
            'version' => Application::VERSION,
            'time'    => gmdate('c'),
        ])->withHeader('Cache-Control', 'no-store');
    }

    /** Readiness — checks the database. Gated to admins by route middleware. */
    public function db(Db $db): Response
    {
        try {
            $one = $db->selectValue('SELECT 1');
            $migration = $db->selectValue(
                'SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1'
            );

            return Response::json([
                'status'    => $one === 1 || $one === '1' ? 'ok' : 'degraded',
                'migration' => $migration,
            ])->withHeader('Cache-Control', 'no-store');
        } catch (Throwable $e) {
            logger()->error('health/db failed', ['exception' => $e]);

            return Response::json(['status' => 'error'], 503)->withHeader('Cache-Control', 'no-store');
        }
    }
}
