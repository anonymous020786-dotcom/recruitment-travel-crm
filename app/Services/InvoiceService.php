<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditService;
use App\Auth\BranchScope;
use App\Auth\BranchScopeResolver;
use App\Auth\Gate;
use App\Domain\StatusMachine;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Models\Application;
use App\Models\Invoice;
use App\Models\TourBooking;
use App\Models\User;
use App\Repositories\InvoiceHistoryRepository;
use App\Repositories\InvoiceLineRepository;
use App\Repositories\InvoiceRepository;
use App\Support\Db;
use App\Support\Money;
use App\Support\Sequences;
use App\Support\Ulid;

/**
 * Invoices. An invoice is billed to a person for an application (recruitment
 * service fee), a tour booking, or "other". Totals are always derived on the
 * server from the lines (in integer minor units — never trusted from the form):
 *
 *   subtotal = Σ round(quantity × unit price);  grand = subtotal − discount + tax
 *
 * Lifecycle (config/statuses.php `invoice`): a draft can be edited freely; issuing
 * freezes it; from then on only payments (Step 9.2) move it between issued /
 * partially_paid / paid. A void invoice is final and needs a reason; nothing that
 * has received money can be voided. Numbers are allocated at creation from a
 * gap-free sequence, so a voided draft still owns its number.
 */
final class InvoiceService
{
    /** Days from issue to due date when none is given. */
    public const DEFAULT_TERMS_DAYS = 15;

    /** DECIMAL(14,2) ceiling in minor units. */
    private const MAX_MINOR = 99_999_999_999_999;

    public function __construct(
        private readonly Db $db,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceLineRepository $lines,
        private readonly InvoiceHistoryRepository $history,
        private readonly StatusMachine $statuses,
        private readonly Sequences $sequences,
        private readonly Gate $gate,
        private readonly AuditService $audit,
        private readonly BranchScopeResolver $scopes,
    ) {
    }

