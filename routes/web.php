<?php

declare(strict_types=1);

use App\Controllers\Auth\LoginController;
use App\Controllers\Auth\PasswordConfirmController;
use App\Controllers\Auth\PasswordResetController;
use App\Controllers\Auth\TwoFactorChallengeController;
use App\Controllers\Auth\WebAuthnLoginController;
use App\Controllers\Crm\AccountController;
use App\Controllers\Crm\DashboardController;
use App\Controllers\Crm\FollowupController;
use App\Controllers\Crm\LeadController;
use App\Controllers\Crm\PasskeyController;
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

        // Passwordless sign-in with a passkey (discoverable credential).
        $r->post('/login/passkey/options', [WebAuthnLoginController::class, 'options'])
            ->middleware(['throttle:login', 'json'])->name('login.passkey.options');
        $r->post('/login/passkey', [WebAuthnLoginController::class, 'verify'])
            ->middleware(['throttle:login', 'json'])->name('login.passkey');
    });

    // ---- Two-factor challenge (post-password, pre-session) ------------
    $router->group(['middleware' => ['web.crm']], static function (Router $r): void {
        $r->get('/two-factor', [TwoFactorChallengeController::class, 'show'])->name('2fa.challenge');
        $r->post('/two-factor', [TwoFactorChallengeController::class, 'verify'])
            ->middleware(['throttle:two_factor'])->name('2fa.verify');
        $r->post('/two-factor/email', [TwoFactorChallengeController::class, 'resendEmail'])
            ->middleware(['throttle:two_factor'])->name('2fa.email');
        $r->post('/two-factor/passkey/options', [TwoFactorChallengeController::class, 'passkeyOptions'])
            ->middleware(['throttle:two_factor', 'json'])->name('2fa.passkey.options');
        $r->post('/two-factor/passkey', [TwoFactorChallengeController::class, 'passkeyVerify'])
            ->middleware(['throttle:two_factor', 'json'])->name('2fa.passkey');
    });

    // ---- Authenticated CRM ------------------------------------------
    $router->group(['middleware' => ['web.crm', 'auth', 'branch', 'enforce2fa']], static function (Router $r): void {
        $r->post('/logout', [LoginController::class, 'destroy'])->name('logout');

        $r->get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // ---- Follow-ups (personal queue + close-out actions) --
        $r->get('/followups', [FollowupController::class, 'index'])
            ->middleware(['can:followups.view'])->name('followups.index');
        $r->post('/followups/{id}/complete', [FollowupController::class, 'complete'])
            ->middleware(['can:followups.complete', 'throttle:write'])->name('followups.complete');
        $r->post('/followups/{id}/cancel', [FollowupController::class, 'cancel'])
            ->middleware(['can:followups.edit', 'throttle:write'])->name('followups.cancel');

        // Step-up: re-enter the password before entering the secure area.
        $r->get('/confirm-password', [PasswordConfirmController::class, 'show'])->name('password.confirm');
        $r->post('/confirm-password', [PasswordConfirmController::class, 'store'])
            ->middleware(['throttle:password_confirm'])->name('password.confirm.store');

        // ---- Account -------------------------------------------
        $r->get('/account/profile', [AccountController::class, 'profile'])->name('account.profile');
        $r->put('/account/profile', [AccountController::class, 'updateProfile'])->middleware(['throttle:write'])->name('account.profile.update');
        $r->get('/account/security', [AccountController::class, 'security'])->middleware(['confirm'])->name('account.security');
        $r->post('/account/password', [AccountController::class, 'changePassword'])->middleware(['throttle:write'])->name('account.password');

        $r->get('/account/two-factor', [AccountController::class, 'twoFactorSetup'])->name('account.2fa.setup');
        $r->post('/account/two-factor', [AccountController::class, 'twoFactorConfirm'])->middleware(['throttle:two_factor'])->name('account.2fa.confirm');
        $r->post('/account/two-factor/disable', [AccountController::class, 'disableTwoFactor'])->middleware(['confirm'])->name('account.2fa.disable');
        $r->get('/account/recovery-codes', [AccountController::class, 'recoveryCodes'])->name('account.recovery');
        $r->post('/account/recovery-codes', [AccountController::class, 'regenerateRecoveryCodes'])->middleware(['confirm'])->name('account.recovery.regen');

        // ---- Passkeys (WebAuthn) ------------------------------
        $r->get('/account/passkeys', [PasskeyController::class, 'index'])->middleware(['confirm'])->name('account.passkeys');
        $r->post('/account/passkeys/options', [PasskeyController::class, 'options'])
            ->middleware(['throttle:two_factor', 'json', 'confirm'])->name('account.passkeys.options');
        $r->post('/account/passkeys', [PasskeyController::class, 'store'])
            ->middleware(['throttle:two_factor', 'json', 'confirm'])->name('account.passkeys.store');
        $r->post('/account/passkeys/{id}/rename', [PasskeyController::class, 'rename'])->middleware(['confirm'])->name('account.passkeys.rename');
        $r->post('/account/passkeys/{id}/delete', [PasskeyController::class, 'destroy'])->middleware(['confirm'])->name('account.passkeys.destroy');
        $r->post('/account/passkeys/second-factor', [PasskeyController::class, 'toggleSecondFactor'])->middleware(['confirm'])->name('account.passkeys.2fa');

        $r->post('/account/devices/revoke', [AccountController::class, 'revokeDevice'])->name('account.devices.revoke');
        $r->post('/account/sessions/revoke', [AccountController::class, 'revokeSession'])->name('account.sessions.revoke');
        $r->post('/account/sessions/revoke-all', [AccountController::class, 'signOutEverywhere'])->middleware(['confirm'])->name('account.sessions.revoke_all');

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
        $r->post('/leads/{lead}/followups', [LeadController::class, 'scheduleFollowup'])
            ->middleware(['can:followups.create', 'throttle:write'])->name('leads.followups.store');
    });
};
