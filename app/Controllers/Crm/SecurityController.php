<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Audit\AuditService;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\SecurityOverviewRepository;
use App\Repositories\SessionRepository;
use App\Security\IpRules;
use App\Security\SecurityPolicy;
use App\Services\UserAdminService;
use App\Support\Db;

/**
 * Admin → Security (super admin, `security.*`): rate limits, the two-factor policy, IP allow/block rules, who is signed in,
 * and who has been failing to sign in. The rules live in SecurityPolicy and IpRules; this class only translates.
 */
final class SecurityController extends CrmController
{
    public function __construct(
        private readonly SecurityPolicy $policy,
        private readonly IpRules $ipRules,
        private readonly SecurityOverviewRepository $overview,
        private readonly SessionRepository $sessions,
        private readonly UserAdminService $users,
        private readonly AuditService $audit,
        private readonly Db $db,
    ) {
    }

    // ---- overview -----------------------------------------------------------------------------------------------

    public function index(Request $request): Response
    {
        $life = $this->lifetime();

        return $this->private(view_response('crm.admin.security.index', [
            'sessionCounts' => $this->overview->sessionCounts($life),
            'attempts' => $this->overview->attemptCounts(24),
            'top' => $this->overview->topFailingAddresses(24),
            'recent' => $this->overview->recentAttempts(25),
            'locked' => $this->overview->lockedAccounts(),
            'ipRuleCount' => $this->ipRules->count(),
            'gaps' => $this->policy->twoFactorGaps(),
            'twoFactor' => $this->policy->twoFactor(),
            'customLimits' => count(array_filter($this->policy->rateLimits(), static fn (array $r): bool => $r['custom'])),
            'autoBlock' => $this->policy->autoBlock(),
            'canManage' => can('security.manage'),
            'yourIp' => $request->ip(),
        ]));
    }

    // ---- rate limits --------------------------------------------------------------------------------------------

    public function rateLimits(): Response
    {
        return $this->private(view_response('crm.admin.security.rate-limits', ['rows' => $this->policy->rateLimits(), 'canManage' => can('security.manage')]));
    }

    public function saveRateLimits(Request $request): Response
    {
        $input = $request->input('rl', []);
        try {
            $changed = $this->policy->saveRateLimits(is_array($input) ? $input : [], $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), is_array($input) ? ['rl' => $input] : [], '/admin/security/rate-limits');
        }
        flash('status', $changed === [] ? 'Nothing changed.' : count($changed) . ' limit' . (count($changed) === 1 ? '' : 's') . ' saved. They apply from the next request.');

