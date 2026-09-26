<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Auth;
use App\Auth\Gate;
use App\Auth\PermissionService;
use App\Cms\MediaLibrary;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\CmsPageService;
use App\Session\ArraySessionStore;
use App\Session\SessionStore;
use App\Support\Hash;
use App\Support\ImageOptimizer;
use App\Support\Ulid;
use Tests\Support\DbTestCase;

/** Admin → Pages → Media: every upload is re-encoded, resized, de-duplicated and stored under a random name. */
final class MediaLibraryTest extends DbTestCase
{
    private Router $router;
    private ArraySessionStore $store;
    private string $sid = '';
    private string $token = '';
    private int $branch;
    private string $root;
    private string $tmp;
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];
    private ?User $publisher = null;
    private ?User $writer = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ImageOptimizer::gdAvailable()) {
            self::markTestSkipped('GD is needed (php -d extension=gd).');
        }
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->root = sys_get_temp_dir() . '/crm-media-' . bin2hex(random_bytes(4));
        $this->tmp = sys_get_temp_dir() . '/crm-media-up-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        mkdir($this->tmp);
        $this->app->config()->set('cms.media.root', $this->root);
        $this->app->config()->set('cms.media.max_kb', 8192);
        $this->branch = (int) $this->db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => 'Media branch', 'code' => 'MDX-' . bin2hex(random_bytes(2))]);
        $this->store = new ArraySessionStore();
        $this->app->instance(SessionStore::class, $this->store);
        $this->router = new Router($this->app);
        (require TEST_ROOT . '/routes/web.php')($this->router);
        $this->router->finalizeNames();
        $this->app->instance(Router::class, $this->router);
    }

    protected function tearDown(): void
    {
        // by pattern, so a run that crashed half-way never leaves rows that block the next one
        $users = "SELECT id FROM (SELECT id FROM users WHERE email LIKE 'tmedia\_%') x";
        $this->db->affectingStatement("DELETE FROM cms_media WHERE uploaded_by IN ({$users})");
        $this->db->affectingStatement("DELETE FROM cms_pages WHERE author_id IN ({$users})");
        $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$users})");
        $this->db->affectingStatement("DELETE FROM users WHERE email LIKE 'tmedia\_%'");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module = 'cms'");
        $this->db->affectingStatement('DELETE FROM branches WHERE code LIKE ?', ['MDX-%']);
        foreach ([$this->root ?? '', $this->tmp ?? ''] as $dir) {
            if ($dir !== '' && is_dir($dir)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $f) {
                    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
                }
                rmdir($dir);
            }
        }
    }

    // ---- helpers ---------------------------------------------------------------------------------------------------

    private function user(string $role): int
    {
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "TMEDIA {$role}", 'email' => 'tmedia_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $this->branch, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $this->branch]);
        $this->userIds[] = $id;

        return $id;
    }

    private function publisher(): User
    {
        return $this->publisher ??= $this->app->get(UserRepository::class)->findById($this->user('admin'));
    }

    private function writer(): User
    {
        return $this->writer ??= $this->app->get(UserRepository::class)->findById($this->user('manager'));
    }

    private function lib(): MediaLibrary
    {
        return $this->app->get(MediaLibrary::class);
    }

    /** A real image file of the given type and size, with a unique colour so each one hashes differently. */
    private function image(string $type, int $w = 120, int $h = 80, string $append = ''): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        imagesetpixel($img, 1, 1, imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        $path = $this->tmp . '/' . bin2hex(random_bytes(4)) . '.' . $type;
        match ($type) {
            'png' => imagepng($img, $path),
            'jpg' => imagejpeg($img, $path, 90),
            'gif' => imagegif($img, $path),
            'webp' => imagewebp($img, $path),
        };
        imagedestroy($img);
        if ($append !== '') {
            file_put_contents($path, $append, FILE_APPEND);
        }

        return $path;
    }

    /** @return array{name:string,tmp_name:string,size:int,error:int} */
    private function file(string $path, string $name = 'photo.png'): array
    {
        return ['name' => $name, 'tmp_name' => $path, 'size' => (int) filesize($path), 'error' => UPLOAD_ERR_OK, 'type' => 'image/png'];
    }

    private function errorsOf(callable $do, string $label = ''): array
    {
        try {
            $do();
        } catch (ValidationException $e) {
            return $e->errors();
        }
        self::fail("a validation error was expected {$label}");
    }

    private function actAs(int $userId): void
    {
        $this->sid = bin2hex(random_bytes(32));
        $this->token = bin2hex(random_bytes(32));
        $this->store->sessions[$this->sid] = ['data' => ['_auth_user_id' => $userId, '_auth_at' => time(), '_authenticated_at' => time(), '_token' => $this->token, '_started_at' => time(), '_last_regen' => time(), '_last_activity' => time()], 'touched' => time()];
        $auth = new Auth($this->app, new UserRepository($this->db));
        $this->app->instance(Auth::class, $auth);
        $this->app->instance(Gate::class, new Gate($this->app, $this->app->get(PermissionService::class), $auth));
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $files */
    private function send(string $method, string $uri, array $post = [], array $files = []): Response
    {
        if ($post !== [] && !isset($post['_token'])) {
            $post['_token'] = $this->token;
        }
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return $this->router->dispatch(new Request($query, $post, ['crm_session' => $this->sid], $files, [
            'REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'http://localhost',
        ], ''));
    }

    /** @param array<string,mixed> $post */
    private function code(string $method, string $uri, array $post = [], array $files = []): int
    {
        try {
            return $this->send($method, $uri, $post, $files)->getStatus();
        } catch (AuthorizationException) {
            return 403;
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }

    // ---- uploads ---------------------------------------------------------------------------------------------------

    public function test_an_upload_is_stored_under_a_random_name_with_a_webp_copy_and_a_thumbnail(): void
    {
        $r = $this->lib()->upload($this->file($this->image('png', 900, 600), 'Office photo.png'), 'Our office', $this->writer());
        self::assertFalse($r['reused']);
        $m = $r['media'];
        self::assertMatchesRegularExpression('#^\d{4}/\d{2}/[a-z0-9]{26}\.png$#', $m['path']);
        self::assertMatchesRegularExpression('#\.webp$#', (string) $m['webp_path']);
        self::assertMatchesRegularExpression('#-thumb\.(webp|jpg)$#', (string) $m['thumb_path']);
        foreach (['path', 'webp_path', 'thumb_path'] as $c) {
            self::assertFileExists($this->root . '/' . $m[$c], $c);
        }
        self::assertSame('/media/' . $m['path'], $m['url']);
        self::assertSame(900, (int) $m['width']);
        self::assertSame('Office photo.png', $m['original_name']);
        self::assertSame('Our office', $m['alt_text']);
        [$tw, $th] = getimagesize($this->root . '/' . $m['thumb_path']);
        self::assertSame(400, max($tw, $th));
        self::assertSame(1, (int) $this->db->selectValue("SELECT COUNT(*) FROM activity_logs WHERE action = 'cms_media_uploaded'"));
    }

    public function test_hidden_payloads_are_stripped_by_re_encoding(): void
    {
        foreach (['png', 'jpg', 'gif'] as $type) {
            $path = $this->image($type, 60, 60, '<?php system($_GET["c"]); ?><script>alert(1)</script>');
            self::assertStringContainsString('<?php', (string) file_get_contents($path));
            $m = $this->lib()->upload($this->file($path, "x.{$type}"), '', $this->writer())['media'];
            foreach (['path', 'webp_path', 'thumb_path'] as $c) {
                $stored = (string) file_get_contents($this->root . '/' . $m[$c]);
                self::assertStringNotContainsString('<?php', $stored, "{$type} {$c}");
                self::assertStringNotContainsString('<script', $stored, "{$type} {$c}");
            }
        }
        $gif = $this->lib()->upload($this->file($this->image('gif'), 'anim.gif'), '', $this->writer())['media'];
        self::assertStringEndsWith('.png', $gif['path'], 'GIFs are stored as PNG');
        self::assertSame('image/png', $gif['mime']);
    }

    public function test_large_images_are_scaled_down(): void
    {
        $m = $this->lib()->upload($this->file($this->image('jpg', 3000, 1000), 'wide.jpg'), '', $this->writer())['media'];
        self::assertSame(2560, (int) $m['width']);
        self::assertSame(853, (int) $m['height']);
        self::assertSame([2560, 853], array_slice(getimagesize($this->root . '/' . $m['path']), 0, 2));
    }

    public function test_the_same_file_twice_is_stored_once(): void
    {
        $path = $this->image('png');
        $a = $this->lib()->upload($this->file($path, 'a.png'), '', $this->writer());
        $b = $this->lib()->upload($this->file($path, 'b.png'), '', $this->writer());
        self::assertTrue($b['reused']);
        self::assertSame($a['media']['public_id'], $b['media']['public_id']);
        self::assertSame(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM cms_media WHERE sha256 = :s', ['s' => hash_file('sha256', $path)]));
    }

    public function test_anything_that_is_not_a_safe_raster_image_is_refused(): void
    {
        $cases = [];
        $svg = $this->tmp . '/x.svg';
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><circle r="5"/></svg>');
        $cases['svg'] = $this->file($svg, 'x.svg');
        $php = $this->tmp . '/shell.jpg';
        file_put_contents($php, '<?php echo 1; ?>');
        $cases['php named .jpg'] = $this->file($php, 'shell.jpg');
        $html = $this->tmp . '/page.png';
        file_put_contents($html, '<html><script>alert(1)</script></html>');
        $cases['html named .png'] = $this->file($html, 'page.png');
        $pdf = $this->tmp . '/doc.png';
        file_put_contents($pdf, "%PDF-1.4\n%fake");
        $cases['pdf'] = $this->file($pdf, 'doc.png');
        $truncated = $this->tmp . '/cut.png';
        file_put_contents($truncated, substr((string) file_get_contents($this->image('png')), 0, 40));
        $cases['truncated png'] = $this->file($truncated, 'cut.png');
        $cases['no file'] = ['name' => '', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_NO_FILE];
        $cases['php ini limit'] = ['name' => 'big.png', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_INI_SIZE];
        $cases['missing tmp'] = ['name' => 'x.png', 'tmp_name' => $this->tmp . '/nope.png', 'size' => 1, 'error' => UPLOAD_ERR_OK];

        foreach ($cases as $label => $file) {
            self::assertArrayHasKey('file', $this->errorsOf(fn () => $this->lib()->upload($file, '', $this->writer()), $label));
        }
        self::assertSame(0, (int) $this->db->selectValue('SELECT COUNT(*) FROM cms_media WHERE uploaded_by = :u', ['u' => $this->writer()->id]));
        self::assertSame([], glob($this->root . '/*/*/*') ?: [], 'nothing was written');
    }

    public function test_size_and_pixel_limits_are_checked_before_decoding(): void
    {
        $this->app->config()->set('cms.media.max_kb', 1);
        self::assertArrayHasKey('file', $this->errorsOf(fn () => $this->lib()->upload($this->file($this->image('png', 200, 200, str_repeat('x', 3000))), '', $this->writer())));
        $this->app->config()->set('cms.media.max_kb', 8192);

        // a tiny PNG whose header claims 30000×30000 pixels (a decompression bomb)
        $png = (string) file_get_contents($this->image('png', 10, 10));
        $bomb = substr($png, 0, 16) . pack('N', 30000) . pack('N', 30000) . substr($png, 24);
        $path = $this->tmp . '/bomb.png';
        file_put_contents($path, $bomb);
        $errors = $this->errorsOf(fn () => $this->lib()->upload($this->file($path, 'bomb.png'), '', $this->writer()));
        self::assertStringContainsString('too many pixels', $errors['file'][0]);
    }

    public function test_the_uploaded_name_never_reaches_a_path_and_descriptions_are_checked(): void
    {
        $m = $this->lib()->upload($this->file($this->image('png'), '../../etc/<script>pass wd".png'), '', $this->writer())['media'];
        self::assertSame('scriptpass wd.png', $m['original_name']);
        self::assertStringNotContainsString('..', $m['path']);
        self::assertArrayHasKey('alt', $this->errorsOf(fn () => $this->lib()->upload($this->file($this->image('png')), "two\nlines", $this->writer())));
        self::assertArrayHasKey('alt', $this->errorsOf(fn () => $this->lib()->upload($this->file($this->image('png')), str_repeat('a', 201), $this->writer())));

        $this->lib()->describe($m['public_id'], 'New alt', 'A title', $this->writer());
        self::assertSame('New alt', $this->lib()->find($m['public_id'])['alt_text']);
        self::assertArrayHasKey('title', $this->errorsOf(fn () => $this->lib()->describe($m['public_id'], '', str_repeat('t', 121), $this->writer())));
    }

    // ---- deleting ---------------------------------------------------------------------------------------------------

    public function test_a_file_in_use_cannot_be_deleted_and_deleting_removes_every_copy(): void
    {
        $m = $this->lib()->upload($this->file($this->image('jpg'), 'hero.jpg'), 'Hero', $this->writer())['media'];
        $pages = $this->app->get(CmsPageService::class);
        $pageId = $pages->create(['title' => 'TMEDIA page', 'path' => 'tmedia-page', 'body' => 'Look: ![Hero](' . $m['url'] . ')'], $this->publisher());
        self::assertNotSame([], $this->lib()->usage($m));
        try {
            $this->lib()->delete($m['public_id'], $this->publisher());
            self::fail('in use');
        } catch (DomainRuleException $e) {
            self::assertStringContainsString('tmedia-page', $e->getMessage());
        }
        try {
            $this->lib()->delete($m['public_id'], $this->writer());
            self::fail('writers cannot delete');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
        $pages->trash($pageId, $this->publisher());
        $this->lib()->delete($m['public_id'], $this->publisher());
        self::assertNull($this->lib()->find($m['public_id']));
        foreach (['path', 'webp_path', 'thumb_path'] as $c) {
            self::assertFileDoesNotExist($this->root . '/' . $m[$c], $c);
        }
        try {
            $this->lib()->delete($m['public_id'], $this->publisher());
            self::fail();
        } catch (DomainRuleException $e) {
            self::assertSame(404, $e->httpStatus());
        }
    }

    // ---- the screen -------------------------------------------------------------------------------------------------

    public function test_the_media_screen_uploads_lists_describes_and_deletes(): void
    {
        $this->actAs($this->user('read_only'));
        self::assertSame(403, $this->code('GET', '/admin/cms/media'));

        $this->actAs($this->writer()->id);
        $res = $this->send('POST', '/admin/cms/media', ['alt' => 'Beach'], ['file' => $this->file($this->image('png', 300, 200), 'beach.png')]);
        $location = (string) $res->getHeader('Location');
        self::assertMatchesRegularExpression('#^/admin/cms/media\?file=[0-9A-Z]{26}$#', $location);
        $id = substr($location, strlen('/admin/cms/media?file='));
        $page = $this->send('GET', $location)->getBody();
        self::assertStringContainsString('beach.png', $page);
        self::assertStringContainsString('![Beach](/media/', $page);
        self::assertStringNotContainsString('>Delete<', $page, 'writers do not see delete');

        $bad = $this->send('POST', '/admin/cms/media', ['alt' => ''], []);
        self::assertSame('/admin/cms/media', $bad->getHeader('Location'));

        $this->send('PUT', "/admin/cms/media/{$id}", ['_method' => 'PUT', 'alt' => 'Sunny beach', 'title' => 'Beach']);
        self::assertSame('Sunny beach', $this->lib()->find($id)['alt_text']);
        self::assertSame(403, $this->code('POST', "/admin/cms/media/{$id}/delete", ['x' => '1']));

        $this->actAs($this->publisher()->id);
        self::assertStringContainsString('>Delete<', $this->send('GET', $location)->getBody());
        self::assertStringContainsString('beach.png', $this->send('GET', '/admin/cms/media?q=beach')->getBody());
        $this->send('POST', "/admin/cms/media/{$id}/delete", ['x' => '1']);
        self::assertNull($this->lib()->find($id));
        self::assertSame(404, $this->code('POST', '/admin/cms/media/NOSUCHFILE0000000000000000/delete', ['x' => '1']));
    }
}
