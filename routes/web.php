<?php

declare(strict_types=1);

use App\Controllers\Auth\LoginController;
use App\Controllers\Auth\PasswordResetController;
use App\Controllers\Crm\LeadController;
use App\Controllers\HealthController;
use App\Controllers\Public\ContactController;
use App\Controllers\Public\PublicPageController;
use App\Controllers\Public\SeoController;
use App\Http\Router;

return static function (Router $router): void {

    // ---- Public site (indexable, cacheable, session-free) ---------------
    $router->group(['middleware' => ['web.public'], 'name' => 'public.'], static function (Router $r): void {
        $r->get('/', [PublicPageController::class, 'home'])->name('home');
        $r->get('/about', [PublicPageController::class, 'about'])->name('about');
        $r->get('/robots.txt', [SeoController::class, 'robots'])->name('robots');
        $r->get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
    });

    // ---- Public forms (session for flash/CSRF, not shared-cached) -------
    $router->group(['middleware' => ['web.public', 'session'], 'name' => 'public.'], static function (Router $r): void {
        $r->get('/contact', [PublicPageController::class, 'contact'])->name('contact');
        $r->post('/contact', [ContactController::class, 'submit'])
            ->middleware(['csrf', 'throttle:public_form'])->name('contact.submit');
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

        // ---- Leads --------------------------------------------------
        $r->get('/leads', [LeadController::class, 'index'])->middleware(['can:leads.view'])->name('leads.index');
        $r->get('/leads/create', [LeadController::class, 'create'])->middleware(['can:leads.create'])->name('leads.create');
        $r->post('/leads', [LeadController::class, 'store'])->middleware(['can:leads.create', 'throttle:write'])->name('leads.store');
        $r->post('/leads/bulk/assign', [LeadController::class, 'bulkAssign'])->middleware(['can:leads.assign'])->name('leads.bulk.assign');

        $r->get('/leads/{lead}', [LeadController::class, 'show'])->middleware(['can:leads.view'])->name('leads.show');
        $r->get('/leads/{lead}/edit', [LeadController::class, 'edit'])->middleware(['can:leads.edit'])->name('leads.edit');
        $r->put('/leads/{lead}', [LeadController::class, 'update'])->middleware(['can:leads.edit', 'throttle:write'])->name('leads.update');
        $r->delete('/leads/{lead}', [LeadController::class, 'destroy'])->middleware(['can:leads.delete'])->name('leads.destroy');

        $r->post('/leads/{lead}/assign', [LeadController::class, 'assign'])->middleware(['can:leads.assign'])->name('leads.assign');
        $r->post('/leads/{lead}/status', [LeadController::class, 'changeStatus'])->middleware(['can:leads.edit'])->name('leads.status');
        $r->post('/leads/{lead}/notes', [LeadController::class, 'addNote'])->middleware(['can:leads.edit'])->name('leads.notes');
    });
};