    /** @param array<string,mixed> $data from InvoiceValidator::invoice() */
    public function createForApplication(Application $app, array $data, User $actor): Invoice
    {
        if (in_array($app->status, ['rejected', 'cancelled'], true)) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, "A {$app->status} application cannot be invoiced.", []);
        }
        $personId = (int) $this->db->selectValue('SELECT person_id FROM candidates WHERE id = :id', ['id' => $app->candidateId]);

        return $this->create('application', $app->id, $personId, $app->branchId, 'INR', $data, $actor);
    }

    /** @param array<string,mixed> $data from InvoiceValidator::invoice() */
    public function createForTourBooking(TourBooking $booking, array $data, User $actor): Invoice
    {
        if ($booking->status === 'cancelled') {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'A cancelled booking cannot be invoiced.', []);
        }

        return $this->create('tour_booking', $booking->id, $booking->personId, $booking->branchId, $booking->currency, $data, $actor);
    }

    /** Edit a draft: lines, discount, tax, due date, currency, notes. @param array<string,mixed> $data */
    public function update(Invoice $invoice, array $data, User $actor, int $expectedVersion): Invoice
    {
        $this->authorize('edit', $invoice, $actor, 'invoices.edit');
        if (!$invoice->isDraft()) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Only a draft invoice can be edited. Void it and raise a new one instead.', []);
        }

        $t = $this->totals($data['lines'], $data['discount_total'], $data['tax_total']);
        $scope = $this->scopes->resolve($actor);

        return $this->db->transaction(function () use ($invoice, $data, $t, $expectedVersion, $scope, $actor): Invoice {
            $changes = $this->headerColumns($t, $data['currency'] ?? $invoice->currency, $data['due_on'], $data['notes']);
            if ($this->invoices->updateVersioned($invoice->id, $changes, $expectedVersion, $scope) === 0) {
                throw new StaleRecordException('invoice', $invoice->publicId);
            }
            $this->lines->replace($invoice->id, $t['lines']);
            $fresh = $this->reload($invoice->id, $scope);
            $this->audit->log('updated', 'invoices', 'invoice', $invoice->id, $this->snapshot($invoice), $this->snapshot($fresh), null, $actor);

            return $fresh;
        });
    }

    /** Draft → issued: freezes the amounts and starts the due-date clock. */
    public function issue(Invoice $invoice, User $actor, int $expectedVersion, ?string $dueOn = null): Invoice
    {
        $this->authorize('issue', $invoice, $actor, 'invoices.create');
        $this->statuses->assert('invoice', $invoice->status, 'issued');
        if ($this->lines->count($invoice->id) === 0 || Money::toMinor($invoice->grandTotal) <= 0) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'An invoice needs at least one line and a total above zero before it can be issued.', []);
        }

        $today = gmdate('Y-m-d');
        $due = $dueOn ?? $invoice->dueOn ?? gmdate('Y-m-d', strtotime('+' . self::DEFAULT_TERMS_DAYS . ' days'));
        if ($due < $today) {
            throw new ValidationException(['due_on' => ['The due date cannot be before today.']]);
        }

        $scope = $this->scopes->resolve($actor);

        return $this->db->transaction(function () use ($invoice, $today, $due, $expectedVersion, $scope, $actor): Invoice {
            if ($this->invoices->updateVersioned($invoice->id, ['status' => 'issued', 'issued_on' => $today, 'due_on' => $due], $expectedVersion, $scope) === 0) {
                throw new StaleRecordException('invoice', $invoice->publicId);
            }
            $this->history->append($invoice->id, 'draft', 'issued', null, $actor->id);
            $this->audit->log('issued', 'invoices', 'invoice', $invoice->id, ['status' => 'draft'], ['status' => 'issued', 'issued_on' => $today, 'due_on' => $due], null, $actor);

            return $this->reload($invoice->id, $scope);
        });
    }

    /** Cancel an invoice for good. Refused once any money has been received against it. */
    public function void(Invoice $invoice, string $reason, User $actor, int $expectedVersion): Invoice
    {
        $this->authorize('void', $invoice, $actor, 'invoices.void');
        $this->statuses->assert('invoice', $invoice->status, 'void');

        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['reason' => ['Please give a reason for voiding this invoice.']]);
        }
        if (Money::toMinor($invoice->amountPaid) > 0) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Payments have been received against this invoice. Reverse them first.', []);
        }

        $scope = $this->scopes->resolve($actor);

        return $this->db->transaction(function () use ($invoice, $reason, $expectedVersion, $scope, $actor): Invoice {
            if ($this->invoices->updateVersioned($invoice->id, ['status' => 'void'], $expectedVersion, $scope) === 0) {
                throw new StaleRecordException('invoice', $invoice->publicId);
            }
            $this->history->append($invoice->id, $invoice->status, 'void', mb_substr($reason, 0, 255), $actor->id);
            $this->audit->log('voided', 'invoices', 'invoice', $invoice->id, ['status' => $invoice->status], ['status' => 'void'], $reason, $actor);

            return $this->reload($invoice->id, $scope);
        });
    }

    // ---- internals -------------------------------------------------

    /** @param array<string,mixed> $data */
    private function create(string $type, int $targetId, int $personId, int $branchId, string $defaultCurrency, array $data, User $actor): Invoice
    {
        if (!$this->gate->forUser($actor)->allows('invoices.create')) {
            throw AuthorizationException::forPermission('invoices.create');
        }
        $scope = $this->scopes->resolve($actor);
        if (!$scope->contains($branchId)) {
            throw new AuthorizationException('You cannot invoice in that branch.');
        }

        $t = $this->totals($data['lines'], $data['discount_total'], $data['tax_total']);
        $currency = $data['currency'] ?? $defaultCurrency;

        return $this->db->transaction(function () use ($type, $targetId, $personId, $branchId, $currency, $data, $t, $actor, $scope): Invoice {
            $id = $this->invoices->create($this->headerColumns($t, $currency, $data['due_on'], $data['notes']) + [
                'public_id'        => Ulid::generate(),
                'invoice_number'   => $this->sequences->next('invoice', 'INV', 6),
                'person_id'        => $personId,
                'branch_id'        => $branchId,
                'invoiceable_type' => $type,
                'invoiceable_id'   => $targetId,
                'status'           => 'draft',
                'created_by'       => $actor->id,
            ]);
            $this->lines->replace($id, $t['lines']);
            $this->history->append($id, null, 'draft', null, $actor->id);
            $this->audit->log('created', 'invoices', 'invoice', $id, null, [
                'type' => $type, 'target_id' => $targetId, 'grand_total' => Money::fromMinor($t['grand']), 'lines' => count($t['lines']),
            ], null, $actor);

            return $this->reload($id, $scope);
        });
    }

    /**
     * @param list<array{description:string,quantity:string,unit_price:string}> $lines
     * @return array{lines:list<array{description:string,quantity:string,unit_price:string,line_total:string}>,subtotal:int,discount:int,tax:int,grand:int}
     */
    private function totals(array $lines, string $discount, string $tax): array
    {
        $priced = [];
        $subtotal = 0;
        foreach ($lines as $l) {
            $lineMinor = Money::lineTotalMinor(Money::toMinor($l['quantity']), Money::toMinor($l['unit_price']));
            $subtotal += $lineMinor;
            $priced[] = $l + ['line_total' => Money::fromMinor($lineMinor)];
        }

        $discountMinor = Money::toMinor($discount);
        $taxMinor = Money::toMinor($tax);
        if ($discountMinor > $subtotal) {
            throw new ValidationException(['discount_total' => ['The discount cannot exceed the subtotal.']]);
        }
        $grand = $subtotal - $discountMinor + $taxMinor;
        if ($grand > self::MAX_MINOR) {
            throw new ValidationException(['lines' => ['That total is too large.']]);
        }

        return ['lines' => $priced, 'subtotal' => $subtotal, 'discount' => $discountMinor, 'tax' => $taxMinor, 'grand' => $grand];
    }

    /**
     * @param array{subtotal:int,discount:int,tax:int,grand:int} $t
     * @return array<string,mixed>
     */
    private function headerColumns(array $t, string $currency, ?string $dueOn, ?string $notes): array
    {
        return [
            'currency' => $currency, 'subtotal' => Money::fromMinor($t['subtotal']), 'discount_total' => Money::fromMinor($t['discount']),
            'tax_total' => Money::fromMinor($t['tax']), 'grand_total' => Money::fromMinor($t['grand']), 'due_on' => $dueOn, 'notes' => $notes,
        ];
    }

    private function authorize(string $ability, Invoice $invoice, User $actor, string $permission): void
    {
        if (!$this->gate->forUser($actor)->allows($ability, $invoice)) {
            throw AuthorizationException::forPermission($permission);
        }
    }

    private function reload(int $id, BranchScope $scope): Invoice
    {
        $invoice = $this->invoices->findById($id, $scope);
        if ($invoice === null) {
            throw new \RuntimeException('Invoice vanished mid-operation.');
        }

        return $invoice;
    }

    /** @return array<string,mixed> */
    private function snapshot(Invoice $i): array
    {
        return [
            'currency' => $i->currency, 'subtotal' => $i->subtotal, 'discount_total' => $i->discountTotal,
            'tax_total' => $i->taxTotal, 'grand_total' => $i->grandTotal, 'due_on' => $i->dueOn, 'status' => $i->status,
        ];
    }
}
