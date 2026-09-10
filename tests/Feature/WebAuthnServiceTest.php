<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\WebAuthn\WebAuthnException;
use App\Auth\WebAuthn\WebAuthnService;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Repositories\WebAuthnCredentialRepository;
use App\Session\Session;
use App\Support\Hash;
use App\Support\Ulid;
use Tests\Support\FakeAuthenticator;
use Tests\Support\DbTestCase;

final class WebAuthnServiceTest extends DbTestCase
{
    private WebAuthnService $service;
    private WebAuthnCredentialRepository $repo;
    private User $user;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('openssl_pkey_new')) {
            self::markTestSkipped('openssl extension not available');
        }

        $cfg = $this->app->get(\App\Support\Config::class);
        $cfg->set('webauthn.rp_id', 'localhost');
        $cfg->set('webauthn.origins', ['http://localhost']);
        $cfg->set('webauthn.challenge_ttl_seconds', 300);
        $cfg->set('webauthn.user_verification', 'preferred');

        $roleId = (int) $this->db->selectValue('SELECT id FROM roles LIMIT 1');
        if ($roleId === 0) {
            self::markTestSkipped('roles not seeded');
        }

        $this->userId = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(),
            'name' => 'WA User',
            'email' => 'wa_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $roleId,
            'is_active' => 1,
        ]);
        $this->user = $this->app->get(UserRepository::class)->findById($this->userId);
        $this->service = $this->app->get(WebAuthnService::class);
        $this->repo = $this->app->get(WebAuthnCredentialRepository::class);
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement('DELETE FROM webauthn_credentials WHERE user_id = ?', [$this->userId]);
        $this->db->affectingStatement('DELETE FROM users WHERE id = ?', [$this->userId]);
    }

    private function registerFakeKey(Session $session, ?FakeAuthenticator $auth = null): FakeAuthenticator
    {
        $auth ??= new FakeAuthenticator();
        $options = $this->service->registrationOptions($this->user, $session);
        $response = $auth->register($options['challenge']);
        $this->service->verifyRegistration($this->user, $response, $session, 'Test key');

        return $auth;
    }

    public function test_registration_persists_a_credential(): void
    {
        $session = new Session('s', []);
        $auth = $this->registerFakeKey($session);

        $stored = $this->repo->findByCredentialId($auth->credentialId);
        self::assertNotNull($stored);
        self::assertSame($this->userId, (int) $stored['user_id']);
        self::assertSame(1, $this->repo->countForUser($this->userId));
    }

    public function test_registration_rejects_a_replayed_challenge(): void
    {
        $session = new Session('s', []);
        $options = $this->service->registrationOptions($this->user, $session);
        $auth = new FakeAuthenticator();
        $response = $auth->register($options['challenge']);

        $this->service->verifyRegistration($this->user, $response, $session, 'k');

        $this->expectException(WebAuthnException::class);
        $this->service->verifyRegistration($this->user, $response, $session, 'k'); // challenge consumed
    }

    public function test_registration_rejects_a_foreign_origin(): void
    {
        $session = new Session('s', []);
        $options = $this->service->registrationOptions($this->user, $session);
        $response = (new FakeAuthenticator(origin: 'https://evil.example'))->register($options['challenge']);

        $this->expectException(WebAuthnException::class);
        $this->service->verifyRegistration($this->user, $response, $session, 'k');
    }

    public function test_assertion_verifies_and_advances_sign_count(): void
    {
        $session = new Session('s', []);
        $auth = $this->registerFakeKey($session);

        $options = $this->service->assertionOptions($session, $this->user);
        $result = $this->service->verifyAssertion($auth->assert($options['challenge'], true, 5), $session, $this->userId);

        self::assertSame($this->userId, $result['user_id']);
        self::assertSame(5, (int) $this->repo->findByCredentialId($auth->credentialId)['sign_count']);
    }

    public function test_assertion_rejects_sign_count_regression(): void
    {
        $session = new Session('s', []);
        $auth = $this->registerFakeKey($session);

        $opts1 = $this->service->assertionOptions($session, $this->user);
        $this->service->verifyAssertion($auth->assert($opts1['challenge'], true, 10), $session, $this->userId);

        $opts2 = $this->service->assertionOptions($session, $this->user);
        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessageMatches('/cloned/i');
        $this->service->verifyAssertion($auth->assert($opts2['challenge'], true, 4), $session, $this->userId);
    }

    public function test_assertion_rejects_a_tampered_signature(): void
    {
        $session = new Session('s', []);
        $auth = $this->registerFakeKey($session);

        $options = $this->service->assertionOptions($session, $this->user);
        $response = $auth->assert($options['challenge'], true, 3);
        $response['response']['signature'] = WebAuthnService::b64urlEncode(random_bytes(70));

        $this->expectException(WebAuthnException::class);
        $this->service->verifyAssertion($response, $session, $this->userId);
    }

    public function test_passwordless_requires_user_verification(): void
    {
        $session = new Session('s', []);
        $auth = $this->registerFakeKey($session);

        // UV flag not set -> rejected for a passwordless assertion.
        $opts = $this->service->assertionOptions($session, null);
        try {
            $this->service->verifyAssertion($auth->assert($opts['challenge'], false, 2), $session, null);
            self::fail('expected WebAuthnException');
        } catch (WebAuthnException $e) {
            self::assertMatchesRegularExpression('/verifying authenticator/i', $e->getMessage());
        }

        // UV set -> resolves the user from the credential.
        $opts = $this->service->assertionOptions($session, null);
        $result = $this->service->verifyAssertion($auth->assert($opts['challenge'], true, 3), $session, null);
        self::assertSame($this->userId, $result['user_id']);
    }

    public function test_assertion_challenge_is_single_use(): void
    {
        $session = new Session('s', []);
        $auth = $this->registerFakeKey($session);

        $options = $this->service->assertionOptions($session, $this->user);
        $response = $auth->assert($options['challenge'], true, 6);
        $this->service->verifyAssertion($response, $session, $this->userId);

        $this->expectException(WebAuthnException::class);
        $this->service->verifyAssertion($response, $session, $this->userId);
    }
}
