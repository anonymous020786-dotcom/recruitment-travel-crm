<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Shrinks JPEG, PNG and WebP files with GD, in place and only when it actually helps.
 *
 *  - JPEG: re-encoded at the chosen quality as a progressive file; EXIF/ICC/thumbnail data is dropped (GD does not copy it),
 *    after the camera's rotation flag has been applied to the pixels so a phone photo does not turn sideways. A file whose
 *    orientation involves mirroring is left alone rather than risk it;
 *  - PNG: maximum deflate compression with transparency kept; with `lossy` an opaque truecolor PNG is reduced to a 256-colour
 *    palette (a big saving for logos and flat artwork, visible on photographs — so it is opt-in);
 *  - WebP: re-encoded at the chosen quality;
 *  - `maxWidth` scales larger images down (never up);
 *  - `webp` also writes a `.webp` sibling (name.jpg → name.webp) when it is at least 10% smaller than the optimised original,
 *    for templates or the web server to prefer;
 *  - a file is only replaced when the result is at least 3% smaller, and the replacement is atomic (write a temp file, rename).
 *
 * Inputs are checked before they are decoded: it must be a real image of a supported type (by content, not extension), the
 * dimensions must be sane and the decode must fit in memory — a "decompression bomb" is skipped, not attempted.
 */
final class ImageOptimizer
{
    public const MIN_GAIN = 0.03;
    private const MAX_SIDE = 12000;
    private const MAX_PIXELS = 40_000_000;

    public function __construct(
        private readonly int $jpegQuality = 82,
        private readonly int $webpQuality = 80,
        private readonly ?int $maxWidth = null,
        private readonly bool $lossy = false,
        private readonly bool $webp = false,
    ) {
    }

