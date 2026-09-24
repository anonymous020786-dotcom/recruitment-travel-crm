<?php

declare(strict_types=1);

use App\Controllers\Auth\LoginController;
use App\Controllers\Auth\PasswordConfirmController;
use App\Controllers\Auth\PasswordResetController;
use App\Controllers\Auth\TwoFactorChallengeController;
use App\Controllers\Auth\WebAuthnLoginController;
use App\Controllers\Crm\AccountController;
use App\Controllers\Crm\ApplicationController;
use App\Controllers\Crm\CandidateController;
use App\Controllers\Crm\DashboardController;
use App\Controllers\Crm\DocumentController;
use App\Controllers\Crm\EmployerController;
use App\Controllers\Crm\JobController;
use App\Controllers\Crm\MatchController;
use App\Controllers\Crm\FollowupController;
use App\Controllers\Crm\InterviewController;
use App\Controllers\Crm\InvoiceController;
use App\Controllers\Crm\LeadController;
use App\Controllers\Crm\LeadExportController;
use App\Controllers\Crm\MedicalController;
use App\Controllers\Crm\PaymentController;
use App\Controllers\Crm\TourBookingController;
use App\Controllers\Crm\TourPackageController;
use App\Controllers\Crm\TravelController;
use App\Controllers\Crm\VisaController;
use App\Controllers\Crm\LeadImportController;
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

        // Import/export — literal paths, must be declared before /leads/{lead}
        // (a two-segment wildcard route) or "import"/"export" would be parsed
        // as a lead's public id instead.
        $r->get('/leads/import', [LeadImportController::class, 'create'])
            ->middleware(['can:leads.import', 'can:imports.run'])->name('leads.import.create');
        $r->post('/leads/import', [LeadImportController::class, 'store'])
            ->middleware(['can:leads.import', 'can:imports.run', 'throttle:import'])->name('leads.import.store');
        $r->get('/leads/import/{batch}', [LeadImportController::class, 'preview'])
            ->middleware(['can:leads.import', 'can:imports.run'])->name('leads.import.preview');
        $r->post('/leads/import/{batch}/confirm', [LeadImportController::class, 'confirm'])
            ->middleware(['can:leads.import', 'can:imports.run', 'throttle:write'])->name('leads.import.confirm');
        $r->get('/leads/import/{batch}/report', [LeadImportController::class, 'report'])
            ->middleware(['can:leads.import', 'can:imports.run'])->name('leads.import.report');
        $r->get('/leads/import/{batch}/report/download', [LeadImportController::class, 'downloadReport'])
            ->middleware(['can:leads.import', 'can:imports.run'])->name('leads.import.report.download');

        $r->post('/leads/export', [LeadExportController::class, 'store'])
            ->middleware(['can:leads.export', 'can:exports.run', 'throttle:export'])->name('leads.export.store');

        $r->get('/leads/{lead}', [LeadController::class, 'show'])->middleware(['can:leads.view'])->name('leads.show');
        $r->get('/leads/{lead}/edit', [LeadController::class, 'edit'])->middleware(['can:leads.edit'])->name('leads.edit');
        $r->get('/leads/{lead}/merge', [LeadController::class, 'mergeForm'])->middleware(['can:leads.merge'])->name('leads.merge');
        $r->post('/leads/{lead}/merge', [LeadController::class, 'merge'])->middleware(['can:leads.merge', 'throttle:write'])->name('leads.merge.do');
        $r->put('/leads/{lead}', [LeadController::class, 'update'])->middleware(['can:leads.edit', 'throttle:write'])->name('leads.update');
        $r->delete('/leads/{lead}', [LeadController::class, 'destroy'])->middleware(['can:leads.delete'])->name('leads.destroy');

        $r->post('/leads/{lead}/assign', [LeadController::class, 'assign'])->middleware(['can:leads.assign'])->name('leads.assign');
        $r->post('/leads/{lead}/status', [LeadController::class, 'changeStatus'])->middleware(['can:leads.edit'])->name('leads.status');
        $r->post('/leads/{lead}/notes', [LeadController::class, 'addNote'])->middleware(['can:leads.edit'])->name('leads.notes');
        $r->post('/leads/{lead}/communications', [LeadController::class, 'logCommunication'])
            ->middleware(['can:communication.log', 'throttle:write'])->name('leads.communications.store');
        $r->post('/leads/{lead}/followups', [LeadController::class, 'scheduleFollowup'])
            ->middleware(['can:followups.create', 'throttle:write'])->name('leads.followups.store');
        $r->post('/leads/{lead}/convert', [LeadController::class, 'convert'])
            ->middleware(['can:leads.convert', 'throttle:write'])->name('leads.convert');

        // ---- My exports (any queued report, not just leads) ---------
        $r->get('/exports', [LeadExportController::class, 'index'])->middleware(['can:exports.run'])->name('exports.index');
        $r->get('/exports/{job}/download', [LeadExportController::class, 'download'])->middleware(['can:exports.run'])->name('exports.download');

        // ---- Candidates (created only via lead conversion for now) ---
        $r->get('/candidates', [CandidateController::class, 'index'])->middleware(['can:candidates.view'])->name('candidates.index');
        $r->get('/candidates/{candidate}', [CandidateController::class, 'show'])->middleware(['can:candidates.view'])->name('candidates.show');
        $r->get('/candidates/{candidate}/edit', [CandidateController::class, 'edit'])->middleware(['can:candidates.edit'])->name('candidates.edit');
        $r->put('/candidates/{candidate}', [CandidateController::class, 'update'])->middleware(['can:candidates.edit', 'throttle:write'])->name('candidates.update');
        $r->post('/candidates/{candidate}/counselor', [CandidateController::class, 'reassignCounselor'])
            ->middleware(['can:candidates.edit', 'throttle:write'])->name('candidates.counselor');

        $r->post('/candidates/{candidate}/education', [CandidateController::class, 'storeEducation'])
            ->middleware(['can:candidates.education.manage', 'throttle:write'])->name('candidates.education.store');
        $r->put('/candidates/{candidate}/education/{education}', [CandidateController::class, 'updateEducation'])
            ->middleware(['can:candidates.education.manage', 'throttle:write'])->name('candidates.education.update');
        $r->delete('/candidates/{candidate}/education/{education}', [CandidateController::class, 'destroyEducation'])
            ->middleware(['can:candidates.education.manage', 'throttle:write'])->name('candidates.education.destroy');

        $r->post('/candidates/{candidate}/experience', [CandidateController::class, 'storeExperience'])
            ->middleware(['can:candidates.experience.manage', 'throttle:write'])->name('candidates.experience.store');
        $r->put('/candidates/{candidate}/experience/{experience}', [CandidateController::class, 'updateExperience'])
            ->middleware(['can:candidates.experience.manage', 'throttle:write'])->name('candidates.experience.update');
        $r->delete('/candidates/{candidate}/experience/{experience}', [CandidateController::class, 'destroyExperience'])
            ->middleware(['can:candidates.experience.manage', 'throttle:write'])->name('candidates.experience.destroy');

        $r->post('/candidates/{candidate}/skills', [CandidateController::class, 'storeSkill'])
            ->middleware(['can:candidates.skills.manage', 'throttle:write'])->name('candidates.skills.store');
        $r->delete('/candidates/{candidate}/skills/{skill}', [CandidateController::class, 'destroySkill'])
            ->middleware(['can:candidates.skills.manage', 'throttle:write'])->name('candidates.skills.destroy');

        $r->put('/candidates/{candidate}/preferences', [CandidateController::class, 'savePreferences'])
            ->middleware(['can:candidates.preferences.manage', 'throttle:write'])->name('candidates.preferences.update');

        $r->post('/candidates/{candidate}/passports', [CandidateController::class, 'storePassport'])
            ->middleware(['can:candidates.passport.manage', 'throttle:write'])->name('candidates.passports.store');
        $r->put('/candidates/{candidate}/passports/{passport}', [CandidateController::class, 'updatePassport'])
            ->middleware(['can:candidates.passport.manage', 'throttle:write'])->name('candidates.passports.update');
        $r->delete('/candidates/{candidate}/passports/{passport}', [CandidateController::class, 'destroyPassport'])
            ->middleware(['can:candidates.passport.manage', 'throttle:write'])->name('candidates.passports.destroy');

        $r->post('/candidates/{candidate}/notes', [CandidateController::class, 'addNote'])
            ->middleware(['can:candidates.edit', 'throttle:write'])->name('candidates.notes.store');

        $r->post('/candidates/{candidate}/tasks', [CandidateController::class, 'storeTask'])
            ->middleware(['can:tasks.create', 'throttle:write'])->name('candidates.tasks.store');
        $r->post('/candidates/{candidate}/tasks/{task}/complete', [CandidateController::class, 'completeTask'])
            ->middleware(['can:tasks.complete', 'throttle:write'])->name('candidates.tasks.complete');
        $r->post('/candidates/{candidate}/tasks/{task}/cancel', [CandidateController::class, 'cancelTask'])
            ->middleware(['can:tasks.edit', 'throttle:write'])->name('candidates.tasks.cancel');

        $r->post('/candidates/{candidate}/documents', [DocumentController::class, 'store'])
            ->middleware(['can:documents.upload', 'throttle:write'])->name('candidates.documents.store');
        $r->delete('/candidates/{candidate}/documents/{document}', [DocumentController::class, 'destroy'])
            ->middleware(['can:documents.delete', 'throttle:write'])->name('candidates.documents.destroy');
        $r->post('/candidates/{candidate}/documents/{document}/review', [DocumentController::class, 'startReview'])
            ->middleware(['throttle:write'])->name('candidates.documents.review');
        $r->post('/candidates/{candidate}/documents/{document}/verify', [DocumentController::class, 'verify'])
            ->middleware(['can:documents.verify', 'throttle:write'])->name('candidates.documents.verify');
        $r->post('/candidates/{candidate}/documents/{document}/reject', [DocumentController::class, 'reject'])
            ->middleware(['can:documents.reject', 'throttle:write'])->name('candidates.documents.reject');
        $r->post('/candidates/{candidate}/checklist/{type}', [DocumentController::class, 'toggleChecklist'])
            ->middleware(['can:documents.checklist.manage', 'throttle:write'])->name('candidates.checklist.toggle');

        $r->get('/documents/{document}/download', [DocumentController::class, 'download'])
            ->middleware(['can:documents.view'])->name('documents.download');
        $r->get('/documents/{document}/preview', [DocumentController::class, 'preview'])
            ->middleware(['can:documents.view'])->name('documents.preview');

        // ---- Employers (literal paths before the {employer} wildcard) ----
        $r->get('/employers', [EmployerController::class, 'index'])->middleware(['can:employers.view'])->name('employers.index');
        $r->get('/employers/create', [EmployerController::class, 'create'])->middleware(['can:employers.create'])->name('employers.create');
        $r->post('/employers', [EmployerController::class, 'store'])->middleware(['can:employers.create', 'throttle:write'])->name('employers.store');
        $r->get('/employers/{employer}', [EmployerController::class, 'show'])->middleware(['can:employers.view'])->name('employers.show');
        $r->get('/employers/{employer}/edit', [EmployerController::class, 'edit'])->middleware(['can:employers.edit'])->name('employers.edit');
        $r->put('/employers/{employer}', [EmployerController::class, 'update'])->middleware(['can:employers.edit', 'throttle:write'])->name('employers.update');
        $r->delete('/employers/{employer}', [EmployerController::class, 'destroy'])->middleware(['can:employers.delete', 'throttle:write'])->name('employers.destroy');

        $r->post('/employers/{employer}/contacts', [EmployerController::class, 'storeContact'])
            ->middleware(['can:employers.contacts.manage', 'throttle:write'])->name('employers.contacts.store');
        $r->put('/employers/{employer}/contacts/{contact}', [EmployerController::class, 'updateContact'])
            ->middleware(['can:employers.contacts.manage', 'throttle:write'])->name('employers.contacts.update');
        $r->delete('/employers/{employer}/contacts/{contact}', [EmployerController::class, 'destroyContact'])
            ->middleware(['can:employers.contacts.manage', 'throttle:write'])->name('employers.contacts.destroy');

        // ---- Applications ----
        $r->get('/applications', [ApplicationController::class, 'index'])->middleware(['can:applications.view'])->name('applications.index');
        $r->post('/applications', [ApplicationController::class, 'store'])->middleware(['can:applications.create', 'throttle:write'])->name('applications.store');
        $r->get('/applications/{application}', [ApplicationController::class, 'show'])->middleware(['can:applications.view'])->name('applications.show');
        $r->post('/applications/{application}/status', [ApplicationController::class, 'changeStatus'])->middleware(['can:applications.change_status', 'throttle:write'])->name('applications.status');
        $r->post('/applications/{application}/interviews', [InterviewController::class, 'store'])->middleware(['can:interviews.create', 'throttle:write'])->name('interviews.store');

        // ---- Interviews ----
        $r->get('/interviews', [InterviewController::class, 'index'])->middleware(['can:interviews.view'])->name('interviews.index');
        $r->post('/interviews/{interview}/confirm', [InterviewController::class, 'confirm'])->middleware(['can:interviews.edit', 'throttle:write'])->name('interviews.confirm');
        $r->post('/interviews/{interview}/reschedule', [InterviewController::class, 'reschedule'])->middleware(['can:interviews.edit', 'throttle:write'])->name('interviews.reschedule');
        $r->post('/interviews/{interview}/outcome', [InterviewController::class, 'outcome'])->middleware(['can:interviews.record_outcome', 'throttle:write'])->name('interviews.outcome');

        // ---- Medical ----
        $r->get('/medical', [MedicalController::class, 'index'])->middleware(['can:medical.view'])->name('medical.index');
        $r->post('/candidates/{candidate}/medical', [MedicalController::class, 'store'])->middleware(['can:medical.create', 'throttle:write'])->name('medical.store');
        $r->post('/medical/{medical}/reschedule', [MedicalController::class, 'reschedule'])->middleware(['can:medical.edit', 'throttle:write'])->name('medical.reschedule');
        $r->post('/medical/{medical}/attended', [MedicalController::class, 'attended'])->middleware(['can:medical.edit', 'throttle:write'])->name('medical.attended');
        $r->post('/medical/{medical}/result', [MedicalController::class, 'result'])->middleware(['can:medical.edit', 'throttle:write'])->name('medical.result');
        $r->delete('/medical/{medical}', [MedicalController::class, 'destroy'])->middleware(['can:medical.delete', 'throttle:write'])->name('medical.destroy');

        // ---- Visa ----
        $r->get('/visa', [VisaController::class, 'index'])->middleware(['can:visa.view'])->name('visa.index');
        $r->post('/candidates/{candidate}/visa', [VisaController::class, 'store'])->middleware(['can:visa.create', 'throttle:write'])->name('visa.store');
        $r->get('/visa/{visa}', [VisaController::class, 'show'])->middleware(['can:visa.view'])->name('visa.show');
        $r->put('/visa/{visa}', [VisaController::class, 'update'])->middleware(['can:visa.edit', 'throttle:write'])->name('visa.update');
        $r->post('/visa/{visa}/status', [VisaController::class, 'changeStatus'])->middleware(['can:visa.change_status', 'throttle:write'])->name('visa.status');
        $r->delete('/visa/{visa}', [VisaController::class, 'destroy'])->middleware(['can:visa.delete', 'throttle:write'])->name('visa.destroy');

        // ---- Travel: pipeline, flights, departure, arrival, placement ----
        $r->get('/travel', [TravelController::class, 'index'])->middleware(['can:travel.view'])->name('travel.index');
        $r->get('/placements', [TravelController::class, 'placements'])->middleware(['can:travel.view'])->name('placements.index');
        $r->post('/applications/{application}/flights', [TravelController::class, 'bookFlight'])->middleware(['can:travel.tickets.manage', 'throttle:write'])->name('flights.store');
        $r->post('/flights/{flight}/status', [TravelController::class, 'flightStatus'])->middleware(['can:travel.tickets.manage', 'throttle:write'])->name('flights.status');
        $r->post('/flights/{flight}', [TravelController::class, 'updateFlight'])->middleware(['can:travel.tickets.manage', 'throttle:write'])->name('flights.update');
        $r->post('/applications/{application}/departure', [TravelController::class, 'departure'])->middleware(['can:travel.departure.manage', 'throttle:write'])->name('travel.departure');
        $r->post('/applications/{application}/arrival', [TravelController::class, 'arrival'])->middleware(['can:travel.departure.manage', 'throttle:write'])->name('travel.arrival');
        $r->post('/applications/{application}/placement', [TravelController::class, 'place'])->middleware(['can:travel.placement.manage', 'throttle:write'])->name('travel.place');
        $r->post('/applications/{application}/travel-profile', [TravelController::class, 'profile'])->middleware(['can:travel.profile.manage', 'throttle:write'])->name('travel.profile');
        $r->post('/placements/{placement}/status', [TravelController::class, 'placementStatus'])->middleware(['can:travel.placement.manage', 'throttle:write'])->name('placements.status');

        // ---- Tour packages (literal paths before the {package} wildcard) ----
        $r->get('/tours/packages', [TourPackageController::class, 'index'])->middleware(['can:tours.packages.view'])->name('tours.packages.index');
        $r->get('/tours/packages/create', [TourPackageController::class, 'create'])->middleware(['can:tours.packages.create'])->name('tours.packages.create');
        $r->post('/tours/packages', [TourPackageController::class, 'store'])->middleware(['can:tours.packages.create', 'throttle:write'])->name('tours.packages.store');
        $r->get('/tours/packages/{package}', [TourPackageController::class, 'show'])->middleware(['can:tours.packages.view'])->name('tours.packages.show');
        $r->get('/tours/packages/{package}/edit', [TourPackageController::class, 'edit'])->middleware(['can:tours.packages.edit'])->name('tours.packages.edit');
        $r->put('/tours/packages/{package}', [TourPackageController::class, 'update'])->middleware(['can:tours.packages.edit', 'throttle:write'])->name('tours.packages.update');
        $r->post('/tours/packages/{package}/status', [TourPackageController::class, 'changeStatus'])->middleware(['can:tours.packages.edit', 'throttle:write'])->name('tours.packages.status');
        $r->post('/tours/packages/{package}/publish', [TourPackageController::class, 'publish'])->middleware(['can:tours.packages.publish', 'throttle:write'])->name('tours.packages.publish');
        $r->delete('/tours/packages/{package}', [TourPackageController::class, 'destroy'])->middleware(['can:tours.packages.delete', 'throttle:write'])->name('tours.packages.destroy');
        $r->post('/tours/packages/{package}/items', [TourPackageController::class, 'storeItem'])->middleware(['can:tours.packages.edit', 'throttle:write'])->name('tours.packages.items.store');
        $r->delete('/tours/packages/{package}/items/{item}', [TourPackageController::class, 'destroyItem'])->middleware(['can:tours.packages.edit', 'throttle:write'])->name('tours.packages.items.destroy');

        // ---- Tour bookings (literal paths before the {booking} wildcard) ----
        $r->get('/tours/bookings', [TourBookingController::class, 'index'])->middleware(['can:tours.bookings.view'])->name('tours.bookings.index');
        $r->get('/tours/bookings/create', [TourBookingController::class, 'create'])->middleware(['can:tours.bookings.create'])->name('tours.bookings.create');
        $r->post('/tours/bookings', [TourBookingController::class, 'store'])->middleware(['can:tours.bookings.create', 'throttle:write'])->name('tours.bookings.store');
        $r->get('/tours/bookings/{booking}', [TourBookingController::class, 'show'])->middleware(['can:tours.bookings.view'])->name('tours.bookings.show');
        $r->get('/tours/bookings/{booking}/edit', [TourBookingController::class, 'edit'])->middleware(['can:tours.bookings.edit'])->name('tours.bookings.edit');
        $r->put('/tours/bookings/{booking}', [TourBookingController::class, 'update'])->middleware(['can:tours.bookings.edit', 'throttle:write'])->name('tours.bookings.update');
        $r->post('/tours/bookings/{booking}/status', [TourBookingController::class, 'changeStatus'])->middleware(['can:tours.bookings.change_status', 'throttle:write'])->name('tours.bookings.status');

        // ---- Invoices (literal paths before the {invoice} wildcard) ----
        $r->get('/invoices', [InvoiceController::class, 'index'])->middleware(['can:invoices.view'])->name('invoices.index');
        $r->get('/invoices/create', [InvoiceController::class, 'create'])->middleware(['can:invoices.create'])->name('invoices.create');
        $r->post('/invoices', [InvoiceController::class, 'store'])->middleware(['can:invoices.create', 'throttle:write'])->name('invoices.store');
        $r->get('/invoices/{invoice}', [InvoiceController::class, 'show'])->middleware(['can:invoices.view'])->name('invoices.show');
        $r->get('/invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->middleware(['can:invoices.edit'])->name('invoices.edit');
        $r->put('/invoices/{invoice}', [InvoiceController::class, 'update'])->middleware(['can:invoices.edit', 'throttle:write'])->name('invoices.update');
        $r->post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->middleware(['can:invoices.create', 'throttle:write'])->name('invoices.issue');
        $r->post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->middleware(['can:invoices.void', 'throttle:write'])->name('invoices.void');

        // ---- Payments ----
        $r->get('/payments', [PaymentController::class, 'index'])->middleware(['can:payments.view'])->name('payments.index');
        $r->get('/invoices/{invoice}/payments/create', [PaymentController::class, 'create'])->middleware(['can:payments.create'])->name('payments.create');
        $r->post('/invoices/{invoice}/payments', [PaymentController::class, 'store'])->middleware(['can:payments.create', 'throttle:write'])->name('payments.store');
        $r->get('/payments/{payment}', [PaymentController::class, 'show'])->middleware(['can:payments.view'])->name('payments.show');
        $r->get('/payments/{payment}/receipt', [PaymentController::class, 'receipt'])->middleware(['can:receipts.view'])->name('payments.receipt');
        $r->put('/payments/{payment}', [PaymentController::class, 'update'])->middleware(['can:payments.edit', 'throttle:write'])->name('payments.update');
        $r->post('/payments/{payment}/allocate', [PaymentController::class, 'allocate'])->middleware(['can:allocations.manage', 'throttle:write'])->name('payments.allocate');
        $r->post('/payments/{payment}/reverse', [PaymentController::class, 'reverse'])->middleware(['can:payments.reverse', 'throttle:write'])->name('payments.reverse');

        // ---- Jobs (literal paths before the {job} wildcard) ----
        $r->get('/jobs', [JobController::class, 'index'])->middleware(['can:jobs.view'])->name('jobs.index');
        $r->get('/jobs/create', [JobController::class, 'create'])->middleware(['can:jobs.create'])->name('jobs.create');
        $r->post('/jobs', [JobController::class, 'store'])->middleware(['can:jobs.create', 'throttle:write'])->name('jobs.store');
        $r->get('/jobs/{job}', [JobController::class, 'show'])->middleware(['can:jobs.view'])->name('jobs.show');
        $r->get('/jobs/{job}/matches', [MatchController::class, 'forJob'])->middleware(['can:jobs.match'])->name('jobs.matches');
        $r->get('/jobs/{job}/edit',[JobController::class, 'edit'])->middleware(['can:jobs.edit'])->name('jobs.edit');
        $r->put('/jobs/{job}', [JobController::class, 'update'])->middleware(['can:jobs.edit', 'throttle:write'])->name('jobs.update');
        $r->delete('/jobs/{job}', [JobController::class, 'destroy'])->middleware(['can:jobs.delete', 'throttle:write'])->name('jobs.destroy');
        $r->post('/jobs/{job}/status', [JobController::class, 'changeStatus'])->middleware(['can:jobs.change_status', 'throttle:write'])->name('jobs.status');
        $r->post('/jobs/{job}/publish', [JobController::class, 'publish'])->middleware(['can:jobs.publish', 'throttle:write'])->name('jobs.publish');
        $r->post('/jobs/{job}/requirements', [JobController::class, 'storeRequirement'])->middleware(['can:jobs.edit', 'throttle:write'])->name('jobs.requirements.store');
        $r->delete('/jobs/{job}/requirements/{requirement}', [JobController::class, 'destroyRequirement'])->middleware(['can:jobs.edit', 'throttle:write'])->name('jobs.requirements.destroy');
        $r->post('/jobs/{job}/benefits', [JobController::class, 'storeBenefit'])->middleware(['can:jobs.edit', 'throttle:write'])->name('jobs.benefits.store');
        $r->delete('/jobs/{job}/benefits/{benefit}', [JobController::class, 'destroyBenefit'])->middleware(['can:jobs.edit', 'throttle:write'])->name('jobs.benefits.destroy');
    });
};
