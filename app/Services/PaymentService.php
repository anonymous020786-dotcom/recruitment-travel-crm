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
use App\Exceptions\QueryException;
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Repositories\InvoiceHistoryRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\PaymentAllocationRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\ReceiptRepository;
use App\Support\Db;
use App\Support\Money;
use App\Support\Sequences;
use App\Support\Ulid;

/**
 * Payments received against invoices — the money ledger.
 *
 * Integrity rules (docs/00-ARCHITECTURE.md T9 / §36), all enforced here inside one
 * transaction and backed by DB constraints (`amount > 0`, unique idempotency key,
 * one allocation row per payment+invoice):
 *  - amounts are exact integer minor units; nothing is taken from the form but the
 *    payer's own inputs — the invoice's state is re-read under a row lock;
 *  - a payment is applied to an invoice only up to what is still outstanding, in the
 *    invoice's currency, for the same person; any remainder stays as unallocated credit;
 *  - a double submit (same idempotency key) returns the original payment, never a second;
 *  - every payment gets a gap-free payment number and receipt number and an immutable
 *    receipt snapshot; it is never edited or deleted — only reversed, which releases
 *    everything it had been applied to and restores the invoice's status.
 */
final class PaymentService
{
    private const MAX_MINOR = 99_999_999_999_999;

    public function __construct(
        private readonly Db $db,
        private readonly PaymentRepository $payments,
        private readonly PaymentAllocationRepository $allocations,
        private readonly ReceiptRepository $receipts,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceHistoryRepository $invoiceHistory,
        private readonly StatusMachine $statuses,
        private readonly Sequences $sequences,
        private readonly Gate $gate,
        private readonly AuditService $audit,
        private readonly BranchScopeResolver $scopes,
    ) {
    }

    /**
     * Receive money for an invoice. The amount is applied to the invoice up to its outstanding balance;
     * any excess is kept on the payment as unallocated credit.
     *
     * @param array{amount:string,method:string,reference:?string,paid_at:string,notes:?string,idempotency_key:?string} $input from PaymentValidator::payment()
     * @return array{payment:Payment,created:bool} `created` is false when the idempotency key had already been used
     */
    public function recordForInvoice(Invoice $invoice, array $input, User $actor): array
    {
        if (!$this->gate->forUser($actor)->allows('payments.create')) {
            throw AuthorizationException::forPermission('payments.create');
        }
        $scope = $this->scopes->resolve($actor);
        if (!$scope->contains($invoice->branchId)) {
            throw new AuthorizationException('You cannot record payments in that branch.');
        }

        $key = $input['idempotency_key'];
        if ($key !== null && ($existing = $this->payments->findByIdempotencyKey($key, $scope)) !== null) {
            return ['payment' => $existing, 'created' => false];
        }

        $amountMinor = Money::toMinor($input['amount']);
        if ($amountMinor <= 0 || $amountMinor > self::MAX_MINOR) {
            throw new ValidationException(['amount' => ['Enter an amount above zero.']]);
        }

        try {
            $payment = $this->db->transaction(fn (): Payment => $this->record($invoice, $input, $amountMinor, $actor, $scope));
        } catch (QueryException $e) {
            // Two identical submissions raced past the check above; the unique key let exactly one in.
            if ($key !== null && ($existing = $this->payments->findByIdempotencyKey($key, $scope)) !== null) {
                return ['payment' => $existing, 'created' => false];
            }
            throw $e;
        }

        return ['payment' => $payment, 'created' => true];
    }

