<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Auth\WebAuthn\WebAuthnException;
use App\Auth\WebAuthn\WebAuthnService;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\TwoFactorRepository;
use App\Repositories\WebAuthnCredentialRepository;
use App\Session\Session;
use App\Support\Db;

/**
 * Self-service passkey (WebAuthn) management. Registration is a JSON ceremony
 * driven by resources/js/app.js; rename / delete / second-factor toggle are
 * plain form posts.
 */
final class PasskeyController extends CrmController
{
    public function __construct(
        private readonly WebAuthnService $webauthn,
        private readonly WebAuthnCredentialRepository $credentials,
        private readonly TwoFactorRepository $twoFactorRepo,
        private readonly Db $db,
    ) {
    }

    public function index(): Response
    {
        $user = $this->currentUser();

        return view_response('crm.account.passkeys', [
            'passkeys'      => $this->credentials->forUser($user->id),
            'isSecondFactor' => $user->twoFactorEnabled && $user->twoFactorMethod === 'passkey',
            'twoFactorMethod' => $user->twoFactorMethod,
        ]);
    }

    /** JSON: options for navigator.credentials.create(). */
    public function options(Request $request): Response
    {
        $user = $this->currentUser();
        $session = $this->session($request);

        return $this->json($this->webauthn->registrationOptions($user, $session));
    }

    /** JSON: verify the attestation response and store the credential. */
    public function store(Request $request): Response
    {
        $user = $this->currentUser();
        $session = $this->session($request);
        $body = $request->json();

        $label = (string) ($body['label'] ?? $request->input('label', 'Security key'));

        try {
            $result = $this->webauthn->verifyRegistration($user, (array) ($body['credential'] ?? $body), $session, $label);
        } catch (WebAuthnException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        audit()->log('passkey_registered', 'auth', 'user', $user->id, null, ['credential_id' => $result['id'], 'label' => $result['label']], null, $user);

        return $this->json(['ok' => true, 'id' => $result['id'], 'label' => $result['label']]);
    }

    public function rename(Request $request): Response
    {
        $user = $this->currentUser();
        $id = (int) $request->route('id', '0');
        $label = trim((string) $request->input('label', ''));

        if ($label !== '' && $this->credentials->renameForUser($user->id, $id, mb_substr($label, 0, 60))) {
            flash('status', 'Passkey renamed.');
        }

        return Response::redirect('/account/passkeys');
    }

    public function destroy(Request $request): Response
    {
        $user = $this->currentUser();
        $id = (int) $request->route('id', '0');

        if (!$this->credentials->deleteForUser($user->id, $id)) {
            return Response::redirect('/account/passkeys');
        }

        audit()->log('passkey_removed', 'auth', 'user', $user->id, ['credential_id' => $id], null, null, $user);

        // If passkeys were the second factor and none remain, fall back to no 2FA
        // (unless the role requires it — then the user must add another).
        if ($user->twoFactorEnabled
            && $user->twoFactorMethod === 'passkey'
            && !$this->credentials->userHasAny($user->id)) {
            $this->db->affectingStatement(
                "UPDATE users SET two_factor_enabled = 0, two_factor_method = 'none' WHERE id = :id",
                ['id' => $user->id],
            );
            audit()->log('2fa_disabled', 'auth', 'user', $user->id, ['method' => 'passkey'], ['enabled' => false], 'last passkey removed', $user);
            flash('status', 'Passkey removed. Two-factor authentication is now off.');
        } else {
            flash('status', 'Passkey removed.');
        }

        return Response::redirect('/account/passkeys');
    }

    /** Enable or disable "use my passkeys as the second factor". */
    public function toggleSecondFactor(Request $request): Response
    {
        $user = $this->currentUser();
        $enable = $request->boolean('enable');

        if ($enable) {
            if (!$this->credentials->userHasAny($user->id)) {
                flash('error_toast', 'Add a passkey first.');

                return Response::redirect('/account/passkeys');
            }
            $this->db->affectingStatement(
                "UPDATE users SET two_factor_enabled = 1, two_factor_method = 'passkey' WHERE id = :id",
                ['id' => $user->id],
            );
            audit()->log('2fa_enabled', 'auth', 'user', $user->id, null, ['method' => 'passkey'], null, $user);
            flash('status', 'Passkeys are now required as your second factor.');

            return Response::redirect('/account/passkeys');
        }

        // Disabling — blocked if the role mandates 2FA and there is no other method.
        $roles = (array) config('auth.two_factor.required_roles', []);
        $totpConfirmed = $this->db->exists('SELECT 1 FROM users WHERE id = :id AND totp_confirmed_at IS NOT NULL', ['id' => $user->id]);
        if (in_array($user->roleName, $roles, true) && !$totpConfirmed) {
            flash('error_toast', 'Two-factor authentication is required for your role. Set up an authenticator app first.');

            return Response::redirect('/account/passkeys');
        }

        $this->db->affectingStatement(
            "UPDATE users SET two_factor_enabled = 0, two_factor_method = 'none' WHERE id = :id",
            ['id' => $user->id],
        );
        audit()->log('2fa_disabled', 'auth', 'user', $user->id, ['method' => 'passkey'], ['enabled' => false], null, $user);
        flash('status', 'Passkeys are no longer required to sign in.');

        return Response::redirect('/account/passkeys');
    }

    private function session(Request $request): Session
    {
        $session = $request->attribute('session');
        if (!$session instanceof Session) {
            abort(500, 'Session unavailable.');
        }

        return $session;
    }
}
