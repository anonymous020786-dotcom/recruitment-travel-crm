<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Storage\Lifecycle;
use App\Storage\S3Client;
use App\Storage\SigV4;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeTransport;

/** The S3/R2 client: request shape and signing (no network), presigned URLs, listings, errors and lifecycle rules. */
final class S3ClientTest extends TestCase
{
    private const T = 1369353600;
    private string $file = '';

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 's3t') ?: '';
        file_put_contents($this->file, 'Welcome to Amazon S3.');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /** @param array<string,mixed> $over */
    private function s3(FakeTransport $t, array $over = []): S3Client
    {
        return new S3Client($t, $over + ['host' => 's3.ap-south-1.amazonaws.com', 'region' => 'ap-south-1', 'bucket' => 'crm-docs', 'key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'prefix' => 'crm'], static fn (): int => self::T);
    }

    private function r2(FakeTransport $t): S3Client
    {
        return new S3Client($t, ['host' => 'acc123.r2.cloudflarestorage.com', 'region' => 'auto', 'bucket' => 'docs', 'key' => 'K', 'secret' => 'S', 'path_style' => true], static fn (): int => self::T);
    }

    public function test_an_upload_is_signed_with_the_real_payload_hash_and_uses_virtual_hosted_addressing_on_s3(): void
    {
        $t = new FakeTransport();
        $t->push(['status' => 200, 'headers' => ['ETag' => '"abc123"']]);

        $r = $this->s3($t, ['storage_class' => 'STANDARD_IA'])->put('documents/24/09/xx/01ABC.pdf', $this->file, 'application/pdf', ['sha256' => 'deadbeef']);

        $req = $t->last();
        self::assertTrue($r['ok']);
        self::assertSame('abc123', $r['etag']);
        self::assertSame('PUT', $req['method']);
        self::assertSame('https://crm-docs.s3.ap-south-1.amazonaws.com/crm/documents/24/09/xx/01ABC.pdf', $req['url']);
        self::assertSame($this->file, $req['upload'], 'the file is streamed, not loaded into memory');
        self::assertSame('44ce7dd67c959e0d3524ffac1771dfbba87d2b6b4b4e99e42034a8b803f8b072', $req['headers']['x-amz-content-sha256']);
        self::assertSame('STANDARD_IA', $req['headers']['x-amz-storage-class']);
        self::assertSame('deadbeef', $req['headers']['x-amz-meta-sha256']);
        self::assertSame('application/pdf', $req['headers']['content-type']);
        self::assertArrayNotHasKey('host', $req['headers']);
        self::assertMatchesRegularExpression('~^AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/ap-south-1/s3/aws4_request, SignedHeaders=content-length;content-type;host;x-amz-content-sha256;x-amz-date;x-amz-meta-sha256;x-amz-storage-class, Signature=[0-9a-f]{64}$~', $req['headers']['authorization']);
    }

    public function test_the_signature_on_the_wire_is_the_one_sigv4_computes_for_that_exact_request(): void
    {
        $t = new FakeTransport();
        $this->s3($t)->put('k.pdf', $this->file, 'application/pdf');
        $req = $t->last();

        $expected = SigV4::sign('PUT', '/crm/k.pdf', [], ['host' => 'crm-docs.s3.ap-south-1.amazonaws.com', 'content-type' => 'application/pdf', 'content-length' => '21'],
            hash('sha256', 'Welcome to Amazon S3.'), ['key' => 'AKIAIOSFODNN7EXAMPLE', 'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'], 'ap-south-1', 's3', self::T);

        self::assertStringEndsWith('Signature=' . $expected['signature'], $req['headers']['authorization']);
    }

    public function test_r2_uses_path_style_addressing_the_auto_region_and_no_storage_class_by_default(): void
    {
        $t = new FakeTransport();
        $this->r2($t)->put('a/b.pdf', $this->file, 'application/pdf');
        $req = $t->last();

        self::assertSame('https://acc123.r2.cloudflarestorage.com/docs/a/b.pdf', $req['url']);
        self::assertStringContainsString('/auto/s3/aws4_request', $req['headers']['authorization']);
        self::assertArrayNotHasKey('x-amz-storage-class', $req['headers']);
    }

    public function test_a_bucket_name_with_dots_falls_back_to_path_style_so_tls_still_matches(): void
    {
        $t = new FakeTransport();
        $this->s3($t, ['bucket' => 'my.docs.bucket'])->head('x.pdf');

        self::assertSame('https://s3.ap-south-1.amazonaws.com/my.docs.bucket/crm/x.pdf', $t->last()['url']);
    }

    public function test_head_get_and_delete(): void
    {
        $t = new FakeTransport();
        $c = $this->s3($t);
        $t->push(['status' => 200, 'headers' => ['Content-Length' => '2048', 'ETag' => '"e1"', 'Content-Type' => 'application/pdf', 'x-amz-storage-class' => 'STANDARD_IA']]);
        $t->push(['status' => 404]);
        $t->push(['status' => 200, 'body' => 'BYTES', 'headers' => ['Content-Type' => 'application/pdf']]);
        $t->push(['status' => 204]);
        $t->push(['status' => 403, 'body' => '<Error><Code>AccessDenied</Code></Error>']);

        self::assertSame(['size' => 2048, 'etag' => 'e1', 'type' => 'application/pdf', 'class' => 'STANDARD_IA'], $c->head('a.pdf'));
        self::assertNull($c->head('missing.pdf'), 'a missing object is null, not an exception');
        $got = $c->get('a.pdf');
        self::assertSame(['BYTES', true], [$got['body'], $got['ok']]);
        self::assertTrue($c->delete('a.pdf'));
        self::assertFalse($c->delete('b.pdf'));
        self::assertSame(['HEAD', 'HEAD', 'GET', 'DELETE', 'DELETE'], array_column($t->requests, 'method'));
    }

    public function test_a_download_can_stream_to_a_file(): void
    {
        $src = tempnam(sys_get_temp_dir(), 's3s');
        file_put_contents($src, 'FILE CONTENT');
        $dest = tempnam(sys_get_temp_dir(), 's3d');
        $t = new FakeTransport();
        $t->push(['status' => 200, 'file' => $src]);

        $r = $this->s3($t)->get('a.pdf', $dest);

        self::assertTrue($r['ok']);
        self::assertSame('FILE CONTENT', file_get_contents($dest));
        self::assertSame($dest, $t->last()['download']);
        @unlink($src);
        @unlink($dest);
    }

    // ---- presigned downloads -------------------------------------------------------------------------------------------

    public function test_a_presigned_url_forces_a_download_name_and_type_and_carries_a_valid_signature(): void
    {
        $url = $this->s3(new FakeTransport())->presignGet('documents/x.pdf', 300, 'Passport "Ravi".pdf', 'application/pdf');

        $parts = parse_url($url);
        parse_str((string) $parts['query'], $q);
        self::assertSame('crm-docs.s3.ap-south-1.amazonaws.com', $parts['host']);
        self::assertSame('/crm/documents/x.pdf', $parts['path']);
        self::assertSame('300', $q['X-Amz-Expires']);
        self::assertSame('attachment; filename="Passport _Ravi_.pdf"', $q['response-content-disposition'], 'quotes are stripped so the header cannot be broken out of');
        self::assertSame('application/pdf', $q['response-content-type']);

        $expect = SigV4::presign('GET', 'crm-docs.s3.ap-south-1.amazonaws.com', '/crm/documents/x.pdf',
            ['response-content-disposition' => 'attachment; filename="Passport _Ravi_.pdf"', 'response-content-type' => 'application/pdf'],
            ['key' => 'AKIAIOSFODNN7EXAMPLE', 'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'], 'ap-south-1', 's3', self::T, 300);
        self::assertSame($expect['signature'], $q['X-Amz-Signature']);
        self::assertStringNotContainsString('wJalrXUtnFEMI', $url, 'the secret is never in the URL');
    }

    public function test_the_expiry_is_at_most_seven_days(): void
    {
        parse_str((string) parse_url($this->s3(new FakeTransport())->presignGet('k', 10_000_000), PHP_URL_QUERY), $q);

        self::assertSame('604800', $q['X-Amz-Expires']);
    }

    // ---- listing, bucket check, errors -----------------------------------------------------------------------------------

    public function test_listing_parses_keys_relative_to_the_prefix_and_pages(): void
    {
        $xml = '<?xml version="1.0"?><ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><IsTruncated>true</IsTruncated><NextContinuationToken>tok/1</NextContinuationToken>'
            . '<Contents><Key>crm/backups/a.gz</Key><LastModified>2026-09-01T10:00:00.000Z</LastModified><Size>1234</Size></Contents>'
            . '<Contents><Key>crm/backups/b.gz</Key><LastModified>2026-09-02T10:00:00.000Z</LastModified><Size>99</Size></Contents></ListBucketResult>';
        $t = new FakeTransport();
        $t->push(['status' => 200, 'body' => $xml]);

        $r = $this->s3($t)->list('backups/', 50);

        self::assertSame('GET', $t->last()['method']);
        self::assertStringContainsString('list-type=2', $t->last()['url']);
        self::assertStringContainsString('prefix=crm%2Fbackups%2F', $t->last()['url']);
        self::assertStringContainsString('max-keys=50', $t->last()['url']);
        self::assertSame(['backups/a.gz'], array_slice(array_column($r['keys'], 'key'), 0, 1));
        self::assertTrue($r['truncated']);
        self::assertSame('tok/1', $r['next']);
    }

    public function test_the_bucket_check_explains_each_kind_of_failure(): void
    {
        foreach ([[200, 'Connected'], [403, 'refused these credentials'], [404, 'bucket was not found'], [500, 'HTTP 500']] as [$status, $needle]) {
            $t = new FakeTransport();
            $t->push(['status' => $status]);
            $r = $this->s3($t)->checkBucket();
            self::assertStringContainsString($needle, $r['message']);
            self::assertSame($status === 200, $r['ok']);
        }
        $t = new FakeTransport();
        $t->push(['status' => 0, 'error' => 'timed out']);
        self::assertStringContainsString('Could not reach the service: timed out', $this->s3($t)->checkBucket()['message']);
        self::assertSame('AccessDenied', S3Client::errorCode('<?xml version="1.0"?><Error><Code>AccessDenied</Code><Message>x</Message></Error>'));
        self::assertNull(S3Client::errorCode('not xml'));
    }

    public function test_a_failed_upload_reports_the_status_and_never_throws(): void
    {
        $t = new FakeTransport();
        $t->push(['status' => 403]);
        $r = $this->s3($t)->put('k', $this->file, 'application/pdf');

        self::assertSame([false, 403], [$r['ok'], $r['status']]);
        self::assertFalse($this->s3($t)->put('k', '/no/such/file', 'application/pdf')['ok']);
    }

    // ---- lifecycle (cost saving) ---------------------------------------------------------------------------------------

    public function test_the_lifecycle_configuration_tiers_documents_down_expires_backups_and_aborts_stale_uploads(): void
    {
        $xml = Lifecycle::xml('crm/documents/', 90, 365, 'crm/backups/', 30);

        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml), 'the rules are well-formed XML');
        self::assertSame(3, $doc->getElementsByTagName('Rule')->length);
        self::assertStringContainsString('<Prefix>crm/documents/</Prefix>', $xml);
        self::assertStringContainsString('<Days>90</Days><StorageClass>STANDARD_IA</StorageClass>', $xml);
        self::assertStringContainsString('<Days>365</Days><StorageClass>GLACIER_IR</StorageClass>', $xml);
        self::assertStringContainsString('<Prefix>crm/backups/</Prefix></Filter><Status>Enabled</Status><Expiration><Days>30</Days>', $xml);
        self::assertStringContainsString('<DaysAfterInitiation>7</DaysAfterInitiation>', $xml);

        // S3 refuses Standard-IA under 30 days and needs the archive step to come after it
        self::assertStringContainsString('<Days>30</Days><StorageClass>STANDARD_IA</StorageClass>', Lifecycle::xml('d/', 5, null));
        self::assertStringNotContainsString('GLACIER_IR', Lifecycle::xml('d/', 5, null), 'R2 has no archive tier: pass null to skip it');
        self::assertStringContainsString('<Days>121</Days><StorageClass>GLACIER_IR', Lifecycle::xml('d/', 120, 100));
        self::assertStringContainsString('&lt;x&gt;', Lifecycle::xml('<x>', 30, null), 'a prefix is XML-escaped');
    }

    public function test_applying_a_lifecycle_sends_the_xml_with_its_content_md5(): void
    {
        $t = new FakeTransport();
        $xml = Lifecycle::xml('crm/documents/', 90, null);

        $r = $this->s3($t)->putLifecycle($xml);

        $req = $t->last();
        self::assertTrue($r['ok']);
        self::assertSame('PUT', $req['method']);
        self::assertSame('https://crm-docs.s3.ap-south-1.amazonaws.com/?lifecycle=', $req['url']);
        self::assertSame($xml, $req['body']);
        self::assertSame(base64_encode(md5($xml, true)), $req['headers']['content-md5']);
        self::assertSame(hash('sha256', $xml), $req['headers']['x-amz-content-sha256']);
    }
}
