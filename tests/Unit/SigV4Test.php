<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Storage\SigV4;
use PHPUnit\Framework\TestCase;

/**
 * SigV4 against the worked examples published in the AWS documentation ("Signature Calculations for the Authorization Header",
 * "Authenticating Requests: Using Query Parameters"). If our signatures match Amazon's byte for byte, S3, R2 and every other
 * S3-compatible service will accept them.
 */
final class SigV4Test extends TestCase
{
    private const CREDS = ['key' => 'AKIAIOSFODNN7EXAMPLE', 'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'];
    private const T = 1369353600;   // 2013-05-24T00:00:00Z
    private const EMPTY_SHA = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function test_get_object_with_a_range_header_matches_the_aws_example(): void
    {
        $r = SigV4::sign('GET', '/test.txt', [], ['Host' => 'examplebucket.s3.amazonaws.com', 'Range' => 'bytes=0-9'], self::EMPTY_SHA, self::CREDS, 'us-east-1', 's3', self::T);

        self::assertSame('f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $r['signature']);
        self::assertSame('host;range;x-amz-content-sha256;x-amz-date', $r['signed_headers']);
        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request,SignedHeaders=host;range;x-amz-content-sha256;x-amz-date,Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            str_replace(', ', ',', $r['authorization']),
        );
    }

    public function test_put_object_with_a_storage_class_and_a_dollar_sign_in_the_key_matches_the_aws_example(): void
    {
        $body = 'Welcome to Amazon S3.';
        self::assertSame('44ce7dd67c959e0d3524ffac1771dfbba87d2b6b4b4e99e42034a8b803f8b072', hash('sha256', $body));

        $r = SigV4::sign('PUT', '/test$file.text', [], [
            'Host' => 'examplebucket.s3.amazonaws.com', 'Date' => 'Fri, 24 May 2013 00:00:00 GMT', 'x-amz-storage-class' => 'REDUCED_REDUNDANCY',
        ], hash('sha256', $body), self::CREDS, 'us-east-1', 's3', self::T);

        self::assertSame('98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd', $r['signature']);
        self::assertSame('date;host;x-amz-content-sha256;x-amz-date;x-amz-storage-class', $r['signed_headers']);
        self::assertStringContainsString("\n/test%24file.text\n", $r['canonical_request'], 'the key is encoded once');
    }

    public function test_a_presigned_get_url_matches_the_aws_example(): void
    {
        $r = SigV4::presign('GET', 'examplebucket.s3.amazonaws.com', '/test.txt', [], self::CREDS, 'us-east-1', 's3', self::T, 86400);

        self::assertSame('aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404', $r['signature']);
        self::assertSame('AWS4-HMAC-SHA256', $r['query']['X-Amz-Algorithm']);
        self::assertSame('AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request', $r['query']['X-Amz-Credential']);
        self::assertSame('20130524T000000Z', $r['query']['X-Amz-Date']);
        self::assertSame('86400', $r['query']['X-Amz-Expires']);
        self::assertSame('host', $r['query']['X-Amz-SignedHeaders']);
    }

    public function test_canonical_encoding_rules(): void
    {
        self::assertSame('/a%20b/c%2Bd/%C3%A9.pdf', SigV4::canonicalUri('/a b/c+d/é.pdf'));
        self::assertSame('/plain/key-1_2.3~x', SigV4::canonicalUri('/plain/key-1_2.3~x'));
        self::assertSame('a=1&b=x%20y&response-content-disposition=attachment%3B%20filename%3D%22a.pdf%22', SigV4::canonicalQuery(['response-content-disposition' => 'attachment; filename="a.pdf"', 'b' => 'x y', 'a' => 1]));
    }

    public function test_the_expiry_of_a_presigned_url_is_clamped_to_what_s3_allows(): void
    {
        self::assertSame('604800', SigV4::presign('GET', 'h', '/k', [], self::CREDS, 'auto', 's3', self::T, 999999999)['query']['X-Amz-Expires']);
        self::assertSame('1', SigV4::presign('GET', 'h', '/k', [], self::CREDS, 'auto', 's3', self::T, 0)['query']['X-Amz-Expires']);
    }
}
