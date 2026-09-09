<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use App\View\View;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crm_views_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/layouts', 0777, true);
        mkdir($this->dir . '/components', 0777, true);

        file_put_contents($this->dir . '/layouts/base.php',
            '<html><head><title><?= $title ?? "x" ?></title></head><body><?= $this->yield("content") ?></body></html>');
        file_put_contents($this->dir . '/page.php',
            '<?php $this->layout("layouts.base", ["title" => $heading]); $this->start("content"); ?>'
            . 'HELLO <?= htmlspecialchars($name) ?><?php $this->stop(); ?>');
        file_put_contents($this->dir . '/components/box.php', '<div class="box"><?= htmlspecialchars($text) ?></div>');
        file_put_contents($this->dir . '/plain.php', 'just <?= $x ?>');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    public function test_plain_render(): void
    {
        self::assertSame('just 42', (new View($this->dir))->render('plain', ['x' => 42]));
    }

    public function test_layout_and_sections(): void
    {
        $html = (new View($this->dir))->render('page', ['heading' => 'My Page', 'name' => 'Asha']);
        self::assertStringContainsString('<title>My Page</title>', $html);
        self::assertStringContainsString('HELLO Asha', $html);
        self::assertStringStartsWith('<html>', $html);
    }

    public function test_partial(): void
    {
        $out = (new View($this->dir))->partial('components.box', ['text' => 'x<y']);
        self::assertSame('<div class="box">x&lt;y</div>', $out);
    }

    public function test_missing_view_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new View($this->dir))->render('nope');
    }

    public function test_shared_data_available(): void
    {
        $v = new View($this->dir);
        $v->share('x', 'shared');
        self::assertSame('just shared', $v->render('plain'));
    }
}
