<?php

declare(strict_types=1);

namespace App\View;

/**
 * Minimal PHP-template view engine. Templates live in resources/views as
 * `name.php` (dots = directory separators). Output is NOT auto-escaped — call
 * e()/e_attr()/e_url() explicitly (enforced by code review).
 *
 * Layout usage inside a template:
 *   <?php $this->layout('layouts.app', ['title' => 'Leads']) ?>
 *   <?php $this->start('content') ?> ... <?php $this->stop() ?>
 * And in the layout: <?= $this->yield('content') ?>
 *
 * Step 1.8 adds the component partials + Tailwind layout on top of this.
 */
final class View
{
    /** @var array<string,string> */
    private array $sections = [];

    /** @var list<string> */
    private array $sectionStack = [];

    private ?string $layoutName = null;

    /** @var array<string,mixed> */
    private array $layoutData = [];

    /** @var array<string,mixed> */
    private array $shared = [];

    public function __construct(private readonly string $viewPath)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string,mixed> $data */
    public function render(string $name, array $data = []): string
    {
        $content = $this->renderFile($this->resolve($name), array_merge($this->shared, $data));

        if ($this->layoutName !== null) {
            $layout = $this->layoutName;
            $layoutData = $this->layoutData;
            $this->layoutName = null;
            $this->layoutData = [];

            // The default section is the child's whole output.
            $this->sections['content'] ??= $content;

            return $this->renderFile(
                $this->resolve($layout),
                array_merge($this->shared, $layoutData, $data),
            );
        }

        return $content;
    }

    public function exists(string $name): bool
    {
        return is_file($this->resolve($name));
    }

    // ---- Template-facing API --------------------------------------

    /** @param array<string,mixed> $data */
    public function layout(string $name, array $data = []): void
    {
        $this->layoutName = $name;
        $this->layoutData = $data;
    }

    public function start(string $section): void
    {
        $this->sectionStack[] = $section;
        ob_start();
    }

    public function stop(): void
    {
        $section = array_pop($this->sectionStack);
        if ($section === null) {
            throw new \LogicException('start()/stop() mismatch in view.');
        }
        $this->sections[$section] = ob_get_clean() ?: '';
    }

    public function yield(string $section, string $default = ''): string
    {
        return $this->sections[$section] ?? $default;
    }

    public function hasSection(string $section): bool
    {
        return isset($this->sections[$section]);
    }

    /** @param array<string,mixed> $data */
    public function partial(string $name, array $data = []): string
    {
        return $this->renderFile($this->resolve($name), array_merge($this->shared, $data));
    }

    // ---- Internals ------------------------------------------------

    private function resolve(string $name): string
    {
        $relative = str_replace('.', '/', $name);

        return rtrim($this->viewPath, '/\\') . '/' . $relative . '.php';
    }

    /** @param array<string,mixed> $data */
    private function renderFile(string $file, array $data): string
    {
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$file}");
        }

        $render = function (string $__file, array $__data): void {
            extract($__data, EXTR_SKIP);
            require $__file;
        };

        ob_start();
        try {
            // Bind $this so templates can call $this->layout()/start()/stop()/yield()/partial().
            $render->call($this, $file, $data);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
