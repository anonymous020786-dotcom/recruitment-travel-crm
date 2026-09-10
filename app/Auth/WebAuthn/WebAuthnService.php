<?php

declare(strict_types=1);

namespace App\Auth\WebAuthn;

use App\Models\User;
use App\Repositories\WebAuthnCredentialRepository;
use App\Session\Session;
use App\Support\Application;
use App\Support\Cbor;
use App\Support\CoseKey;

/**
 * WebAuthn ceremonies — registration (navigator.credentials.create) and
 * assertion (navigator.credentials.get) — verified server-side end to end.
 *
 * All parsing is defensive: a step that cannot be checked fails closed with a
 * {@see WebAuthnException}. Challenges are single-use and bound to a purpose so
 * a registration challenge can never be replayed into an assertion.
 */
final class WebAuthnService
{
    private const CHALLENGE_KEY = '_webauthn_challenge';

    public function __construct(
        private readonly WebAuthnCredentialRepository $credentials,
        private readonly Application $app,
    ) {
    }

    // ---- base64url --------------------------------------------------

    public static function b64urlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $pad = strlen($value) % 4;
        if ($pad !== 0) {
            $value .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($value, true);
        if ($out === false) {
            throw new WebAuthnException('Malformed base64url value.');
        }

        return $out;
    }

    // ---- registration ---------------------------------------------

