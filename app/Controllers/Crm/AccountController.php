<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Auth\Auth;
use App\Auth\RememberMe;
use App\Auth\TrustedDevice;
use App\Auth\TwoFactor;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\AuthTokenRepository;
use App\Repositories\SessionRepository;
use App\Repositories\TrustedDeviceRepository;
use App\Repositories\UserRepository;
use App\Support\Hash;
use App\Support\Totp;
use App\Validators\Validator;

final class AccountController extends CrmController
{
    public function __construct(
        private readonly TwoFactor $twoFactor,
        private readonly Totp $totp,
        private readonly Hash $hash,
        private readonly UserRepository $users,
        private readonly SessionRepository $sessions,
        private readonly TrustedDeviceRepository $devices,
        private readonly AuthTokenRepository $authTokens,
        private readonly Auth $auth,
        private readonly RememberMe $remember,
        private readonly TrustedDevice $trustedDevice,
    ) {
    }

    // ---- profile ------------------------------------------------

    public function profile(): Response
    {
        $user = $this->currentUser();
        $phone = app(\App\Support\Db::class)->selectValue('SELECT phone FROM users WHERE id = :id', ['id' => $user->id]);

        return view_response('crm.account.profile', ['user' => $user, 'userPhone' => $phone ?? '']);
    }

    public function updateProfile(Request $request): Response
    {
        $user = $this->currentUser();
        $v = Validator::make($request->only(['name', 'phone']), [
            'name'  => 'required|string|max:120',
            'phone' => 'nullable|string|max:30',
        ]);
        if ($v->fails()) {
            return redirect_with_errors($v->errors(), $request->all(), '/account/profile');
        }
        $data = $v->validated();
        app(\App\Support\Db::class)->affectingStatement(
            'UPDATE users SET name = :n, phone = :p WHERE id = :id',
            ['id' => $user->id, 'n' => trim((string) $data['name']), 'p' => ($data['phone'] ?? '') !== '' ? trim((string) $data['phone']) : null],
        );
        audit()->log('profile_updated', 'account', 'user', $user->id, null, null, null, $user);
        flash('status', 'Profile updated.');

        return Response::redirect('/account/profile');
    }

    public function changePassword(Request $request): Response
    {
        $user = $this->currentUser();
        $v = Validator::make($request->only(['current_password', 'password', 'password_confirmation']), [
            'current_password' => 'required|string',
            'password'         => 'required|string|min:10|max:200|confirmed',
        ]);
        if ($v->fails()) {
            return redirect_with_errors($v->errors(), [], '/account/security');
        }

        $stored = $this->users->passwordHashFor($user->id) ?? '';
        if (!$this->hash->verify((string) $request->input('current_password'), $stored)) {
            return redirect_with_errors(['current_password' => ['That is not your current password.']], [], '/account/security');
        }

        $this->users->updatePasswordHash($user->id, $this->hash->make((string) $request->input('password')));
        // Keep this session, drop the rest + remember tokens.
        $sid = $request->attribute('session')?->id() ?? '';
        $this->sessions->deleteForUserExcept($user->id, $sid);
        $this->remember->revokeAll($user->id);
        audit()->log('password_changed', 'account', 'user', $user->id, null, null, 'self-service', $user);
        flash('status', 'Password changed. Other sessions have been signed out.');

        return Response::redirect('/account/security');
    }

    // ---- security overview ----------------------------------

    public function security(Request $request): Response
    {
        $user = $this->currentUser();
        $currentSid = $request->attribute('session')?->id() ?? '';

        return view_response('crm.account.security', [
            'user'        => $user,
            'twoFactor'   => [
                'enabled'   => $this->twoFactor->enabledFor($user),
                'method'    => $user->twoFactorMethod,
                'recovery'  => $this->twoFactor->remainingRecoveryCodes($user),
                'required'  => $this->twoFactor->requiredFor($user),
            ],
            'devices'     => $this->devices->forUser($user->id),
            'sessions'    => array_map(function ($s) use ($currentSid) {
                $s['is_current'] = ((string) $s['id']) === $currentSid;
                return $s;
            }, $this->sessions->forUser($user->id)),
            'rememberCount' => count($this->authTokens->forUser($user->id)),
        ]);
    }

    // ---- 2FA setup -----------------------------------------

    public function twoFactorSetup(Request $request): Response
    {
        $user = $this->currentUser();
        if ($this->twoFactor->enabledFor($user)) {
            return Response::redirect('/account/security');
        }

        $enrol = $this->twoFactor->beginTotpEnrolment($user); // stores unconfirmed secret

        return view_response('crm.account.two-factor-setup', [
            'secret'   => $enrol['secret'],
            'uri'      => $enrol['uri'],
            'display'  => $this->totp->formatSecretForDisplay($enrol['secret']),
        ]);
    }

    public function twoFactorConfirm(Request $request): Response
    {
        $user = $this->currentUser();
        try {
            $codes = $this->twoFactor->confirmTotpEnrolment($user, (string) $request->input('code', ''));
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), [], '/account/two-factor');
        }

        $request->attribute('session')?->flash('_recovery_codes', $codes);

        return Response::redirect('/account/recovery-codes');
    }

    public function recoveryCodes(Request $request): Response
    {
        $codes = $request->attribute('session')?->get('_recovery_codes');
        if (!is_array($codes) || $codes === []) {
            return Response::redirect('/account/security');
        }

        return view_response('crm.account.recovery-codes', ['codes' => $codes]);
    }

    public function regenerateRecoveryCodes(Request $request): Response
    {
        // Step-up is enforced by the `confirm` middleware on this route.
        $user = $this->currentUser();
        $codes = $this->twoFactor->regenerateRecoveryCodes($user);
        $request->attribute('session')?->flash('_recovery_codes', $codes);

        return Response::redirect('/account/recovery-codes');
    }

    public function disableTwoFactor(Request $request): Response
    {
        $user = $this->currentUser();
        if ($this->twoFactor->requiredFor($user)) {
            flash('error_toast', 'Two-factor authentication is required for your role and cannot be disabled.');

            return Response::redirect('/account/security');
        }

        // Step-up is enforced by the `confirm` middleware on this route.
        $this->twoFactor->disable($user, $user);
        flash('status', 'Two-factor authentication disabled.');

        return Response::redirect('/account/security');
    }

    // ---- device / session revocation ---------------------

    public function revokeDevice(Request $request): Response
    {
        $id = (int) $request->input('id', 0);
        if ($id > 0) {
            $this->devices->deleteById($this->currentUser()->id, $id);
            flash('status', 'Device removed. It will need to verify next time.');
        }

        return Response::redirect('/account/security');
    }

    public function revokeSession(Request $request): Response
    {
        $id = (string) $request->input('id', '');
        $current = $request->attribute('session')?->id() ?? '';
        if ($id !== '' && $id !== $current) {
            $this->sessions->deleteOne($this->currentUser()->id, $id);
            flash('status', 'That session has been signed out.');
        }

        return Response::redirect('/account/security');
    }

    public function signOutEverywhere(Request $request): Response
    {
        $user = $this->currentUser();
        $current = $request->attribute('session')?->id() ?? '';
        $this->sessions->deleteForUserExcept($user->id, $current);
        $this->remember->revokeAll($user->id);
        $this->trustedDevice->revokeAll($user->id);
        audit()->log('sessions_revoked', 'account', 'user', $user->id, null, null, 'sign out everywhere', $user);
        flash('status', 'Signed out of all other devices.');

        return Response::redirect('/account/security');
    }
}
