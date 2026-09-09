<?php

declare(strict_types=1);

namespace Tests\Unit\Session;

use App\Session\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    public function test_basic_get_put_forget_pull(): void
    {
        $s = new Session('id', []);
        $s->put('a', 1);
        self::assertSame(1, $s->get('a'));
        self::assertTrue($s->has('a'));
        self::assertSame(1, $s->pull('a'));
        self::assertFalse($s->has('a'));
        self::assertSame('def', $s->get('missing', 'def'));
    }

    public function test_flash_survives_exactly_one_request(): void
    {
        $s = new Session('id', []);
        $s->flash('msg', 'saved');

        // Request 2 begins:
        $s2 = new Session('id', $s->all());
        $s2->ageFlashData();
        self::assertSame('saved', $s2->get('msg'), 'flash available on the next request');

        // Request 3 begins:
        $s3 = new Session('id', $s2->all());
        $s3->ageFlashData();
        self::assertNull($s3->get('msg'), 'flash gone after one request');
    }

    public function test_reflash_and_keep(): void
    {
        $s = new Session('id', []);
        $s->flash('a', 1);
        $s->flash('b', 2);

        $s2 = new Session('id', $s->all());
        $s2->ageFlashData();
        $s2->keep('a');

        $s3 = new Session('id', $s2->all());
        $s3->ageFlashData();
        self::assertSame(1, $s3->get('a'), 'kept');
        self::assertNull($s3->get('b'), 'not kept');
    }

    public function test_token_is_stable_until_regenerated(): void
    {
        $s = new Session('id', []);
        $t = $s->token();
        self::assertSame(64, strlen($t));
        self::assertSame($t, $s->token());
        $s->regenerateToken();
        self::assertNotSame($t, $s->token());
    }

    public function test_regenerate_changes_id_and_reports_old(): void
    {
        $s = new Session('old-id', ['k' => 'v']);
        $s->regenerate(destroyOld: true);
        self::assertNotSame('old-id', $s->id());
        self::assertSame('v', $s->get('k'), 'data preserved on regenerate');
        self::assertSame('old-id', $s->migrateFrom());
        self::assertNull($s->migrateFrom(), 'consumed once');
    }

    public function test_invalidate_wipes_data_and_rotates(): void
    {
        $s = new Session('old-id', ['k' => 'v', '_token' => 'x']);
        $s->invalidate();
        self::assertNotSame('old-id', $s->id());
        self::assertNull($s->get('k'));
        self::assertNotSame('x', $s->token());
    }

    public function test_id_validation(): void
    {
        self::assertTrue(Session::isValidId(str_repeat('a', 64)));
        self::assertFalse(Session::isValidId('short'));
        self::assertFalse(Session::isValidId(str_repeat('Z', 64)));
    }
}
