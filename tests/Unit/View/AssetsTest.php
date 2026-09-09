<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use App\View\Assets;
use PHPUnit\Framework\TestCase;

final class AssetsTest extends TestCase
{
    private string $pub;

    protected function setUp(): void
    {
        $this->pub = sys_get_temp_dir() . '/crm_pub_' . bin2hex(random_bytes(4));
        mkdir($this->pub . '/assets/build', 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->pub . '/assets/build/*') ?: []);
        @rmdir($this->pub . '/assets/build');
        @rmdir($this->pub . '/assets');
        @rmdir($this->pub);
    }

    public function test_resolves_hashed_name_from_manifest(): void
    {
        file_put_contents(
            $this->pub . '/assets/build/manifest.json',
            json_encode(['app.css' => 'app.deadbeef01.css', 'app.js' => 'app.cafe0001aa.js']),
        );

        $assets = new Assets($this->pub);
        self::assertSame('/assets/build/app.deadbeef01.css', $assets->url('app.css'));
        self::assertSame('/assets/build/app.cafe0001aa.js', $assets->url('app.js'));
    }

    public function test_falls_back_to_mtime_query_without_manifest(): void
    {
        file_put_contents($this->pub . '/assets/build/app.css', 'body{}');
        $url = (new Assets($this->pub))->url('app.css');
        self::assertStringStartsWith('/assets/build/app.css?v=', $url);
    }

    public function test_falls_back_to_dev_when_file_absent(): void
    {
        self::assertSame('/assets/build/app.css?v=dev', (new Assets($this->pub))->url('app.css'));
    }
}
