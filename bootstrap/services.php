<?php

declare(strict_types=1);

/**
 * Container service bindings. Shared by bootstrap/app.php (real requests + CLI)
 * and the integration test harness, so tests exercise the same wiring.
 *
 * Assumes Config, Logger and Db instances/bindings are already registered.
 */

use App\Audit\AuditService;
use App\Auth\Auth;
use App\Auth\AuthService;
use App\Auth\BranchScopeResolver;
use App\Auth\Gate;
use App\Auth\PermissionService;
use App\Domain\StatusMachine;
use App\Http\Kernel;
use App\Http\Router;
use App\Integrations\IntegrationsService;
use App\Integrations\Turnstile;
use App\Support\HttpClient;
use App\Mail\Mailer;
use App\Mail\QueueMailer;
use App\Notifications\NotificationService;
use App\Services\LeadService;
use App\Session\DatabaseSessionStore;
use App\Session\SessionStore;
use App\Support\Application;
use App\Support\Clock;
use App\Support\Db;
use App\Support\Hash;
use App\Support\Logger;
use App\Support\RateLimiter;
use App\Support\Sequences;
use App\Support\Signer;
use App\View\Assets;
use App\View\View;

return static function (Application $app): void {

    $app->singleton(Router::class, static fn (Application $app): Router => new Router($app));
    $app->singleton(Kernel::class);

    $app->singleton(Signer::class, static fn (Application $app): Signer => new Signer(
        (string) $app->config()->get('app.key', ''),
    ));

    $app->singleton(SessionStore::class, static fn (Application $app): SessionStore => new DatabaseSessionStore(
        $app->get(Db::class),
        (string) $app->config()->get('session.table', 'sessions'),
    ));

    $app->singleton(Hash::class, static fn (Application $app): Hash => new Hash(
        (array) $app->config()->get('security.hash', []),
    ));

    $app->singleton(RateLimiter::class, static fn (Application $app): RateLimiter => new RateLimiter(
        $app->get(Db::class),
        (string) $app->config()->get('rate_limits.table', 'rate_limits'),
    ));

    $app->singleton(Mailer::class, static fn (Application $app): Mailer => new QueueMailer(
        $app->get(Db::class),
        $app->get(Logger::class),
        logBody: !$app->isProduction(),
    ));

    $app->singleton(\App\Mail\Transport\Transport::class, static function (Application $app): \App\Mail\Transport\Transport {
        $cfg = $app->config();
        if ((string) $cfg->get('mail.driver', 'log') === 'smtp'
            && (string) $cfg->get('mail.smtp.host', '') !== ''
            && (string) $cfg->get('mail.smtp.username', '') !== '') {
            $fromDomain = explode('@', (string) $cfg->get('mail.from.address', 'crm@localhost'))[1] ?? 'localhost';

            return new \App\Mail\Transport\SmtpTransport((array) $cfg->get('mail.smtp', []), $fromDomain);
        }

        return new \App\Mail\Transport\LogTransport($app->get(Logger::class));
    });

    $app->singleton(\App\Mail\MailQueue::class, static fn (Application $app): \App\Mail\MailQueue => new \App\Mail\MailQueue(
        $app->get(Db::class),
        $app->get(\App\Mail\Transport\Transport::class),
        $app->get(Logger::class),
        [
            'from_address' => (string) $app->config()->get('mail.from.address', 'no-reply@localhost'),
            'from_name'    => (string) $app->config()->get('mail.from.name', 'CRM'),
        ] + (array) $app->config()->get('mail.queue', []),
    ));

    $app->singleton(\App\Mail\MailComposer::class);
    $app->singleton(\App\Support\CronRunner::class);
    $app->singleton(\App\Auth\LoginAlerts::class);

    $app->singleton(View::class, static fn (Application $app): View => new View($app->basePath('resources/views')));
    $app->singleton(Assets::class, static fn (Application $app): Assets => new Assets($app->basePath('public')));

    $app->singleton(HttpClient::class, static fn (Application $app): HttpClient => new HttpClient(
        logger: $app->get(Logger::class),
    ));
    $app->singleton(IntegrationsService::class, static fn (Application $app): IntegrationsService => new IntegrationsService(
        (array) $app->config()->get('integrations', []),
    ));
    $app->singleton(Turnstile::class, static fn (Application $app): Turnstile => new Turnstile(
        (array) $app->config()->get('integrations.turnstile', []),
        $app->get(HttpClient::class),
        $app->get(Logger::class),
    ));

    $app->singleton(Clock::class, static fn (Application $app): Clock => new Clock(
        (string) $app->config()->get('app.timezone', 'UTC'),
    ));
    $app->singleton(Sequences::class, static fn (Application $app): Sequences => new Sequences($app->get(Db::class)));
    $app->singleton(StatusMachine::class, static fn (Application $app): StatusMachine => new StatusMachine(
        (array) $app->config()->get('statuses', []),
    ));
    $app->singleton(\App\Domain\Matching\MatchEngine::class, static fn (Application $app): \App\Domain\Matching\MatchEngine => new \App\Domain\Matching\MatchEngine(
        (array) $app->config()->get('matching', []),
    ));

    $app->singleton(\App\Support\Totp::class);
    $app->singleton(\App\Support\Encryptor::class, static fn (Application $app) => new \App\Support\Encryptor(
        (string) $app->config()->get('app.key', ''),
    ));

    $app->singleton(Auth::class);
    $app->singleton(\App\Auth\RememberMe::class);
    $app->singleton(\App\Auth\TrustedDevice::class);
    $app->singleton(\App\Auth\TwoFactor::class);
    $app->singleton(\App\Auth\WebAuthn\WebAuthnService::class);
    $app->singleton(PermissionService::class);
    $app->singleton(BranchScopeResolver::class);
    $app->singleton(AuditService::class);
    $app->singleton(AuthService::class);
    $app->singleton(NotificationService::class);
    $app->singleton(LeadService::class);
    $app->singleton(\App\Services\LeadImportService::class);
    $app->singleton(\App\Services\LeadExportService::class);
    $app->singleton(\App\Services\CandidateService::class);
    $app->singleton(\App\Services\EmployerService::class);
    $app->singleton(\App\Services\JobService::class);
    $app->singleton(\App\Services\ApplicationService::class);
    $app->singleton(\App\Services\InterviewService::class);
    $app->singleton(\App\Services\MedicalService::class);
    $app->singleton(\App\Services\VisaService::class);
    $app->singleton(\App\Services\TravelService::class);
    $app->singleton(\App\Services\ExpiryService::class, static fn (Application $app): \App\Services\ExpiryService => new \App\Services\ExpiryService(
        $app->get(Db::class),
        $app->get(\App\Repositories\VisaRepository::class),
        $app->get(\App\Repositories\VisaHistoryRepository::class),
        $app->get(\App\Repositories\MedicalRepository::class),
        $app->get(\App\Repositories\PassportRepository::class),
        $app->get(\App\Repositories\UserRepository::class),
        $app->get(\App\Notifications\NotificationService::class),
        $app->get(\App\Audit\AuditService::class),
        (array) $app->config()->get('cron.reminder_windows', []),
    ));
    $app->singleton(\App\Services\MatchService::class, static fn (Application $app): \App\Services\MatchService => new \App\Services\MatchService(
        $app->get(\App\Domain\Matching\MatchEngine::class),
        $app->get(\App\Repositories\MatchProfileRepository::class),
        $app->get(\App\Repositories\JobRepository::class),
        $app->get(\App\Repositories\JobRequirementRepository::class),
        $app->get(Gate::class),
        $app->get(\App\Auth\BranchScopeResolver::class),
        (array) $app->config()->get('matching', []),
    ));

    $app->singleton(Gate::class, static function (Application $app): Gate {
        $gate = new Gate($app, $app->get(PermissionService::class), $app->get(Auth::class));

        // model class => policy class. One line per feature phase.
        $gate->policy(\App\Models\Lead::class, \App\Policies\LeadPolicy::class);
        $gate->policy(\App\Models\Candidate::class, \App\Policies\CandidatePolicy::class);
        $gate->policy(\App\Models\Employer::class, \App\Policies\EmployerPolicy::class);
        $gate->policy(\App\Models\Job::class, \App\Policies\JobPolicy::class);
        $gate->policy(\App\Models\Application::class, \App\Policies\ApplicationPolicy::class);
        $gate->policy(\App\Models\Interview::class, \App\Policies\InterviewPolicy::class);
        $gate->policy(\App\Models\MedicalRecord::class, \App\Policies\MedicalPolicy::class);
        $gate->policy(\App\Models\VisaApplication::class, \App\Policies\VisaPolicy::class);
        $gate->policy(\App\Models\FlightBooking::class, \App\Policies\FlightPolicy::class);
        $gate->policy(\App\Models\Placement::class, \App\Policies\PlacementPolicy::class);

        return $gate;
    });
};
