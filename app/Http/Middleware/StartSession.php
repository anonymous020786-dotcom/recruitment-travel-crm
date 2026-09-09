<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Session\Session;
use App\Session\SessionStore;
use App\Support\Application;
use Closure;

/**
 * Bridges the session cookie ↔ SessionStore ↔ Session object.
 *
 *   - reads (and format-validates) the session id from the cookie, else starts fresh
 *   - enforces absolute lifetime + idle timeout (either → fresh session)
 *   - regenerates the id on a fixed cadence and whenever the app asked for it
 *   - ages flash data at the start of the request
 *   - exposes the Session as the `session` request attribute + container instance
 *   - persists on the way out and queues the (HttpOnly/Secure/SameSite) cookie
 */
final class StartSession implements Middleware
{
    public function __construct(
        private readonly Application $app,
        private readonly SessionStore $store,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $cfg = $this->app->config();
        $cookieName = (string) $cfg->get('session.cookie', 'crm_session');
        $idleSeconds = (int) $cfg->get('session.idle_minutes', 30) * 60;
        $lifetimeSeconds = (int) $cfg->get('session.lifetime_minutes', 480) * 60;
        $regenSeconds = (int) $cfg->get('session.regenerate_minutes', 20) * 60;
        $now = time();

        $cookieId = (string) $request->cookie($cookieName, '');
        $id = Session::isValidId($cookieId) ? $cookieId : Session::newId();

        $data = $id === $cookieId ? $this->store->read($id) : [];
        $session = new Session($id, $data);

        // Absolute lifetime / idle timeout → drop everything, start clean.
        $startedAt = (int) $session->get('_started_at', 0);
        $lastActivity = (int) $session->get('_last_activity', 0);
        $expiredAbsolute = $startedAt > 0 && ($now - $startedAt) > $lifetimeSeconds;
        $expiredIdle = $lastActivity > 0 && ($now - $lastActivity) > $idleSeconds;

        if ($expiredAbsolute || $expiredIdle) {
            $this->store->destroy($session->id());
            $session = new Session(Session::newId());
        }

        if (!$session->has('_started_at')) {
            $session->put('_started_at', $now);
        }

        // Periodic id rotation (defence against fixation / long-lived ids).
        if (($now - (int) $session->get('_last_regen', 0)) > $regenSeconds) {
            $session->regenerate(destroyOld: true);
        }

        $session->ageFlashData();
        $session->token(); // ensure a CSRF token exists

        $request->setAttribute('session', $session);
        $this->app->instance(Session::class, $session);

        $response = $next($request);

        // Remember the last non-AJAX GET as the "previous url" for redirect-back.
        if ($request->isMethod('GET') && !$request->isXmlHttpRequest()) {
            $session->setPreviousUrl($request->path());
        }

        $session->put('_last_activity', time());

        if (($old = $session->migrateFrom()) !== null && $old !== $session->id()) {
            $this->store->destroy($old);
        }

        $this->store->write($session->id(), $session->all(), [
            'user_id'    => $session->get('_auth_user_id'),
            'ip'         => $request->ipBinary(),
            'user_agent' => $request->userAgent(),
        ]);

        return $response->withCookie($cookieName, $session->id(), [
            'expires'  => $now + $lifetimeSeconds,
            'path'     => (string) $cfg->get('session.path', '/'),
            'domain'   => $cfg->get('session.domain') ?: '',
            'secure'   => (bool) $cfg->get('session.secure', true),
            'httponly' => (bool) $cfg->get('session.http_only', true),
            'samesite' => (string) $cfg->get('session.same_site', 'Lax'),
        ]);
    }
}
