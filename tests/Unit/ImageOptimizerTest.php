<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ImageOptimizer;
use PHPUnit\Framework\TestCase;

/** The image optimizer (needs GD: run with `php -d extension=gd vendor/bin/phpunit`; skipped otherwise). */
final class ImageOptimizerTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        if (!ImageOptimizer::gdAvailable()) {
            self::markTestSkipped('GD is not loaded');
        }
        $this->dir = sys_get_temp_dir() . '/imgopt_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if ($this->dir === '') {
            return;   // skipped before setUp created it
        }
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /** A photo-like image: smooth gradient plus noise (compresses poorly at high quality). */
    private function photo(int $w = 400, int $h = 300): \GdImage
    {
        mt_srand(7);
        $im = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $n = mt_rand(-12, 12);
                imagesetpixel($im, $x, $y, imagecolorallocate($im, max(0, min(255, (int) ($x / $w * 255) + $n)), max(0, min(255, (int) ($y / $h * 255) + $n)), max(0, min(255, 128 + $n))));
            }
        }

        return $im;
    }

    private function jpeg(string $name, int $quality = 100, ?\GdImage $im = null): string
    {
        $path = "{$this->dir}/{$name}";
        imagejpeg($im ?? $this->photo(), $path, $quality);

        return $path;
    }

    public function test_a_high_quality_jpeg_is_shrunk_and_stays_a_valid_same_size_jpeg(): void
    {
        $path = $this->jpeg('a.jpg');
        $before = filesize($path);

        $r = (new ImageOptimizer(78))->optimize($path);

        self::assertTrue($r['written']);
        self::assertLessThan($before * 0.7, filesize($path));
        self::assertSame([400, 300, IMAGETYPE_JPEG], array_slice(getimagesize($path), 0, 3));
        self::assertSame($r['after'], filesize($path));
    }

    public function test_a_second_pass_finds_nothing_more_to_do_and_leaves_no_temp_files(): void
    {
        $path = $this->jpeg('a.jpg');
        $opt = new ImageOptimizer(78);
        $opt->optimize($path);
        $hash = md5_file($path);

        $again = $opt->optimize($path);

        self::assertFalse($again['written']);
        self::assertSame($hash, md5_file($path));
        self::assertSame(['a.jpg'], array_map('basename', glob($this->dir . '/*') ?: []));
    }

    public function test_dry_run_reports_the_saving_but_changes_nothing(): void
    {
        $path = $this->jpeg('a.jpg');
        $hash = md5_file($path);

        $r = (new ImageOptimizer(70))->optimize($path, dryRun: true);

        self::assertLessThan($r['before'], $r['after']);
        self::assertFalse($r['written']);
        self::assertSame($hash, md5_file($path));
    }

    public function test_png_transparency_is_preserved(): void
    {
        $im = imagecreatetruecolor(200, 200);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledellipse($im, 100, 100, 120, 120, imagecolorallocatealpha($im, 200, 30, 30, 40));
        $path = "{$this->dir}/a.png";
        imagepng($im, $path, 0);   // stored uncompressed: plenty to gain

        $r = (new ImageOptimizer(lossy: true))->optimize($path);   // lossy must not flatten an image that uses alpha

        self::assertTrue($r['written']);
        $out = imagecreatefrompng($path);
        self::assertSame(127, (imagecolorat($out, 2, 2) >> 24) & 0x7F, 'the corner is still fully transparent');
        self::assertGreaterThan(0, (imagecolorat($out, 100, 100) >> 24) & 0x7F, 'the ellipse is still translucent');
        self::assertTrue(imageistruecolor($out));
    }

    public function test_lossy_reduces_a_many_colour_opaque_png_to_a_palette_and_never_makes_a_file_bigger(): void
    {
        $noisy = "{$this->dir}/a.png";
        $flat = "{$this->dir}/b.png";
        imagepng($this->photo(300, 200), $noisy, 9);
        $im = imagecreatetruecolor(300, 200);
        imagefill($im, 0, 0, imagecolorallocate($im, 10, 120, 200));
        imagepng($im, $flat, 0);
        $lossless = "{$this->dir}/c.png";
        copy($noisy, $lossless);

        (new ImageOptimizer(lossy: true))->optimize($noisy);
        (new ImageOptimizer())->optimize($lossless);
        (new ImageOptimizer(lossy: true))->optimize($flat);

        self::assertLessThan(filesize($lossless), filesize($noisy), 'the palette version of a noisy image is smaller');
        self::assertFalse(imageistruecolor(imagecreatefrompng($noisy)));
        self::assertLessThan(200, filesize($flat), 'a flat image compresses to almost nothing either way');
    }
    public function test_max_width_scales_down_but_never_up(): void
    {
        $big = $this->jpeg('big.jpg', 90, $this->photo(800, 400));
        $small = $this->jpeg('small.jpg', 100, $this->photo(120, 60));

        $r = (new ImageOptimizer(80, maxWidth: 400))->optimize($big);
        $s = (new ImageOptimizer(80, maxWidth: 400))->optimize($small);

        self::assertTrue($r['resized']);
        self::assertSame([400, 200], array_slice(getimagesize($big), 0, 2), 'the aspect ratio is kept');
        self::assertFalse($s['resized']);
        self::assertSame([120, 60], array_slice(getimagesize($small), 0, 2), 'a smaller image is never enlarged');
    }

    public function test_a_webp_sibling_is_written_only_when_asked_and_only_when_much_smaller(): void
    {
        $path = $this->jpeg('a.jpg');
        (new ImageOptimizer(78))->optimize($path);
        self::assertFileDoesNotExist($this->dir . '/a.webp');

        $this->jpeg('b.jpg');
        $r = (new ImageOptimizer(78, 60, webp: true))->optimize($this->dir . '/b.jpg');

        self::assertFileExists($this->dir . '/b.webp');
        self::assertSame(IMAGETYPE_WEBP, getimagesize($this->dir . '/b.webp')[2]);
        self::assertSame(filesize($this->dir . '/b.webp'), $r['webp']);
        self::assertLessThan(filesize($this->dir . '/b.jpg') * 0.9, filesize($this->dir . '/b.webp'));
    }

    // ---- refusals -----------------------------------------------------------------------------------------------

    public function test_things_that_are_not_supported_images_are_left_alone(): void
    {
        file_put_contents("{$this->dir}/fake.jpg", "<?php echo 'not an image';");
        $gif = imagecreatetruecolor(10, 10);
        imagegif($gif, "{$this->dir}/a.gif");
        $opt = new ImageOptimizer();

        self::assertSame('not an image', $opt->optimize("{$this->dir}/fake.jpg")['note']);
        self::assertStringContainsString('unsupported', $opt->optimize("{$this->dir}/a.gif")['note']);
        self::assertSame('not a regular file', $opt->optimize("{$this->dir}/missing.jpg")['note']);
        self::assertSame("<?php echo 'not an image';", file_get_contents("{$this->dir}/fake.jpg"));
    }

    public function test_an_image_claiming_absurd_dimensions_is_not_decoded(): void
    {
        $ihdr = pack('N', 30000) . pack('N', 30000) . "\x08\x06\x00\x00\x00";
        $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr)) . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
        file_put_contents("{$this->dir}/bomb.png", $png);

        $r = (new ImageOptimizer())->optimize("{$this->dir}/bomb.png");

        self::assertStringContainsString('too large', $r['note']);
        self::assertFalse($r['written']);
    }

    // ---- EXIF orientation ----------------------------------------------------------------------------------------------

    /** A JPEG with an EXIF orientation flag injected right after the SOI marker. */
    private function jpegWithOrientation(int $orientation, bool $littleEndian = true): string
    {
        $path = $this->jpeg('o.jpg', 95, $this->photo(200, 100));
        $bytes = (string) file_get_contents($path);
        $p16 = static fn (int $v): string => pack($littleEndian ? 'v' : 'n', $v);
        $p32 = static fn (int $v): string => pack($littleEndian ? 'V' : 'N', $v);
        $tiff = ($littleEndian ? 'II' : 'MM') . $p16(42) . $p32(8) . $p16(1) . $p16(0x0112) . $p16(3) . $p32(1) . $p16($orientation) . "\0\0" . $p32(0);
        $payload = "Exif\0\0" . $tiff;
        $app1 = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
        file_put_contents($path, substr($bytes, 0, 2) . $app1 . substr($bytes, 2));

        return $path;
    }

    public function test_the_exif_orientation_flag_is_read_in_both_byte_orders(): void
    {
        foreach ([1, 3, 6, 8] as $o) {
            self::assertSame($o, ImageOptimizer::exifOrientation((string) file_get_contents($this->jpegWithOrientation($o, true))), "little-endian {$o}");
            self::assertSame($o, ImageOptimizer::exifOrientation((string) file_get_contents($this->jpegWithOrientation($o, false))), "big-endian {$o}");
        }
        self::assertSame(1, ImageOptimizer::exifOrientation((string) file_get_contents($this->jpeg('plain.jpg'))));
        self::assertSame(1, ImageOptimizer::exifOrientation('not a jpeg at all'));
    }

    public function test_a_rotated_phone_photo_is_turned_upright_before_the_flag_is_dropped(): void
    {
        $path = $this->jpegWithOrientation(6);   // "rotate 90° clockwise to view": stored 200×100, displayed 100×200

        $r = (new ImageOptimizer(80))->optimize($path);

        self::assertTrue($r['written']);
        self::assertSame([100, 200], array_slice(getimagesize($path), 0, 2), 'the pixels were rotated');
        self::assertSame(1, ImageOptimizer::exifOrientation((string) file_get_contents($path)), 'and no orientation flag is left to rotate them twice');
    }

    public function test_a_mirrored_orientation_is_left_alone_rather_than_guessed(): void
    {
        $path = $this->jpegWithOrientation(2);
        $hash = md5_file($path);

        $r = (new ImageOptimizer(60))->optimize($path);

        self::assertStringContainsString('mirrored', $r['note']);
        self::assertSame($hash, md5_file($path));
    }
}