    /** Apply part of a payment's unallocated credit to another (or the same) invoice. */
    public function allocate(Payment $payment, Invoice $invoice, string $amount, User $actor, int $expectedVersion): Payment
    {
        $this->authorize('allocate', $payment, $actor, 'allocations.manage');
        $scope = $this->scopes->resolve($actor);
        if (!$scope->contains($invoice->branchId)) {
            throw new AuthorizationException('You cannot allocate to an invoice in that branch.');
        }
        if (!$payment->isRecorded()) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'A reversed payment cannot be allocated.', []);
        }
        $minor = Money::toMinor($amount);
        if ($minor <= 0) {
            throw new ValidationException(['amount' => ['Enter an amount above zero.']]);
        }
        if ($minor > $payment->unallocatedMinor()) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Only ' . $payment->money($payment->unallocated()) . ' of this payment is left to allocate.', []);
        }

        return $this->db->transaction(function () use ($payment, $invoice, $minor, $expectedVersion, $scope, $actor): Payment {
            // Bumping the payment's version first serialises concurrent allocations of the same payment.
            if ($this->payments->updateVersioned($payment->id, [], $expectedVersion, $scope) === 0) {
                throw new StaleRecordException('payment', $payment->publicId);
            }
            $this->applyToInvoice($payment, $invoice, $minor, $actor, 'Payment ' . $payment->paymentNumber);
            $this->audit->log('allocated', 'payments', 'payment', $payment->id, null, ['invoice_id' => $invoice->id, 'amount' => Money::fromMinor($minor)], null, $actor);

            return $this->reload($payment->id, $scope);
        });
    }

    /**
     * Undo a payment: it becomes `reversed` (final), and every invoice it had been applied to loses that
     * amount and returns to the status the remaining money justifies. Refused while a refund is open against it.
     */
    public function reverse(Payment $payment, string $reason, User $actor, int $expectedVersion): Payment
    {
        $this->authorize('reverse', $payment, $actor, 'payments.reverse');
        $this->statuses->assert('payment', $payment->status, 'reversed');
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['reason' => ['Please give a reason for reversing this payment.']]);
        }
        if ($this->payments->hasOpenRefund($payment->id)) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'This payment has a refund against it. Deal with the refund first.', []);
        }

        $scope = $this->scopes->resolve($actor);

        return $this->db->transaction(function () use ($payment, $reason, $expectedVersion, $scope, $actor): Payment {
            if ($this->payments->updateVersioned($payment->id, ['status' => 'reversed', 'reversed_reason' => mb_substr($reason, 0, 255)], $expectedVersion, $scope) === 0) {
                throw new StaleRecordException('payment', $payment->publicId);
            }
            foreach ($this->allocations->forPayment($payment->id) as $a) {
                $locked = $this->invoices->lockForPayment($a['invoice_id']);
                if ($locked === null) {
                    continue;
                }
                $paid = max(0, Money::toMinor($locked['amount_paid']) - Money::toMinor($a['amount']));
                $to = Invoice::statusFor($locked['status'], Money::toMinor($locked['grand_total']), $paid, Money::toMinor($locked['amount_refunded']));
                $this->invoices->setPaymentState($a['invoice_id'], Money::fromMinor($paid), $to);
                if ($to !== $locked['status']) {
                    $this->invoiceHistory->append($a['invoice_id'], $locked['status'], $to, "Payment {$payment->paymentNumber} reversed", $actor->id);
                }
            }
            $this->audit->log('reversed', 'payments', 'payment', $payment->id, ['status' => 'recorded'], ['status' => 'reversed'], $reason, $actor);

            return $this->reload($payment->id, $scope);
        });
    }

    /** Correct the reference / notes of a payment. The amount, method and date are part of the ledger and never change. */
    public function updateDetails(Payment $payment, ?string $reference, ?string $notes, User $actor, int $expectedVersion): Payment
    {
        $this->authorize('edit', $payment, $actor, 'payments.edit');
        if (!$payment->isRecorded()) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'A reversed payment cannot be edited.', []);
        }
        $reference = $reference !== null && trim($reference) !== '' ? mb_substr(trim($reference), 0, 120) : null;
        if ($reference === null && in_array($payment->method, Payment::NEEDS_REFERENCE, true)) {
            throw new ValidationException(['reference' => ['A ' . strtolower($payment->methodLabel()) . ' payment needs its reference.']]);
        }
        $notes = $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 500) : null;

        $scope = $this->scopes->resolve($actor);

        return $this->db->transaction(function () use ($payment, $reference, $notes, $expectedVersion, $scope, $actor): Payment {
            if ($this->payments->updateVersioned($payment->id, ['reference' => $reference, 'notes' => $notes], $expectedVersion, $scope) === 0) {
                throw new StaleRecordException('payment', $payment->publicId);
            }
            $this->audit->log('updated', 'payments', 'payment', $payment->id, ['reference' => $payment->reference, 'notes' => $payment->notes], ['reference' => $reference, 'notes' => $notes], null, $actor);

            return $this->reload($payment->id, $scope);
        });
    }

    /** @return array{receipt_number:string,issued_at:string,snapshot:array<string,mixed>}|null */
    public function receipt(Payment $payment, User $actor): ?array
    {
        $this->authorize('viewReceipt', $payment, $actor, 'receipts.view');

        return $this->receipts->forPayment($payment->id);
    }

    // ---- internals -------------------------------------------------

    /** @param array<string,mixed> $input */
    private function record(Invoice $invoice, array $input, int $amountMinor, User $actor, BranchScope $scope): Payment
    {
        $locked = $this->invoices->lockForPayment($invoice->id)
            ?? throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Invoice not found.', [], 404);
        if (!in_array($locked['status'], Invoice::COLLECTIBLE, true)) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, $locked['status'] === 'paid'
                ? 'This invoice is already fully paid.'
                : 'Payments can only be recorded against an issued invoice.', []);
        }
        $outstanding = $this->outstandingMinor($locked);
        if ($outstanding <= 0) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'This invoice has nothing outstanding.', []);
        }

        $paymentNumber = $this->sequences->next('payment', 'PAY', 6);
        $receiptNumber = $this->sequences->next('receipt', 'RCT', 6);
        $id = $this->payments->create([
            'public_id'       => Ulid::generate(),
            'payment_number'  => $paymentNumber,
            'receipt_number'  => $receiptNumber,
            'person_id'       => $locked['person_id'],
            'branch_id'       => $invoice->branchId,
            'amount'          => Money::fromMinor($amountMinor),
            'currency'        => $locked['currency'],
            'method'          => $input['method'],
            'reference'       => $input['reference'],
            'paid_at'         => $input['paid_at'],
            'idempotency_key' => $input['idempotency_key'],
            'status'          => 'recorded',
            'notes'           => $input['notes'],
            'created_by'      => $actor->id,
        ]);

        $applied = min($amountMinor, $outstanding);
        $payment = $this->reload($id, $scope);
        $this->applyToInvoice($payment, $invoice, $applied, $actor, "Payment {$paymentNumber}");

        $this->receipts->issue($receiptNumber, $id, $actor->id, $this->snapshot($this->reload($id, $scope), $actor));
        $this->audit->log('created', 'payments', 'payment', $id, null, [
            'payment_number' => $paymentNumber, 'amount' => Money::fromMinor($amountMinor), 'currency' => $locked['currency'],
            'method' => $input['method'], 'invoice_id' => $invoice->id, 'applied' => Money::fromMinor($applied),
        ], null, $actor);

        return $this->reload($id, $scope);
    }

    /** Add `$minor` of a payment to an invoice: allocation row, invoice totals and status, history. Caller is in a transaction. */
    private function applyToInvoice(Payment $payment, Invoice $invoice, int $minor, User $actor, string $reason): void
    {
        $locked = $this->invoices->lockForPayment($invoice->id)
            ?? throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Invoice not found.', [], 404);

        if ($locked['person_id'] !== $payment->personId) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'A payment can only be applied to its own customer\'s invoices.', []);
        }
        if ($locked['currency'] !== $payment->currency) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, "This payment is in {$payment->currency}; the invoice is in {$locked['currency']}.", []);
        }
        if (!in_array($locked['status'], Invoice::COLLECTIBLE, true)) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Only an issued invoice can receive payments.', []);
        }
        if ($minor > $this->outstandingMinor($locked)) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'That is more than the invoice has outstanding (' . $invoice->currency . ' ' . number_format($this->outstandingMinor($locked) / 100, 2) . ').', []);
        }

        $paid = Money::toMinor($locked['amount_paid']) + $minor;
        $to = Invoice::statusFor($locked['status'], Money::toMinor($locked['grand_total']), $paid, Money::toMinor($locked['amount_refunded']));

        $this->allocations->add($payment->id, $invoice->id, Money::fromMinor($minor), $actor->id);
        $this->invoices->setPaymentState($invoice->id, Money::fromMinor($paid), $to);
        if ($to !== $locked['status']) {
            $this->invoiceHistory->append($invoice->id, $locked['status'], $to, $reason, $actor->id);
        }
    }

    /**
     * @param array{grand_total:string,amount_paid:string,amount_refunded:string} $locked
     */
    private function outstandingMinor(array $locked): int
    {
        return max(0, Money::toMinor($locked['grand_total']) - (Money::toMinor($locked['amount_paid']) - Money::toMinor($locked['amount_refunded'])));
    }

    /** @return array<string,mixed> what the receipt says, frozen at the moment of receipt */
    private function snapshot(Payment $payment, User $actor): array
    {
        $branch = (string) $this->db->selectValue('SELECT name FROM branches WHERE id = :id', ['id' => $payment->branchId], '');

        return [
            'receipt_number' => $payment->receiptNumber,
            'payment_number' => $payment->paymentNumber,
            'branch'         => $branch,
            'customer'       => ['name' => $payment->customerName, 'phone' => $payment->customerPhone],
            'amount'         => $payment->amount,
            'currency'       => $payment->currency,
            'method'         => $payment->methodLabel(),
            'reference'      => $payment->reference,
            'paid_at'        => $payment->paidAt,
            'allocations'    => array_map(static fn (array $a): array => ['invoice_number' => $a['invoice_number'], 'amount' => $a['amount']], $this->allocations->forPayment($payment->id)),
            'received_by'    => $actor->name,
        ];
    }

    private function authorize(string $ability, Payment $payment, User $actor, string $permission): void
    {
        if (!$this->gate->forUser($actor)->allows($ability, $payment)) {
            throw AuthorizationException::forPermission($permission);
        }
    }

    private function reload(int $id, BranchScope $scope): Payment
    {
        $payment = $this->payments->findById($id, $scope);
        if ($payment === null) {
            throw new \RuntimeException('Payment vanished mid-operation.');
        }

        return $payment;
    }
}
