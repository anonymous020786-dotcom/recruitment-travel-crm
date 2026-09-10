<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Sequences;
use Tests\Support\DbTestCase;

final class SequencesTest extends DbTestCase
{
    private Sequences $seq;
    private string $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seq = new Sequences($this->db);
        $this->scope = 'test:' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement('DELETE FROM number_sequences WHERE scope LIKE ?', ['test:%']);
    }

    public function test_requires_a_transaction(): void
    {
        $this->expectException(\LogicException::class);
        $this->seq->nextValue($this->scope);
    }

    public function test_gap_free_sequence(): void
    {
        $values = [];
        for ($i = 0; $i < 5; $i++) {
            $values[] = $this->db->transaction(fn () => $this->seq->nextValue($this->scope));
        }
        self::assertSame([1, 2, 3, 4, 5], $values);
    }

    public function test_rolled_back_transaction_does_not_consume_a_number(): void
    {
        $this->db->transaction(fn () => $this->seq->nextValue($this->scope)); // -> 1

        try {
            $this->db->transaction(function (): void {
                $this->seq->nextValue($this->scope); // -> 2, but we roll back
                throw new \RuntimeException('abort');
            });
        } catch (\RuntimeException) {
        }

        $next = $this->db->transaction(fn () => $this->seq->nextValue($this->scope));
        self::assertSame(2, $next, 'the rolled-back allocation is not spent');
    }

    public function test_formatted_number(): void
    {
        $number = $this->db->transaction(fn () => $this->seq->next('test', 'LEAD', 6));
        self::assertMatchesRegularExpression('/^LEAD-\d{4}-\d{6}$/', $number);
    }
}
