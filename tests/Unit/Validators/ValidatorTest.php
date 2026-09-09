<?php

declare(strict_types=1);

namespace Tests\Unit\Validators;

use App\Exceptions\ValidationException;
use App\Validators\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function test_required_and_email(): void
    {
        $v = Validator::make(['email' => 'not-an-email'], ['email' => 'required|email', 'name' => 'required']);
        self::assertTrue($v->fails());
        $errors = $v->errors();
        self::assertArrayHasKey('email', $errors);
        self::assertArrayHasKey('name', $errors);
    }

    public function test_passes_valid_data(): void
    {
        $v = Validator::make(
            ['email' => 'a@b.com', 'age' => '30', 'role' => 'admin'],
            ['email' => 'required|email', 'age' => 'integer|min:18', 'role' => 'in:admin,user'],
        );
        self::assertTrue($v->passes());
        self::assertSame(['email' => 'a@b.com', 'age' => '30', 'role' => 'admin'], $v->validated());
    }

    public function test_min_max_on_strings(): void
    {
        self::assertTrue(Validator::make(['p' => 'short'], ['p' => 'min:10'])->fails());
        self::assertTrue(Validator::make(['p' => 'plenty long enough'], ['p' => 'min:10'])->passes());
        self::assertTrue(Validator::make(['p' => str_repeat('x', 50)], ['p' => 'max:20'])->fails());
    }

    public function test_confirmed(): void
    {
        self::assertTrue(Validator::make(
            ['password' => 'secret123', 'password_confirmation' => 'secret123'],
            ['password' => 'confirmed'],
        )->passes());

        self::assertTrue(Validator::make(
            ['password' => 'secret123', 'password_confirmation' => 'nope'],
            ['password' => 'confirmed'],
        )->fails());
    }

    public function test_nullable_skips_when_empty(): void
    {
        self::assertTrue(Validator::make(['nick' => ''], ['nick' => 'nullable|min:3'])->passes());
        self::assertTrue(Validator::make(['nick' => 'ab'], ['nick' => 'nullable|min:3'])->fails());
    }

    public function test_sometimes_skips_when_absent(): void
    {
        self::assertTrue(Validator::make([], ['age' => 'sometimes|integer'])->passes());
        self::assertTrue(Validator::make(['age' => 'x'], ['age' => 'sometimes|integer'])->fails());
    }

    public function test_validated_throws_on_failure(): void
    {
        $this->expectException(ValidationException::class);
        Validator::make(['email' => ''], ['email' => 'required'])->validated();
    }

    public function test_custom_rule(): void
    {
        $v = Validator::make(['code' => 'ABC'], ['code' => 'required|even_length'])
            ->rule('even_length', fn ($value) => strlen((string) $value) % 2 === 0 ?: 'Code length must be even.');

        self::assertTrue($v->fails());
        self::assertSame('Code length must be even.', $v->errors()['code'][0]);
    }

    public function test_one_message_per_field(): void
    {
        $v = Validator::make(['x' => ''], ['x' => 'required|email|min:5']);
        self::assertCount(1, $v->errors()['x']);
    }
}
