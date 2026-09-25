<?php

declare(strict_types=1);

/**
 * Image optimizer: shrinks JPEG / PNG / WebP files in place (only when it saves at least 3%).
 *
 *   php -d extension=gd scripts/optimize-images.php                      optimise everything under public/assets
 *   php -d extension=gd scripts/optimize-images.php public/assets/img    only that folder (or a single file)
 *   php -d extension=gd scripts/optimize-images.php --dry-run            show what would be saved, change nothing
 *
 * Options
 *   --quality=82      JPEG quality 30–95            --webp-quality=80   WebP quality 30–95
 *   --max-width=1600  scale larger images down (never up)
 *   --webp            also write a .webp next to each JPEG/PNG when it is at least 10% smaller
 *   --lossy           reduce opaque truecolor PNGs to a 256-colour palette (logos/flat art; visible on photos)
 *
 * Only paths inside this project are touched; symlinks are skipped. Run it before committing new artwork, and on the server
 * after uploading images if GD is enabled there. See docs/phase-13/STEP-13.12-minification-and-images.md.
 */

use App\Support\ImageOptimizer;

if (\PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (!ImageOptimizer::gdAvailable()) {
    fwrite(STDERR, "The GD extension is not loaded. Run: php -d extension=gd scripts/optimize-images.php (or enable extension=gd in php.ini).\n");
    exit(1);
}

$opts = ['dry' => false, 'webp' => false, 'lossy' => false, 'quality' => 82, 'webpq' => 80, 'max' => null];
$paths = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $opts['dry'] = true;
    } elseif ($arg === '--webp') {
        $opts['webp'] = true;
    } elseif ($arg === '--lossy') {
        $opts['lossy'] = true;
    } elseif (preg_match('/^--quality=(\d{2})$/', $arg, $m)) {
        $opts['quality'] = (int) $m[1];
    } elseif (preg_match('/^--webp-quality=(\d{2})$/', $arg, $m)) {
        $opts['webpq'] = (int) $m[1];
    } elseif (preg_match('/^--max-width=(\d{2,5})$/', $arg, $m)) {
        $opts['max'] = (int) $m[1];
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unknown option {$arg}\n");
        exit(2);
    } else {
        $paths[] = $arg;
    }
}
$paths = $paths === [] ? ['public/assets'] : $paths;

$files = [];
foreach ($paths as $p) {
    $full = realpath(str_starts_with($p, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $p) === 1 ? $p : $root . '/' . $p);
    if ($full === false || !str_starts_with(str_replace('\\', '/', $full) . '/', str_replace('\\', '/', $root) . '/')) {
        fwrite(STDERR, "Skipping {$p}: not found or outside the project.\n");
        continue;
    }
    if (is_file($full)) {
        $files[] = $full;
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && !$f->isLink() && preg_match('/\.(jpe?g|png|webp)$/i', $f->getFilename()) === 1 && !str_contains(str_replace('\\', '/', $f->getPathname()), '/build/')) {
            $files[] = $f->getPathname();
        }
    }
}
sort($files);
if ($files === []) {
    echo "No images found.\n";
    exit(0);
}

$optimizer = new ImageOptimizer($opts['quality'], $opts['webpq'], $opts['max'], $opts['lossy'], $opts['webp']);
$kb = static fn (int $n): string => number_format($n / 1024, 1) . ' KB';
$totalBefore = $totalAfter = $changed = 0;
echo ($opts['dry'] ? "DRY RUN — nothing is written\n" : '') . str_pad('file', 52) . str_pad('before', 12) . str_pad('after', 12) . "result\n";
foreach ($files as $file) {
    $r = $optimizer->optimize($file, $opts['dry']);
    $rel = ltrim(str_replace(str_replace('\\', '/', $root), '', str_replace('\\', '/', $file)), '/');
    $totalBefore += $r['before'];
    $totalAfter += $r['after'];
    $saved = $r['before'] > 0 ? (1 - $r['after'] / $r['before']) * 100 : 0;
    $note = $r['after'] < $r['before']
        ? sprintf('-%.0f%%%s', $saved, $r['resized'] ? ' (resized)' : '')
        : ($r['note'] !== '' ? $r['note'] : 'unchanged');
    if ($r['webp'] !== null) {
        $note .= ' + .webp ' . $kb($r['webp']);
    }
    $changed += $r['after'] < $r['before'] ? 1 : 0;
    echo str_pad(mb_substr($rel, -50), 52) . str_pad($kb($r['before']), 12) . str_pad($kb($r['after']), 12) . $note . "\n";
}
printf("\n%d file(s), %d %s. %s → %s (saved %s).\n", count($files), $changed, $opts['dry'] ? 'would change' : 'changed', $kb($totalBefore), $kb($totalAfter), $kb($totalBefore - $totalAfter));