    public static function gdAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromjpeg') && function_exists('imagecreatefrompng');
    }

    /**
     * @return array{path:string,type:string,before:int,after:int,written:bool,resized:bool,webp:?int,note:string}
     */
    public function optimize(string $path, bool $dryRun = false): array
    {
        $result = ['path' => $path, 'type' => '', 'before' => 0, 'after' => 0, 'written' => false, 'resized' => false, 'webp' => null, 'note' => ''];
        if (!self::gdAvailable()) {
            throw new \RuntimeException('The GD extension is not available (php -d extension=gd …).');
        }
        if (is_link($path) || !is_file($path)) {
            return ['note' => 'not a regular file'] + $result;
        }
        $before = (int) filesize($path);
        $result['before'] = $result['after'] = $before;

        $info = @getimagesize($path);
        if ($info === false) {
            return ['note' => 'not an image'] + $result;
        }
        [$w, $h, $type] = [$info[0], $info[1], $info[2]];
        $result['type'] = match ($type) { IMAGETYPE_JPEG => 'jpeg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', default => '' };
        if ($result['type'] === '') {
            return ['note' => 'unsupported type (only JPEG, PNG and WebP)'] + $result;
        }
        if ($w < 1 || $h < 1 || $w > self::MAX_SIDE || $h > self::MAX_SIDE || $w * $h > self::MAX_PIXELS) {
            return ['note' => "too large to decode safely ({$w}×{$h})"] + $result;
        }

        $bytes = (string) file_get_contents($path);
        $orientation = $type === IMAGETYPE_JPEG ? self::exifOrientation($bytes) : 1;
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            return ['note' => 'mirrored EXIF orientation — left alone'] + $result;
        }

        $im = @imagecreatefromstring($bytes);
        if ($im === false) {
            return ['note' => 'could not be decoded'] + $result;
        }

        $im = $this->orient($im, $orientation);
        $im = $this->shrink($im, $result['resized']);
        $hasAlpha = $type !== IMAGETYPE_JPEG && $this->usesAlpha($im);

        $encoded = $this->encode($im, $result['type'], $hasAlpha);
        $rotated = $orientation !== 1;
        $smaller = $encoded !== '' && strlen($encoded) <= $before * (1 - self::MIN_GAIN);
        // a resize or a rotation is a deliberate change: keep it even when the byte gain is small, as long as it is not bigger
        $keep = $smaller || (($result['resized'] || $rotated) && $encoded !== '' && strlen($encoded) < $before);

        if ($keep) {
            $result['after'] = strlen($encoded);
            $result['written'] = !$dryRun;
            if (!$dryRun) {
                self::replace($path, $encoded);
            }
        } else {
            $result['note'] = 'already optimal';
            $encoded = $bytes;
        }

        if ($this->webp && $result['type'] !== 'webp' && function_exists('imagewebp')) {
            $webp = $this->encode($im, 'webp', $hasAlpha);
            $sibling = preg_replace('/\.[^.\/\\\\]+$/', '', $path) . '.webp';
            if ($webp !== '' && strlen($webp) <= strlen($encoded) * 0.9) {
                $result['webp'] = strlen($webp);
                if (!$dryRun) {
                    self::replace($sibling, $webp);
                }
            }
        }

        return $result;
    }

    private function encode(\GdImage $im, string $type, bool $alpha): string
    {
        ob_start();
        try {
            switch ($type) {
                case 'jpeg':
                    imageinterlace($im, true);
                    imagejpeg($im, null, max(30, min(95, $this->jpegQuality)));
                    break;
                case 'png':
                    imagesavealpha($im, true);
                    imagepng($im, null, 9);
                    if ($this->lossy && !$alpha && imageistruecolor($im)) {
                        // offer the 256-colour palette version too and keep whichever file is smaller
                        $lossless = (string) ob_get_clean();
                        ob_start();
                        $copy = imagecreatetruecolor(imagesx($im), imagesy($im));
                        imagecopy($copy, $im, 0, 0, 0, 0, imagesx($im), imagesy($im));
                        imagetruecolortopalette($copy, false, 256);
                        imagepng($copy, null, 9);
                        $palette = (string) ob_get_clean();
                        ob_start();
                        echo strlen($palette) < strlen($lossless) ? $palette : $lossless;
                    }
                    break;
                case 'webp':
                    if (!imageistruecolor($im)) {
                        imagepalettetotruecolor($im);
                    }
                    imagesavealpha($im, true);
                    imagewebp($im, null, max(30, min(95, $this->webpQuality)));
                    break;
            }
        } finally {
            $data = (string) ob_get_clean();
        }

        return $data;
    }

    private function shrink(\GdImage $im, bool &$resized): \GdImage
    {
        $w = imagesx($im);
        if ($this->maxWidth === null || $this->maxWidth < 16 || $w <= $this->maxWidth) {
            return $im;
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $scaled = imagescale($im, $this->maxWidth, -1, IMG_BICUBIC);
        if ($scaled === false) {
            return $im;
        }
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        $resized = true;

        return $scaled;
    }

    private function orient(\GdImage $im, int $orientation): \GdImage
    {
        $degrees = match ($orientation) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
        if ($degrees === 0) {
            return $im;
        }
        $rotated = imagerotate($im, $degrees, 0);

        return $rotated === false ? $im : $rotated;
    }

    private function usesAlpha(\GdImage $im): bool
    {
        if (!imageistruecolor($im)) {
            return imagecolortransparent($im) >= 0;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        $stepX = max(1, intdiv($w, 64));
        $stepY = max(1, intdiv($h, 64));
        for ($y = 0; $y < $h; $y += $stepY) {
            for ($x = 0; $x < $w; $x += $stepX) {
                if ((imagecolorat($im, $x, $y) >> 24) & 0x7F) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function replace(string $path, string $data): void
    {
        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $data) === false) {
            @unlink($tmp);
            throw new \RuntimeException("Could not write {$tmp}");
        }
        if (is_file($path)) {
            @chmod($tmp, fileperms($path) & 0777);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Could not replace {$path}");
        }
    }

    /** The EXIF orientation flag (1–8) of a JPEG, or 1 when there is none. */
    public static function exifOrientation(string $jpeg): int
    {
        if (strlen($jpeg) < 12 || $jpeg[0] !== "\xFF" || $jpeg[1] !== "\xD8") {
            return 1;
        }
        $pos = 2;
        $len = strlen($jpeg);
        while ($pos + 4 <= $len && $jpeg[$pos] === "\xFF") {
            $marker = ord($jpeg[$pos + 1]);
            $size = (ord($jpeg[$pos + 2]) << 8) | ord($jpeg[$pos + 3]);
            if ($marker === 0xE1 && substr($jpeg, $pos + 4, 6) === "Exif\0\0") {
                $tiff = substr($jpeg, $pos + 10, $size - 8);

                return self::tiffOrientation($tiff);
            }
            if ($marker === 0xDA || $size < 2) {
                break;   // start of image data: no EXIF before it
            }
            $pos += 2 + $size;
        }

        return 1;
    }

    private static function tiffOrientation(string $t): int
    {
        if (strlen($t) < 12) {
            return 1;
        }
        $little = substr($t, 0, 2) === 'II';
        if (!$little && substr($t, 0, 2) !== 'MM') {
            return 1;
        }
        $u16 = static fn (int $o): int => $little ? unpack('v', substr($t, $o, 2))[1] : unpack('n', substr($t, $o, 2))[1];
        $u32 = static fn (int $o): int => $little ? unpack('V', substr($t, $o, 4))[1] : unpack('N', substr($t, $o, 4))[1];
        $ifd = $u32(4);
        if ($ifd + 2 > strlen($t)) {
            return 1;
        }
        $count = $u16($ifd);
        for ($i = 0; $i < $count && $ifd + 2 + ($i + 1) * 12 <= strlen($t); $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($u16($entry) === 0x0112) {
                $value = $u16($entry + 8);

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }
}
