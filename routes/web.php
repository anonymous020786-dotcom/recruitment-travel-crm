<?php

declare(strict_types=1);

use App\Controllers\Auth\LoginController;
use App\Controllers\Auth\PasswordResetController;
use App\Controllers\HealthController;
use App\Controllers\Public\HomeController;
use App\Controllers\Public\SeoController;
use App\Http\Router;

return static function (Router $router): void {

    // ---- Public site (indexable, cacheable, session-free) ---------------
    $router->group(['middleware' => ['web.public'], 'name' => 'public.'], static function (Router $r): void {
        $r->get('/', [HomeController::class, 'index'])->name('home');
        $r->get('/robots.txt', [SeoController::class, 'robots'])->name('robots');
        $r->get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
    });

    // ---- Health / readiness -------------------------------------------
    $router->get('/health', [HealthController::class, 'index'])->middleware(['headers:api'])->name('health');
    $router->get('/health/db', [HealthController::class, 'db'])
        ->middleware(['headers:api', 'json', 'session', 'auth', 'can:system.health'])
        ->name('health.db');

    // ---- Guest auth (login / password reset) --------------------------
    $router->group(['middleware' => ['web.crm', 'guest']], static function (Router $r): void {
        $r->get('/login', [LoginController::class, 'show'])->name('login');
        $r->post('/login', [LoginController::class, 'store'])->middleware(['throttle:login'])->name('login.attempt');

        $r->get('/forgot-password', [PasswordResetController::class, 'showRequestForm'])->name('password.request');
        $r->post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])
            ->middleware(['throttle:password_reset'])->name('password.email');

        $r->get('/reset-password/{token}', [PasswordResetController::class, 'showResetForm'])->name('password.reset');
        $r->post('/reset-password', [PasswordResetController::class, 'reset'])
            ->middleware(['throttle:password_reset'])->name('password.update');
    });

    // ---- Authenticated CRM ------------------------------------------
    $router->group(['middleware' => ['web.crm', 'auth', 'branch']], static function (Router $r): void {
        $r->post('/logout', [LoginController::class, 'destroy'])->name('logout');

        $r->get('/dashboard', static fn () => view_response('crm.dashboard'))->name('dashboard');
    });
};