        return Response::redirect('/admin/security/rate-limits');
    }

    public function resetRateLimits(Request $request): Response
    {
        $bucket = (string) $request->input('bucket', '');
        $n = $this->policy->resetRateLimits($bucket === '' ? null : $bucket, $this->currentUser());
        flash('status', $n > 0 ? 'Back to the defaults.' : 'Nothing was customised.');

        return Response::redirect('/admin/security/rate-limits');
    }

    // ---- two-factor policy + automatic block --------------------------------------------------------------------

    public function policy(): Response
    {
        return $this->private(view_response('crm.admin.security.policy', [
            'twoFactor' => $this->policy->twoFactor(),
            'gaps' => $this->policy->twoFactorGaps(),
            'roles' => $this->db->select('SELECT name, label FROM roles ORDER BY id'),
            'autoBlock' => $this->policy->autoBlock(),
            'canManage' => can('security.manage'),
        ]));
    }

    public function savePolicy(Request $request): Response
    {
        $roles = $request->input('roles', []);
        try {
            $this->policy->saveTwoFactor(is_array($roles) ? array_values($roles) : [], $request->input('grace', ''), $this->currentUser());
            $this->policy->saveAutoBlock($request->input('threshold', ''), $request->input('minutes', ''), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->only(['grace', 'threshold', 'minutes']) + ['roles' => is_array($roles) ? $roles : []], '/admin/security/policy');
        }
        flash('status', 'Security policy saved.');

        return Response::redirect('/admin/security/policy');
    }

    // ---- IP rules -----------------------------------------------------------------------------------------------

    public function ipRules(Request $request): Response
    {
        return $this->private(view_response('crm.admin.security.ip-rules', [
            'rules' => $this->ipRules->all(), 'canManage' => can('security.manage'), 'yourIp' => $request->ip(), 'max' => IpRules::MAX_RULES,
        ]));
    }

    public function addIpRule(Request $request): Response
    {
        $minutes = trim((string) $request->input('minutes', ''));
        try {
            $this->ipRules->add(
                (string) $request->input('effect', 'block'),
                (string) $request->input('cidr', ''),
                (string) $request->input('note', ''),
                $minutes === '' ? null : (preg_match('/^\d{1,7}$/D', $minutes) === 1 ? (int) $minutes : 0),
                $this->currentUser(),
                $request->ip(),
            );
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->only(['effect', 'cidr', 'note', 'minutes']), '/admin/security/ip-rules');
        }
        flash('status', 'The rule was added and applies immediately.');

        return Response::redirect('/admin/security/ip-rules');
    }

    public function removeIpRule(string $id): Response
    {
        if (preg_match('/^\d{1,18}$/D', $id) !== 1 || !$this->ipRules->remove((int) $id, $this->currentUser())) {
            abort(404);
        }
        flash('status', 'The rule was removed.');

        return Response::redirect('/admin/security/ip-rules');
    }

    // ---- sessions -----------------------------------------------------------------------------------------------

    public function sessions(Request $request): Response
    {
        return $this->private(view_response('crm.admin.security.sessions', [
            'rows' => $this->overview->activeSessions($this->lifetime()),
            'currentId' => $request->attribute('session')?->id() ?? '',
            'canManage' => can('security.manage'),
        ]));
    }

    public function revokeSession(Request $request): Response
    {
        $id = (string) $request->input('id', '');
        $row = preg_match('/^[a-f0-9]{64}$/D', $id) === 1 ? $this->overview->session($id) : null;
        if ($row === null) {
            flash('status', 'That session is already gone.');
        } elseif ($id === ($request->attribute('session')?->id() ?? '')) {
            flash('status', 'That is the session you are using; use Sign out for that.');
        } else {
            $this->sessions->deleteOne((int) $row['user_id'], $id);
            $this->audit->log('session_revoked', 'security', 'user', (int) $row['user_id'], null, null, 'ended from the security centre', $this->currentUser());
            flash('status', 'That session was signed out.');
        }

        return Response::redirect('/admin/security/sessions');
    }

    public function signOutUser(string $user): Response
    {
        try {
            $this->users->signOutEverywhere($user, $this->currentUser());
        } catch (DomainRuleException $e) {
            return redirect_with_errors(['form' => [$e->getMessage()]], [], '/admin/security/sessions');
        }
        flash('status', 'Signed out everywhere.');

        return Response::redirect('/admin/security/sessions');
    }

    public function signOutEveryoneElse(Request $request): Response
    {
        $me = $this->currentUser();
        $n = $this->overview->endAllExcept($request->attribute('session')?->id() ?? '', $me->id);
        $this->audit->log('all_sessions_revoked', 'security', 'security', 0, null, ['sessions' => $n], 'signed everyone else out', $me);
        flash('status', $n === 0 ? 'Nobody else was signed in.' : "Signed {$n} session" . ($n === 1 ? '' : 's') . ' out. Everyone must sign in again.');

        return Response::redirect('/admin/security/sessions');
    }

    // ---- internals ----------------------------------------------------------------------------------------------

    private function lifetime(): int
    {
        return (int) config('session.lifetime_minutes', 480) * 60;
    }

    private function private(Response $response): Response
    {
        return $response->withHeader('Cache-Control', 'no-store, private');
    }
}
