<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Request;
use App\Http\Router;
use App\Session\ArraySessionStore;
use App\Session\Session;
use App\Session\SessionStore;
use Tests\Support\DbTestCase;

/**
 * Public contact form through the real router (session + csrf + throttle).
 * Turnstile is unconfigured in tests, so it is skipped (optional_when_unconfigured).
 */
final class ContactFormTest extends DbTestCase
{
    private Router $router;
    private ArraySessionStore $store;
    private string $token;
    private string $sid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $this->store);

        $this->sid = bin2hex(random_bytes(32));
        $this->token = bin2hex(random_bytes(32));
        $this->store->sessions[$this->sid] = ['data' => [
            '_token' => $this->token, '_started_at' => time(), '_last_regen' => time(), '_last_activity' => time(),
        ], 'touched' => time()];

        $this->router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($this->router);
        $this->router->finalizeNames();
        $this->app->instance(Router::class, $this->router);
    }

    private string $ip = '';

    protected function tearDown(): void
    {
        $this->db->affectingStatement("DELETE FROM public_enquiries WHERE name LIKE 'CF %'");
        if ($this->ip !== '') {
            $this->db->affectingStatement("DELETE FROM rate_limits WHERE bucket_key LIKE ?", ['%' . $this->ip . '%']);
        }
    }

    private function post(array $body): \App\Http\Response
    {
        $body['_token'] ??= $this->token;
        // A fresh IP per test so the public_form rate-limit bucket is not shared.
        $this->ip = '198.51.100.' . random_int(1, 250);

        return $this->router->dispatch(new Request([], $body, ['crm_session' => $this->sid], [], [
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/contact', 'REMOTE_ADDR' => $this->ip,
            'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'http://localhost',
        ], ''));
    }

    public function test_contact_page_renders(): void
    {
        $res = $this->router->dispatch(new Request([], [], ['crm_session' => $this->sid], [], [
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/contact', 'HTTP_HOST' => 'localhost',
        ], ''));
        self::assertSame(200, $res->getStatus());
        self::assertStringContainsString('Contact us', $res->getBody());
    }

    public function test_valid_submission_creates_enquiry(): void
    {
        $res = $this->post([
            'name' => 'CF Valid', 'phone' => '9876500123', 'email' => 'cf@example.com',
            'message' => 'Please call me about jobs in Dubai.',
        ]);
        self::assertSame(302, $res->getStatus());
        self::assertTrue($this->db->exists(
            "SELECT 1 FROM public_enquiries WHERE type='contact' AND name='CF Valid' AND status='new'",
        ));
    }

    public function test_honeypot_is_silently_accepted_without_storing(): void
    {
        $before = (int) $this->db->selectValue('SELECT COUNT(*) FROM public_enquiries');
        $res = $this->post([
            'name' => 'CF Bot', 'phone' => '9000000000', 'message' => 'spam spam', 'company' => 'ACME (bot)',
        ]);
        self::assertSame(302, $res->getStatus());
        self::assertSame($before, (int) $this->db->selectValue('SELECT COUNT(*) FROM public_enquiries'));
    }

    public function test_invalid_input_redirects_with_errors(): void
    {
        $res = $this->post(['name' => '', 'phone' => 'nope', 'message' => 'x']);
        self::assertSame(302, $res->getStatus());
        self::assertArrayHasKey('name', errors());
        self::assertArrayHasKey('message', errors());
    }

    public function test_csrf_required(): void
    {
        try {
            $this->post(['name' => 'CF X', 'phone' => '9000000000', 'message' => 'hello there', '_token' => 'wrong']);
            self::fail('expected 419');
        } catch (\App\Exceptions\HttpException $e) {
            self::assertSame(419, $e->getStatusCode());
        }
    }
}
