<?php

declare(strict_types=1);

namespace App\View;

/**
 * Resolves a logical asset name ("app.css") to its built, content-hashed URL
 * ("/assets/build/app.1a2b3c4d5e.css") via public/assets/build/manifest.json.
 *
 * Falls back to the un-hashed path (with a mtime query string) when no manifest
 * exists yet — so local dev works before `npm run build`.
 */
final class Assets
{
    /** @var array<string,string>|null */
    private ?array $manifest = null;

    public function __construct(
        private readonly string $publicPath,
        private readonly string $buildDir = 'assets/build',
    ) {
    }

    public function url(string $key): string
    {
        $manifest = $this->manifest();
        $base = '/' . trim($this->buildDir, '/') . '/';

        if (isset($manifest[$key])) {
            return $base . $manifest[$key];
        }

        $path = $this->publicPath . '/' . trim($this->buildDir, '/') . '/' . $key;
        $version = is_file($path) ? substr((string) filemtime($path), -6) : 'dev';

        return $base . $key . '?v=' . $version;
    }

    public function exists(string $key): bool
    {
        return isset($this->manifest()[$key])
            || is_file($this->publicPath . '/' . trim($this->buildDir, '/') . '/' . $key);
    }

    /** @return array<string,string> */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $file = $this->publicPath . '/' . trim($this->buildDir, '/') . '/manifest.json';
        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            $this->manifest = is_array($decoded) ? $decoded : [];
        } else {
            $this->manifest = [];
        }

        return $this->manifest;
    }
}
