<?php

declare(strict_types=1);

use App\Controllers\HealthController;
use App\Controllers\Public\HomeController;
use App\Http\Router;

/**
 * Web routes. Middleware groups (security headers, HTTPS, session, auth, CSRF,
 * RBAC, branch scope) are attached in Step 1.4+. For now routes register with
 * the middleware names they will use so the wiring is visible.
 */
return static function (Router $router): void {

    // ---- Public site (indexable, cacheable) -----------------------------
    $router->group(['middleware' => ['web.public'], 'name' => 'public.'], static function (Router $r): void {
        $r->get('/', [HomeController::class, 'index'])->name('home');
    });

    // ---- Health / readiness -------------------------------------------
    $router->get('/health', [HealthController::class, 'index'])->name('health');
    $router->get('/health/db', [HealthController::class, 'db'])
        ->middleware(['auth', 'can:system.health'])
        ->name('health.db');

    // ---- Authenticated CRM (filled from Step 1.6 onward) ---------------
    $router->group(['middleware' => ['web.crm', 'auth'], 'name' => 'crm.'], static function (Router $r): void {
        $r->get('/dashboard', static fn () => \App\Http\Response::html(
            '<!doctype html><meta charset="utf-8"><title>Dashboard</title><h1>Dashboard</h1><p>Coming in Phase 10.</p>'
        ))->name('dashboard');
    });
};
