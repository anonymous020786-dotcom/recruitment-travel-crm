<?php

declare(strict_types=1);

use App\Controllers\Auth\LoginController;
use App\Controllers\Auth\PasswordResetController;
use App\Controllers\HealthController;
use App\Controllers\Public\HomeController;
use App\Http\Response;
use App\Http\Router;

return static function (Router $router): void {

    // ---- Public site (indexable, cacheable, session-free) ---------------
    $router->group(['middleware' => ['web.public'], 'name' => 'public.'], static function (Router $r): void {
        $r->get('/', [HomeController::class, 'index'])->name('home');
    });

    // ---- Health / readiness -------------------------------------------
    $router->get('/health', [HealthController::class, 'index'])->middleware(['headers:api'])->name('health');
    $router->get('/health/db', [HealthController::class, 'db'])
        ->middleware(['headers:api', 'auth', 'can:system.health'])
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
    $router->group(['middleware' => ['web.crm', 'auth']], static function (Router $r): void {
        $r->post('/logout', [LoginController::class, 'destroy'])->name('logout');

        $r->get('/dashboard', static fn () => Response::html(
            '<!doctype html><meta charset="utf-8"><title>Dashboard</title>'
            . '<h1>Dashboard</h1><p>Signed in. Full dashboard arrives in Phase 10.</p>'
            . '<form method="post" action="/logout">' . csrf_field() . '<button>Sign out</button></form>'
        ))->name('dashboard');
    });
};
