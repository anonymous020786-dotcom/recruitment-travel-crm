<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Config;
use App\View\Assets;
use App\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

/**
 * Every component partial renders without error and escapes untrusted props.
 * Runs against the real resources/views so component regressions are caught.
 */
final class ComponentRenderTest extends TestCase
{
    private View $view;

    protected function setUp(): void
    {
        $app = TestApp::make();
        $app->instance(Assets::class, new Assets(sys_get_temp_dir() . '/nope'));
        $this->view = new View(TEST_ROOT . '/resources/views');
        $app->instance(View::class, $this->view);
        $app->instance(Config::class, $app->config());
    }

    private function c(string $name, array $props = []): string
    {
        return $this->view->partial("components.{$name}", $props);
    }

    public function test_button_variants_and_href(): void
    {
        self::assertStringContainsString('btn btn-primary', $this->c('button', ['label' => 'Save']));
        self::assertStringContainsString('<a ', $this->c('button', ['label' => 'Go', 'href' => '/leads']));
        self::assertStringContainsString('btn-danger', $this->c('button', ['label' => 'x', 'variant' => 'danger']));
    }

    public function test_button_escapes_label(): void
    {
        $html = $this->c('button', ['label' => '<script>alert(1)</script>']);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_button_confirm_href_is_sanitised(): void
    {
        $html = $this->c('button', ['label' => 'x', 'href' => 'javascript:alert(1)']);
        self::assertStringContainsString('href="#"', $html);
    }

    public function test_alert_renders_each_type(): void
    {
        foreach (['info', 'success', 'warning', 'danger'] as $type) {
            $html = $this->c('alert', ['type' => $type, 'message' => "msg-{$type}"]);
            self::assertStringContainsString("alert-{$type}", $html);
            self::assertStringContainsString("msg-{$type}", $html);
        }
    }

    public function test_badge_colour_and_escaping(): void
    {
        $html = $this->c('badge', ['label' => '<b>Open</b>', 'color' => 'green', 'dot' => true]);
        self::assertStringContainsString('bg-green-50', $html);
        self::assertStringContainsString('&lt;b&gt;Open', $html);
    }

    public function test_field_renders_input_select_textarea_and_error(): void
    {
        self::assertStringContainsString('<input', $this->c('field', ['name' => 'x', 'label' => 'X']));
        self::assertStringContainsString('<textarea', $this->c('field', ['name' => 'x', 'control' => 'textarea']));
        $sel = $this->c('field', ['name' => 'x', 'control' => 'select', 'options' => ['a' => 'Apple']]);
        self::assertStringContainsString('<option value="a"', $sel);
    }

    public function test_empty_state_and_card_and_page_header(): void
    {
        self::assertStringContainsString('No leads', $this->c('empty-state', ['title' => 'No leads']));
        self::assertStringContainsString('card', $this->c('card', ['title' => 'T', 'body' => 'B']));
        $ph = $this->c('page-header', [
            'title' => 'Leads',
            'breadcrumbs' => [['label' => 'Home', 'href' => '/dashboard'], ['label' => 'Leads']],
        ]);
        self::assertStringContainsString('<h1', $ph);
        self::assertStringContainsString('aria-current="page"', $ph);
    }

    public function test_pagination_math(): void
    {
        $html = $this->c('pagination', ['page' => 2, 'perPage' => 25, 'total' => 130, 'baseUrl' => '/leads']);
        self::assertStringContainsString('26', $html);   // from
        self::assertStringContainsString('50', $html);   // to
        self::assertStringContainsString('of <span class="font-medium text-slate-700">130', $html);
        self::assertStringContainsString('page=3', $html);
    }

    public function test_icon_falls_back_gracefully(): void
    {
        self::assertStringContainsString('<svg', $this->c('icon', ['name' => 'does-not-exist']));
    }

    public function test_stat(): void
    {
        $html = $this->c('stat', ['label' => 'Active', 'value' => 42, 'href' => '/candidates']);
        self::assertStringContainsString('<a', $html);
        self::assertStringContainsString('42', $html);
    }
}
