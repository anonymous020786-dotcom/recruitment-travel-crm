<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Session\ArraySessionStore;
use App\Session\SessionStore;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

/**
 * The public jobs board and travel packages through the real router: only what is meant to be public is shown, nothing
 * internal leaks, pages are cacheable and SEO-complete, and the apply / enquire forms feed the enquiry inbox.
 */
final class PublicSiteTest extends DbTestCase
{
    private Router $router;
    private ArraySessionStore $store;
    private string $sid;
    private string $token;
    private string $ip = '';
    private int $branchId;
    private int $employerId;
    /** @var array<string,int> */
    private array $jobs = [];
    /** @var array<string,int> */
    private array $packages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $this->store);
        $this->sid = bin2hex(random_bytes(32));
        $this->token = bin2hex(random_bytes(32));
        $this->store->sessions[$this->sid] = ['data' => ['_token' => $this->token, '_started_at' => time(), '_last_regen' => time(), '_last_activity' => time()], 'touched' => time()];

        $this->router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($this->router);
        $this->router->finalizeNames();
        $this->app->instance(Router::class, $this->router);

        $this->branchId = (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => 'PS branch', 'code' => 'PSX-' . bin2hex(random_bytes(2))]);
        $this->employerId = (int) $this->db->insertRow('employers', [
            'public_id' => Ulid::generate(), 'employer_number' => 'EMP-PS-' . random_int(100000, 999999), 'company_name' => 'SECRET EMPLOYER LLC', 'country' => 'AE', 'branch_id' => $this->branchId,
        ]);

        $tomorrow = gmdate('Y-m-d', strtotime('+10 days'));
        $yesterday = gmdate('Y-m-d', strtotime('-1 day'));
        $this->job('visible', ['title' => 'Site Electrician', 'city' => 'Dubai', 'salary_min' => '1800', 'salary_max' => '2400', 'currency' => 'AED', 'accommodation' => 'provided', 'deadline' => $tomorrow, 'description_html' => '<p>Install <strong>wiring</strong>.</p><script>alert(1)</script>']);
        $this->job('australia', ['title' => 'Farm Hand', 'country' => 'AU', 'city' => 'Perth']);
        $this->job('draft', ['status' => 'draft']);
        $this->job('private', ['is_public' => 0]);
        $this->job('closed', ['status' => 'closed']);
        $this->job('expired', ['deadline' => $yesterday]);
        $this->job('deleted', ['deleted_at' => gmdate('Y-m-d H:i:s')]);
        $this->job('xss', ['title' => '<img src=x onerror=alert(1)> Welder']);
        $this->db->insertRow('job_requirements', ['job_id' => $this->jobs['visible'], 'label' => 'Two years experience', 'is_mandatory' => 1, 'weight' => 5]);

        $this->package('bali', ['name' => 'Bali Escape', 'destination' => 'Bali', 'price' => '45000', 'currency' => 'INR', 'duration_days' => 5, 'duration_nights' => 4, 'inclusions_html' => '<p>Hotel and breakfast</p>']);
        $this->package('draftpkg', ['status' => 'draft']);
        $this->package('privatepkg', ['is_public' => 0]);
        $this->package('archived', ['status' => 'archived']);
        $this->db->insertRow('tour_package_items', ['tour_package_id' => $this->packages['bali'], 'day_no' => 1, 'title' => 'Arrival in Denpasar', 'sort_order' => 1]);
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement("DELETE FROM public_enquiries WHERE name LIKE 'PS %'");
        $this->db->affectingStatement("DELETE FROM notifications WHERE type = 'enquiry_new' AND title LIKE '% from PS %'");
        $this->db->affectingStatement("DELETE FROM job_requirements WHERE job_id IN (SELECT id FROM jobs WHERE employer_id = ?)", [$this->employerId]);
        $this->db->affectingStatement('DELETE FROM jobs WHERE employer_id = ?', [$this->employerId]);
        $this->db->affectingStatement("DELETE FROM tour_packages WHERE slug LIKE 'zz-ps-%'");
        $this->db->affectingStatement('DELETE FROM employers WHERE id = ?', [$this->employerId]);
        $this->db->affectingStatement('DELETE FROM branches WHERE id = ?', [$this->branchId]);
        if ($this->ip !== '') {
            $this->db->affectingStatement('DELETE FROM rate_limits WHERE bucket_key LIKE ?', ['%' . $this->ip . '%']);
        }
    }

    /** @param array<string,mixed> $over */
    private function job(string $key, array $over = []): void
    {
        $slug = 'zz-ps-' . $key . '-' . bin2hex(random_bytes(2));
        $this->jobs[$key] = (int) $this->db->insertRow('jobs', $over + [
            'public_id' => Ulid::generate(), 'job_number' => 'JOB-PS-' . random_int(100000, 999999), 'slug' => $slug, 'title' => "PS job {$key}", 'employer_id' => $this->employerId,
            'branch_id' => $this->branchId, 'country' => 'AE', 'vacancies' => 3, 'status' => 'open', 'is_public' => 1,
        ]);
        $this->jobs[$key . ':slug'] = 0;
        $this->slugs[$key] = (string) $this->db->selectValue('SELECT slug FROM jobs WHERE id = ?', [$this->jobs[$key]]);
    }

    /** @var array<string,string> */
    private array $slugs = [];

    /** @param array<string,mixed> $over */
    private function package(string $key, array $over = []): void
    {
        $slug = 'zz-ps-' . $key . '-' . bin2hex(random_bytes(2));
        $this->packages[$key] = (int) $this->db->insertRow('tour_packages', $over + [
            'public_id' => Ulid::generate(), 'slug' => $slug, 'name' => "PS package {$key}", 'destination' => 'Somewhere', 'status' => 'active', 'is_public' => 1,
        ]);
        $this->slugs['pkg:' . $key] = $slug;
    }

    private function get(string $uri, array $query = []): Response
    {
        return $this->router->dispatch(new Request($query, [], ['crm_session' => $this->sid], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '203.0.113.9'], ''));
    }

    private function post(string $uri, array $body): Response
    {
        $this->ip = '198.51.100.' . random_int(1, 250);
        $body['_token'] ??= $this->token;

        return $this->router->dispatch(new Request([], $body, ['crm_session' => $this->sid], [], [
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => $this->ip, 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'http://localhost',
        ], ''));
    }

    private function code(string $uri): int
    {
        try {
            return $this->get($uri)->getStatus();
        } catch (\App\Exceptions\HttpException $e) {
            return $e->getStatusCode();
        }
    }

    // ---- jobs list ----------------------------------------------------------------------

    public function test_the_list_shows_only_open_public_current_jobs(): void
    {
        $res = $this->get('/overseas-jobs');
        $html = $res->getBody();

        self::assertSame(200, $res->getStatus());
        self::assertStringContainsString('Site Electrician', $html);
        self::assertStringContainsString('Farm Hand', $html);
        foreach (['draft', 'private', 'closed', 'expired', 'deleted'] as $hidden) {
            self::assertStringNotContainsString($this->slugs[$hidden], $html, "{$hidden} job must not be listed");
        }
    }

    public function test_nothing_internal_leaks_into_the_public_pages(): void
    {
        $list = $this->get('/overseas-jobs')->getBody();
        $detail = $this->get('/overseas-jobs/' . $this->slugs['visible'])->getBody();
        $apply = $this->get('/overseas-jobs/' . $this->slugs['visible'] . '/apply')->getBody();
        $sitemap = $this->get('/sitemap.xml')->getBody();

        foreach ([$list, $detail, $apply, $sitemap] as $html) {
            self::assertStringNotContainsString('SECRET EMPLOYER', $html, 'the employer is never named');
            self::assertStringNotContainsString('JOB-PS-', $html, 'internal job numbers are not exposed');
        }
    }

    public function test_country_filter_search_and_pagination_links(): void
    {
        $au = $this->get('/overseas-jobs', ['country' => 'au'])->getBody();
        self::assertStringContainsString('Farm Hand', $au);
        self::assertStringNotContainsString('Site Electrician', $au);
        self::assertStringContainsString('overseas-jobs?country=AU">', $au, 'a country page is indexable with its own canonical');

        $q = $this->get('/overseas-jobs', ['q' => 'Electrician']);
        self::assertStringContainsString('Site Electrician', $q->getBody());
        self::assertStringNotContainsString('Farm Hand', $q->getBody());
        self::assertStringContainsString('noindex, follow', $q->getBody(), 'search result pages are not indexed');

        self::assertStringContainsString('No vacancies match', $this->get('/overseas-jobs', ['q' => 'zzz-nothing-zzz'])->getBody());
        self::assertSame(200, $this->get('/overseas-jobs', ['country' => "'; DROP TABLE jobs;--", 'page' => '-5'])->getStatus(), 'junk input is harmless');
    }

    public function test_titles_are_escaped(): void
    {
        $html = $this->get('/overseas-jobs')->getBody();

        self::assertStringNotContainsString('<img src=x onerror', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt; Welder', $html);
    }

    // ---- job detail ------------------------------------------------------------------------

    public function test_a_job_page_has_the_details_structured_data_and_is_cacheable(): void
    {
        $res = $this->get('/overseas-jobs/' . $this->slugs['visible']);
        $html = $res->getBody();

        self::assertSame(200, $res->getStatus());
        self::assertStringContainsString('<h1 class="mt-2 text-2xl font-bold text-slate-900">Site Electrician</h1>', $html);
        self::assertStringContainsString('AED 1,800 – 2,400 / month', $html);
        self::assertStringContainsString('Accommodation provided', $html);
        self::assertStringContainsString('Two years experience', $html);
        self::assertStringContainsString('<strong>wiring</strong>', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html, 'rich text is sanitised on output too');
        self::assertStringContainsString('public, max-age=', (string) $res->getHeader('Cache-Control'));

        preg_match('#<script type="application/ld\+json">(\{"@context":"https://schema.org","@type":"JobPosting".*?)</script>#s', $html, $m);
        $ld = json_decode($m[1] ?? '', true);
        self::assertIsArray($ld, 'JobPosting JSON-LD is valid JSON');
        self::assertSame('Site Electrician', $ld['title']);
        self::assertSame('AE', $ld['jobLocation']['address']['addressCountry']);
        self::assertEquals(1800, $ld['baseSalary']['value']['minValue']);
        self::assertSame('AED', $ld['baseSalary']['currency']);
        self::assertArrayHasKey('validThrough', $ld);
        self::assertNotSame('SECRET EMPLOYER LLC', $ld['hiringOrganization']['name']);
    }

    public function test_hidden_or_unknown_jobs_are_404_everywhere(): void
    {
        foreach (['draft', 'private', 'closed', 'expired', 'deleted'] as $k) {
            self::assertSame(404, $this->code('/overseas-jobs/' . $this->slugs[$k]), "{$k} detail");
            self::assertSame(404, $this->code('/overseas-jobs/' . $this->slugs[$k] . '/apply'), "{$k} apply form");
        }
        self::assertSame(404, $this->code('/overseas-jobs/no-such-job'));
    }

    // ---- applying ------------------------------------------------------------------------------------

    public function test_applying_creates_an_enquiry_linked_to_the_job(): void
    {
        $form = $this->get('/overseas-jobs/' . $this->slugs['visible'] . '/apply');
        self::assertSame(200, $form->getStatus());
        self::assertStringContainsString('name="_token"', $form->getBody());
        self::assertStringContainsString('private', (string) $form->getHeader('Cache-Control'), 'a page with a CSRF token is not shared-cached');

        $res = $this->post('/overseas-jobs/' . $this->slugs['visible'] . '/apply', ['name' => 'PS Applicant', 'phone' => '+91 98765 43210', 'email' => 'ps@example.com', 'message' => 'Available from next month.']);

        self::assertSame(302, $res->getStatus());
        $row = $this->db->selectOne("SELECT * FROM public_enquiries WHERE name = 'PS Applicant'");
        self::assertNotNull($row);
        self::assertSame('job_apply', $row['type']);
        self::assertSame($this->jobs['visible'], (int) $row['job_id']);
        self::assertSame('new', $row['status']);
        self::assertSame($this->slugs['visible'], json_decode((string) $row['meta_json'], true)['job_slug']);
    }

    public function test_applying_needs_only_a_name_and_a_phone_but_validates_them(): void
    {
        $ok = $this->post('/overseas-jobs/' . $this->slugs['visible'] . '/apply', ['name' => 'PS Minimal', 'phone' => '9876543210']);
        self::assertSame(302, $ok->getStatus());
        self::assertTrue($this->db->exists("SELECT 1 FROM public_enquiries WHERE name = 'PS Minimal'"));

        $before = (int) $this->db->selectValue('SELECT COUNT(*) FROM public_enquiries');
        $this->post('/overseas-jobs/' . $this->slugs['visible'] . '/apply', ['name' => 'PS Bad', 'phone' => 'call me']);
        self::assertArrayHasKey('phone', errors());
        self::assertSame($before, (int) $this->db->selectValue('SELECT COUNT(*) FROM public_enquiries'));
    }

    public function test_bots_and_hidden_jobs_cannot_create_enquiries(): void
    {
        $before = (int) $this->db->selectValue('SELECT COUNT(*) FROM public_enquiries');

        $bot = $this->post('/overseas-jobs/' . $this->slugs['visible'] . '/apply', ['name' => 'PS Bot', 'phone' => '9000000000', 'company' => 'ACME']);
        self::assertSame(302, $bot->getStatus(), 'a bot is thanked and ignored');

        try {
            $this->post('/overseas-jobs/' . $this->slugs['draft'] . '/apply', ['name' => 'PS Sneaky', 'phone' => '9000000001']);
            self::fail('applied to a hidden job');
        } catch (\App\Exceptions\HttpException $e) {
            self::assertSame(404, $e->getStatusCode());
        }
        self::assertSame($before, (int) $this->db->selectValue('SELECT COUNT(*) FROM public_enquiries'));

        try {
            $this->post('/overseas-jobs/' . $this->slugs['visible'] . '/apply', ['name' => 'PS NoToken', 'phone' => '9000000002', '_token' => 'wrong']);
            self::fail('accepted without CSRF');
        } catch (\App\Exceptions\HttpException $e) {
            self::assertSame(419, $e->getStatusCode());
        }
    }

    // ---- packages -------------------------------------------------------------------------------------

    public function test_the_package_list_and_page_show_only_active_public_packages(): void
    {
        $list = $this->get('/travel-packages')->getBody();
        self::assertStringContainsString('Bali Escape', $list);
        foreach (['draftpkg', 'privatepkg', 'archived'] as $k) {
            self::assertStringNotContainsString($this->slugs['pkg:' . $k], $list, $k);
            self::assertSame(404, $this->code('/travel-packages/' . $this->slugs['pkg:' . $k]), $k);
        }

        $page = $this->get('/travel-packages/' . $this->slugs['pkg:bali']);
        $html = $page->getBody();
        self::assertSame(200, $page->getStatus());
        self::assertStringContainsString('5 days / 4 nights', $html);
        self::assertStringContainsString('INR 45,000', $html);
        self::assertStringContainsString('Arrival in Denpasar', $html);
        preg_match('#<script type="application/ld\+json">(\{"@context":"https://schema.org","@type":"TouristTrip".*?)</script>#s', $html, $m);
        $ld = json_decode($m[1] ?? '', true);
        self::assertSame('Bali Escape', $ld['name']);
        self::assertEquals(45000, $ld['offers']['price']);
    }

    public function test_a_package_enquiry_is_stored_against_the_package(): void
    {
        $res = $this->post('/travel-packages/' . $this->slugs['pkg:bali'] . '/enquire', ['name' => 'PS Traveller', 'phone' => '9876543211', 'message' => '2 adults, December']);

        self::assertSame(302, $res->getStatus());
        $row = $this->db->selectOne("SELECT * FROM public_enquiries WHERE name = 'PS Traveller'");
        self::assertSame('travel_enquiry', $row['type']);
        self::assertSame($this->packages['bali'], (int) $row['tour_package_id']);
        self::assertNull($row['job_id']);
    }

    // ---- SEO -------------------------------------------------------------------------------------------

    public function test_the_sitemap_lists_visible_jobs_and_packages_only(): void
    {
        $xml = $this->get('/sitemap.xml')->getBody();

        self::assertStringContainsString('/overseas-jobs/' . $this->slugs['visible'], $xml);
        self::assertStringContainsString('/travel-packages/' . $this->slugs['pkg:bali'], $xml);
        foreach (['draft', 'private', 'closed', 'expired', 'deleted'] as $k) {
            self::assertStringNotContainsString($this->slugs[$k], $xml, $k);
        }
        self::assertStringNotContainsString($this->slugs['pkg:draftpkg'], $xml);
        self::assertNotFalse(simplexml_load_string($xml), 'the sitemap is well-formed XML');
        self::assertMatchesRegularExpression('#<loc>[^<]*/overseas-jobs</loc>#', $xml);
    }

    public function test_the_public_catalogue_pages_pass_the_accessibility_and_seo_audit(): void
    {
        $audit = new \App\Support\HtmlAudit();
        $pages = [
            '/' => false, '/overseas-jobs' => false, '/overseas-jobs/' . $this->slugs['visible'] => false, '/travel-packages' => false, '/travel-packages/' . $this->slugs['pkg:bali'] => false,
            '/overseas-jobs/' . $this->slugs['visible'] . '/apply' => true, '/travel-packages/' . $this->slugs['pkg:bali'] . '/enquire' => true,
        ];

        foreach ($pages as $uri => $formPage) {
            $errors = array_filter($audit->check($this->get($uri)->getBody(), 'public'), static fn (array $f): bool => $f['severity'] === \App\Support\HtmlAudit::ERROR);
            // The apply / enquire form pages are deliberately noindex.
            $errors = array_filter($errors, static fn (array $f): bool => !($formPage && $f['rule'] === 'seo-noindex'));

            self::assertSame([], array_values($errors), $uri);
        }
    }

    public function test_the_home_page_features_the_latest_jobs_and_packages(): void
    {
        $html = $this->get('/')->getBody();

        self::assertStringContainsString('Latest overseas jobs', $html);
        self::assertStringContainsString('Site Electrician', $html);
        self::assertStringContainsString('Bali Escape', $html);
    }
}
