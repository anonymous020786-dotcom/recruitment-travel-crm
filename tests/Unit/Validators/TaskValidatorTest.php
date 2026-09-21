<?php

declare(strict_types=1);

namespace Tests\Unit\Validators;

use App\Exceptions\ValidationException;
use App\Validators\TaskValidator;
use PHPUnit\Framework\TestCase;

final class TaskValidatorTest extends TestCase
{
    private function valid(array $overrides = []): array
    {
        return array_merge([
            'title' => ' Call candidate ', 'description' => 'Confirm availability.',
            'priority' => 'high', 'due_date' => gmdate('Y-m-d', strtotime('+3 days')),
            'due_time' => '14:30', 'assigned_to' => '5',
        ], $overrides);
    }

    public function test_accepts_and_normalises(): void
    {
        $out = (new TaskValidator())->validate($this->valid());

        self::assertSame('Call candidate', $out['title']);
        self::assertSame('high', $out['priority']);
        self::assertSame('14:30:00', $out['due_time']);
        self::assertSame(5, $out['assigned_to']);
    }

    public function test_rejects_missing_title(): void
    {
        $this->expectException(ValidationException::class);
        (new TaskValidator())->validate($this->valid(['title' => '']));
    }

    public function test_rejects_missing_assignee(): void
    {
        $this->expectException(ValidationException::class);
        (new TaskValidator())->validate($this->valid(['assigned_to' => '']));
    }

    public function test_rejects_past_due_date(): void
    {
        $this->expectException(ValidationException::class);
        (new TaskValidator())->validate($this->valid(['due_date' => '2020-01-01']));
    }

    public function test_rejects_malformed_due_time(): void
    {
        $this->expectException(ValidationException::class);
        (new TaskValidator())->validate($this->valid(['due_time' => '2:30pm']));
    }

    public function test_defaults_priority_to_medium_when_blank(): void
    {
        $out = (new TaskValidator())->validate($this->valid(['priority' => '']));

        self::assertSame('medium', $out['priority']);
    }

    public function test_blank_optional_fields_become_null(): void
    {
        $out = (new TaskValidator())->validate($this->valid(['description' => '', 'due_date' => '', 'due_time' => '']));

        self::assertNull($out['description']);
        self::assertNull($out['due_date']);
        self::assertNull($out['due_time']);
    }
}
