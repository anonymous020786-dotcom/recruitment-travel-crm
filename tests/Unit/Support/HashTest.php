<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Hash;
use PHPUnit\Framework\TestCase;

final class HashTest extends TestCase
{
    public function test_make_and_verify_bcrypt(): void
    {
        $hash = new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]);
        $h = $hash->make('correct horse battery staple');

        self::assertTrue($hash->verify('correct horse battery staple', $h));
        self::assertFalse($hash->verify('wrong', $h));
    }

    public function test_verify_empty_hash_is_false_not_error(): void
    {
        $hash = new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]);
        self::assertFalse($hash->verify('anything', ''));
    }

    public function test_needs_rehash_when_cost_changes(): void
    {
        $weak = (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('pw');
        $strong = new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 6]]);

        self::assertTrue($strong->needsRehash($weak));
        self::assertFalse($strong->needsRehash($strong->make('pw')));
    }

    public function test_hashes_are_salted_and_unique(): void
    {
        $hash = new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]);
        self::assertNotSame($hash->make('pw'), $hash->make('pw'));
    }
}
