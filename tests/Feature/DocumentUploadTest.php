<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ValidationException;
use App\Services\LeadImportService;
use App\Support\DocumentUpload;
use Tests\Support\DbTestCase;

/**
 * The upload accept pipeline itself (Phase 12 upload review): what is accepted, what is refused, and — the part
 * the service-level tests cannot see — that the bytes stored are the bytes that were validated and hashed.
 * Image tests need the GD extension (`php -d extension=gd vendor/bin/phpunit …` where it is not enabled).
 */
final class DocumentUploadTest extends DbTestCase
{
    private const MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    /** @var list<string> */
    private array $tmp = [];
    /** @var list<string> storage paths (relative) to delete */
    private array $stored = [];
    private string $memoryLimit = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->memoryLimit = (string) ini_get('memory_limit');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->memoryLimit);
        $this->app->config()->set('app.env', 'testing');
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        foreach ($this->stored as $rel) {
            @unlink($this->app->basePath($rel));
        }
    }

    private function upload(): DocumentUpload
    {
        return $this->app->get(DocumentUpload::class);
    }

    /** @return array{name:string,tmp_name:string,error:int,size:int} */
    private function file(string $contents, string $name = 'scan.pdf'): array
    {
        $path = tempnam(sys_get_temp_dir(), 'docup');
        file_put_contents($path, $contents);
        $this->tmp[] = $path;

        return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($contents)];
    }

    /** @return array{storage_path:string,mime_type:string,extension:string,size_bytes:int,sha256:string} */
    private function store(array $file, array $mimes = self::MIMES, int $maxKb = 2048): array
    {
        $r = $this->upload()->store($file, $mimes, $maxKb);
        $this->stored[] = $r['storage_path'];

        return $r;
    }

    private function refused(array $file, string $expectedFragment, array $mimes = self::MIMES, int $maxKb = 2048): void
    {
        try {
            $this->store($file, $mimes, $maxKb);
            self::fail("accepted, expected refusal containing “{$expectedFragment}”");
        } catch (ValidationException $e) {
            self::assertStringContainsString($expectedFragment, (string) $e->first());
        }
    }

    private function pdf(string $extra = ''): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R{$extra}>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 3 3]>>endobj\ntrailer<</Size 4/Root 1 0 R>>\n%%EOF";
    }

    private function needGd(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD is not loaded — run with: php -d extension=gd vendor/bin/phpunit');
        }
    }

    // ---- what is accepted -------------------------------------------

    public function test_a_pdf_is_stored_under_a_random_name_with_the_extension_taken_from_its_content(): void
    {
        $r = $this->store($this->file($this->pdf(), 'passport.php.exe'));   // the client's name is irrelevant

        self::assertSame('application/pdf', $r['mime_type']);
        self::assertSame('pdf', $r['extension']);
        self::assertMatchesRegularExpression('#^storage/private/documents/\d{2}/\d{2}/[0-9A-Z]{2}/[0-9A-Z]{26}\.pdf$#', $r['storage_path']);
        self::assertStringNotContainsString('passport', $r['storage_path']);
        self::assertFileExists($this->app->basePath($r['storage_path']));
    }

    public function test_the_recorded_hash_and_size_are_those_of_the_stored_bytes(): void
    {
        $r = $this->store($this->file($this->pdf()));
        $bytes = (string) file_get_contents($this->app->basePath($r['storage_path']));

        self::assertSame(hash('sha256', $bytes), $r['sha256']);
        self::assertSame(strlen($bytes), $r['size_bytes']);
    }

    public function test_a_png_is_accepted_and_its_hash_matches_what_was_stored(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $r = $this->store($this->file($png, 'photo.png'));

        self::assertSame('image/png', $r['mime_type']);
        self::assertSame(hash_file('sha256', $this->app->basePath($r['storage_path'])), $r['sha256']);
    }

    // ---- what is refused ----------------------------------------------

    public function test_a_renamed_executable_is_refused_by_content_not_by_name(): void
    {
        $this->refused($this->file("MZ\x90\x00\x03\x00\x00\x00 fake exe body", 'invoice.pdf'), 'not accepted');
        $this->refused($this->file("<?php system(\$_GET['c']);", 'scan.jpg'), 'not accepted');
        $this->refused($this->file('<html><script>alert(1)</script></html>', 'scan.png'), 'not accepted');
    }

    public function test_a_type_the_document_does_not_allow_is_refused_even_if_valid(): void
    {
        $this->refused($this->file($this->pdf()), 'not accepted', ['image/jpeg', 'image/png']);
    }

    public function test_empty_oversized_and_failed_uploads_are_refused(): void
    {
        $this->refused($this->file(''), 'empty');
        $this->refused($this->file($this->pdf(str_repeat(' ', 3 * 1024))), 'exceeds the 2 KB limit', self::MIMES, 2);

        $failed = $this->file($this->pdf());
        $failed['error'] = UPLOAD_ERR_INI_SIZE;
        $this->refused($failed, 'too large');
        $failed['error'] = UPLOAD_ERR_NO_FILE;
        $this->refused($failed, 'Choose a file');
        $this->refused(['name' => 'x.pdf', 'tmp_name' => '/no/such/file', 'error' => UPLOAD_ERR_OK, 'size' => 10], 'No file was uploaded');
    }

    public function test_the_reported_size_cannot_be_used_to_smuggle_a_big_file_past_the_limit(): void
    {
        $f = $this->file($this->pdf(str_repeat(' ', 4 * 1024)));
        $f['size'] = 10; // lies

        $this->refused($f, 'exceeds the 2 KB limit', self::MIMES, 2);
    }

    public function test_in_production_only_a_real_http_upload_is_accepted(): void
    {
        $this->app->config()->set('app.env', 'production');

        $this->refused($this->file($this->pdf()), 'No file was uploaded');
    }

    /** @return array<string,array{0:string}> */
    public static function activePdfs(): array
    {
        return [
            'javascript'           => ['/JavaScript (app.alert(1))'],
            'js shorthand'         => ['/JS (app.alert(1))'],
            'open action'          => ['/OpenAction 5 0 R'],
            'launch'               => ['/Launch /F (cmd.exe)'],
            'rich media'           => ['/RichMedia 1 0 R'],
            'embedded file'        => ['/EmbeddedFile 1 0 R'],
            'additional actions'   => ['/AA << /O 1 0 R >>'],
            'hex-obfuscated name'  => ['/J#61vaScript (x)'],
            'hex-obfuscated short' => ['/#4AS (x)'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('activePdfs')]
    public function test_a_pdf_with_active_content_is_refused(string $fragment): void
    {
        $this->refused($this->file($this->pdf($fragment)), 'active content');
    }

    public function test_an_encrypted_pdf_is_refused_with_a_useful_message(): void
    {
        $this->refused($this->file($this->pdf('/Encrypt 9 0 R')), 'Encrypted');
    }

    public function test_names_that_merely_start_like_a_token_are_not_mistaken_for_one(): void
    {
        $r = $this->store($this->file($this->pdf('/JSON true /Launchpad 1 /AAAAAA+Arial 2')));

        self::assertSame('application/pdf', $r['mime_type']);
    }

    // ---- images: what gets stored is the sanitised copy ---------------------

    public function test_a_jpeg_is_re_encoded_so_trailing_and_hidden_data_never_reach_storage(): void
    {
        $this->needGd();
        $im = imagecreatetruecolor(20, 20);
        ob_start();
        imagejpeg($im);
        $jpeg = (string) ob_get_clean();
        $marker = '<?php echo "pwned"; ?>';
        $polyglot = $jpeg . $marker;                       // valid JPEG followed by code

        $r = $this->store($this->file($polyglot, 'photo.jpg'));
        $bytes = (string) file_get_contents($this->app->basePath($r['storage_path']));

        self::assertSame('image/jpeg', $r['mime_type']);
        self::assertStringNotContainsString('<?php', $bytes, 'the stored file is the re-encoded copy');
        self::assertNotSame($polyglot, $bytes);
        self::assertSame(hash('sha256', $bytes), $r['sha256'], 'and the hash is of the stored bytes');
        self::assertNotFalse(@imagecreatefromstring($bytes), 'it is still a valid image');
    }

    public function test_an_image_declaring_absurd_dimensions_is_refused_before_it_is_decoded(): void
    {
        $this->needGd();
        ini_set('memory_limit', '256M');
        $ihdr = pack('NN', 30000, 30000) . "\x08\x02\x00\x00\x00";
        $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));

        $this->refused($this->file($png, 'bomb.png'), 'too many pixels');
    }

    public function test_an_image_that_will_not_decode_is_refused(): void
    {
        $this->needGd();
        $ihdr = pack('NN', 1, 1) . "\x08\x02\x00\x00\x00";
        $idat = 'garbage that is not zlib';
        $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr))
            . pack('N', strlen($idat)) . 'IDAT' . $idat . pack('N', crc32('IDAT' . $idat));

        $this->refused($this->file($png, 'x.png'), 'could not be processed');
        $this->refused($this->file("\x89PNG\r\n\x1a\n" . 'not really a png at all', 'x.png'), 'not accepted');   // finfo already says no
    }

    // ---- CSV import upload -------------------------------------------------

    public function test_a_csv_import_must_be_text(): void
    {
        $svc = $this->app->get(LeadImportService::class);
        $check = new \ReflectionMethod($svc, 'assertPlainTextUpload');

        $ok = $this->file("name,phone\nA,9800000000\n", 'leads.csv')['tmp_name'];
        $check->invoke($svc, $ok);
        self::addToAssertionCount(1);

        foreach (["PK\x03\x04\x14\x00 zip bytes", "name,phone\0hidden\n", "\xFF\xFEn\0a\0m\0e\0"] as $bad) {
            try {
                $check->invoke($svc, $this->file($bad, 'leads.csv')['tmp_name']);
                self::fail('accepted a non-text upload');
            } catch (ValidationException $e) {
                self::assertStringContainsString('CSV', (string) $e->first());
            }
        }
    }
}