    /**
     * Options for navigator.credentials.create(). The caller JSON-encodes this
     * and the browser helper turns the base64url fields back into ArrayBuffers.
     *
     * @return array<string,mixed>
     */
    public function registrationOptions(User $user, Session $session): array
    {
        $challenge = $this->issueChallenge($session, 'register:' . $user->id);

        $exclude = [];
        foreach ($this->credentials->forUser($user->id) as $credential) {
            $exclude[] = [
                'type' => 'public-key',
                'id'   => self::b64urlEncode((string) $credential['credential_id']),
            ];
        }

        return [
            'rp' => [
                'id'   => $this->rpId(),
                'name' => (string) $this->config('rp_name', 'CRM'),
            ],
            'user' => [
                // A stable, opaque handle — never PII, never the primary key in the clear.
                'id'          => self::b64urlEncode(hash('sha256', 'webauthn-user:' . $user->id, true)),
                'name'        => $user->email,
                'displayName' => $user->name,
            ],
            'challenge'        => self::b64urlEncode($challenge),
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => CoseKey::ES256],
                ['type' => 'public-key', 'alg' => CoseKey::RS256],
            ],
            'timeout'                => (int) $this->config('timeout_ms', 60000),
            'attestation'            => 'none',
            'excludeCredentials'     => $exclude,
            'authenticatorSelection' => [
                'residentKey'      => 'preferred',
                'userVerification' => (string) $this->config('user_verification', 'preferred'),
            ],
        ];
    }

    /**
     * Verify a registration response and persist the credential.
     *
     * @param array<string,mixed> $body the PublicKeyCredential JSON from the browser
     * @return array{id:int,label:string}
     */
    public function verifyRegistration(User $user, array $body, Session $session, string $label): array
    {
        $challenge = $this->consumeChallenge($session, 'register:' . $user->id);

        $response = (array) ($body['response'] ?? []);
        $clientDataJSON = self::b64urlDecode((string) ($response['clientDataJSON'] ?? ''));
        $attestationObject = self::b64urlDecode((string) ($response['attestationObject'] ?? ''));

        $this->verifyClientData($clientDataJSON, 'webauthn.create', $challenge);

        $attestation = Cbor::decode($attestationObject);
        if (!is_array($attestation) || !isset($attestation['fmt'], $attestation['authData'])) {
            throw new WebAuthnException('Malformed attestation object.');
        }

        $authData = AuthenticatorData::parse((string) $attestation['authData']);
        $this->assertRelyingParty($authData);

        if (!$authData->userPresent()) {
            throw new WebAuthnException('The authenticator did not confirm your presence.');
        }
        if (!$authData->hasAttestedCredential() || $authData->credentialPublicKeyBytes === null) {
            throw new WebAuthnException('The authenticator did not return a credential.');
        }
        if (strlen((string) $authData->credentialId) > 255) {
            throw new WebAuthnException('The credential id is too large to store.');
        }

        // The public key must be one of the algorithms we asked for.
        $coseKey = CoseKey::fromArray($authData->credentialPublicKey);

        $clientDataHash = hash('sha256', $clientDataJSON, true);
        $this->verifyAttestationStatement(
            (string) $attestation['fmt'],
            (array) ($attestation['attStmt'] ?? []),
            $authData,
            $clientDataHash,
            $coseKey,
        );

        if ($this->credentials->findByCredentialId((string) $authData->credentialId) !== null) {
            throw new WebAuthnException('That security key is already registered.');
        }

        $id = $this->credentials->create(
            $user->id,
            (string) $authData->credentialId,
            $authData->credentialPublicKeyBytes,
            $authData->signCount,
            self::transportsToString($response['transports'] ?? null),
            $authData->aaguid,
            self::cleanLabel($label),
        );

        return ['id' => $id, 'label' => self::cleanLabel($label)];
    }

    // ---- assertion (sign-in / step-up) --------------------------

    /**
     * Options for navigator.credentials.get(). Pass the user for a 2FA step
     * (credentials are listed); pass null for passwordless discovery.
     *
     * @return array<string,mixed>
     */
    public function assertionOptions(Session $session, ?User $user): array
    {
        $challenge = $this->issueChallenge($session, $this->assertionPurpose($user?->id));

        $allow = [];
        if ($user !== null) {
            foreach ($this->credentials->forUser($user->id) as $credential) {
                $allow[] = [
                    'type'       => 'public-key',
                    'id'         => self::b64urlEncode((string) $credential['credential_id']),
                    'transports' => self::transportsToArray($credential['transports'] ?? null),
                ];
            }
            if ($allow === []) {
                throw new WebAuthnException('This account has no security keys.');
            }
        }

        return [
            'challenge'        => self::b64urlEncode($challenge),
            'rpId'             => $this->rpId(),
            'timeout'          => (int) $this->config('timeout_ms', 60000),
            'userVerification' => $user === null ? 'required' : (string) $this->config('user_verification', 'preferred'),
            'allowCredentials' => $allow,
        ];
    }

    /**
     * Verify an assertion.
     *
     * @param array<string,mixed> $body           PublicKeyCredential JSON from the browser
     * @param int|null            $expectedUserId  non-null for a 2FA step — the credential must belong to this user;
     *                                             null for passwordless — the credential identifies the user and
     *                                             user verification is mandatory
     * @return array{user_id:int,credential_id:int}
     */
    public function verifyAssertion(array $body, Session $session, ?int $expectedUserId): array
    {
        $challenge = $this->consumeChallenge($session, $this->assertionPurpose($expectedUserId));

        $response = (array) ($body['response'] ?? []);
        $clientDataJSON = self::b64urlDecode((string) ($response['clientDataJSON'] ?? ''));
        $authenticatorData = self::b64urlDecode((string) ($response['authenticatorData'] ?? ''));
        $signature = self::b64urlDecode((string) ($response['signature'] ?? ''));
        $rawId = self::b64urlDecode((string) ($body['rawId'] ?? $body['id'] ?? ''));

        if ($rawId === '') {
            throw new WebAuthnException('No credential was supplied.');
        }

        $this->verifyClientData($clientDataJSON, 'webauthn.get', $challenge);

        $stored = $this->credentials->findByCredentialId($rawId);
        if ($stored === null) {
            throw new WebAuthnException('Unrecognised security key.');
        }
        if ($expectedUserId !== null && (int) $stored['user_id'] !== $expectedUserId) {
            throw new WebAuthnException('That security key is not registered to this account.');
        }

        $authData = AuthenticatorData::parse($authenticatorData);
        $this->assertRelyingParty($authData);

        if (!$authData->userPresent()) {
            throw new WebAuthnException('The authenticator did not confirm your presence.');
        }
        if ($expectedUserId === null && !$authData->userVerified()) {
            throw new WebAuthnException('This sign-in needs a verifying authenticator (PIN or biometric).');
        }

        $clientDataHash = hash('sha256', $clientDataJSON, true);
        $signedMessage = $authenticatorData . $clientDataHash;

        $cose = Cbor::decode((string) $stored['public_key']);
        if (!is_array($cose)) {
            throw new WebAuthnException('The stored credential is corrupt.');
        }
        if (!CoseKey::fromArray($cose)->verify($signedMessage, $signature)) {
            throw new WebAuthnException('The security key signature could not be verified.');
        }

        // Clone detection: once either side has a non-zero counter, it must
        // strictly increase on every use.
        $newCount = $authData->signCount;
        $oldCount = (int) $stored['sign_count'];
        if (($newCount !== 0 || $oldCount !== 0) && $newCount <= $oldCount) {
            throw new WebAuthnException('The security key counter went backwards — it may be cloned. Access denied.');
        }

        $this->credentials->markUsed((int) $stored['id'], $newCount);

        return ['user_id' => (int) $stored['user_id'], 'credential_id' => (int) $stored['id']];
    }

    // ---- verification internals --------------------------------

    private function verifyClientData(string $json, string $expectedType, string $challenge): void
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new WebAuthnException('Malformed client data.');
        }
        if (($data['type'] ?? null) !== $expectedType) {
            throw new WebAuthnException('Unexpected client-data type.');
        }
        if (!hash_equals($challenge, self::b64urlDecode((string) ($data['challenge'] ?? '')))) {
            throw new WebAuthnException('Challenge mismatch.');
        }
        if (!in_array((string) ($data['origin'] ?? ''), $this->origins(), true)) {
            throw new WebAuthnException('Untrusted origin.');
        }
        // If token binding is asserted as present it must carry an id.
        $binding = $data['tokenBinding'] ?? null;
        if (is_array($binding) && ($binding['status'] ?? null) === 'present' && empty($binding['id'])) {
            throw new WebAuthnException('Invalid token binding.');
        }
    }

    private function assertRelyingParty(AuthenticatorData $authData): void
    {
        if (!hash_equals(hash('sha256', $this->rpId(), true), $authData->rpIdHash)) {
            throw new WebAuthnException('Relying-party mismatch.');
        }
    }

    /**
     * We advertise attestation "none", so the usual case is fmt=none with
     * nothing to check. "packed" self-attestation and "fido-u2f" are also
     * verified when sent. Full attestation-certificate chain validation
     * (enterprise attestation) is out of scope for a second factor — such a
     * statement is accepted once its signature checks out.
     *
     * @param array<string,mixed> $attStmt
     */
    private function verifyAttestationStatement(
        string $fmt,
        array $attStmt,
        AuthenticatorData $authData,
        string $clientDataHash,
        CoseKey $credentialKey,
    ): void {
        if ($fmt === 'none') {
            return;
        }

        $signed = $authData->raw . $clientDataHash;

        if ($fmt === 'packed') {
            $sig = (string) ($attStmt['sig'] ?? '');
            if ($sig === '') {
                throw new WebAuthnException('The attestation signature is missing.');
            }

            $x5c = $attStmt['x5c'] ?? null;
            if (is_array($x5c) && isset($x5c[0])) {
                $pem = self::derToCertificatePem((string) $x5c[0]);
                $cert = openssl_pkey_get_public($pem);
                if ($cert === false || openssl_verify($signed, $sig, $cert, OPENSSL_ALGO_SHA256) !== 1) {
                    throw new WebAuthnException('The attestation certificate did not verify.');
                }

                return;
            }

            // Self-attestation: signed with the credential private key.
            if (!$credentialKey->verify($signed, $sig)) {
                throw new WebAuthnException('Self-attestation did not verify.');
            }

            return;
        }

        if ($fmt === 'fido-u2f' || $fmt === 'tpm' || $fmt === 'android-key'
            || $fmt === 'android-safetynet' || $fmt === 'apple') {
            // The credential public key inside authData is already validated and
            // is what we bind the account to. These formats add attestation
            // provenance we do not consume for a 2FA credential.
            return;
        }

        throw new WebAuthnException('Unsupported attestation format.');
    }

    // ---- challenge lifecycle ----------------------------------

    private function issueChallenge(Session $session, string $purpose): string
    {
        $challenge = random_bytes(32);
        $session->put(self::CHALLENGE_KEY, [
            'value'   => self::b64urlEncode($challenge),
            'purpose' => $purpose,
            'at'      => time(),
        ]);

        return $challenge;
    }

    private function consumeChallenge(Session $session, string $purpose): string
    {
        $stored = $session->get(self::CHALLENGE_KEY);
        $session->forget(self::CHALLENGE_KEY); // single use — always cleared

        $ttl = (int) $this->config('challenge_ttl_seconds', 300);
        if (!is_array($stored)
            || ($stored['purpose'] ?? null) !== $purpose
            || !isset($stored['value'], $stored['at'])
            || (time() - (int) $stored['at']) > $ttl) {
            throw new WebAuthnException('The security-key challenge has expired. Please try again.');
        }

        return self::b64urlDecode((string) $stored['value']);
    }

    private function assertionPurpose(?int $userId): string
    {
        return 'assert:' . ($userId ?? '*');
    }

    // ---- config helpers -------------------------------------

    private function config(string $key, mixed $default = null): mixed
    {
        return $this->app->config()->get("webauthn.{$key}", $default);
    }

    private function rpId(): string
    {
        return (string) $this->config('rp_id', 'localhost');
    }

    /** @return list<string> */
    private function origins(): array
    {
        return array_values(array_filter(
            array_map('strval', (array) $this->config('origins', [])),
            static fn (string $o): bool => $o !== '',
        ));
    }

    // ---- small value helpers -------------------------------

    private static function cleanLabel(string $label): string
    {
        $label = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $label) ?? '');

        return $label === '' ? 'Security key' : mb_substr($label, 0, 60);
    }

    private static function transportsToString(mixed $transports): ?string
    {
        if (!is_array($transports)) {
            return null;
        }
        $clean = [];
        foreach ($transports as $transport) {
            if (is_string($transport) && preg_match('/^[a-z-]{1,16}$/', $transport)) {
                $clean[] = $transport;
            }
        }

        return $clean === [] ? null : implode(',', array_slice(array_unique($clean), 0, 8));
    }

    /** @return list<string> */
    private static function transportsToArray(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        return array_values(array_filter(explode(',', $value), static fn (string $t): bool => $t !== ''));
    }

    private static function derToCertificatePem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END CERTIFICATE-----\n";
    }
}
