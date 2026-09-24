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
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Notifications\NotificationService;
use App\Repositories\InvoiceHistoryRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\PaymentAllocationRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\RefundHistoryRepository;
use App\Repositories\RefundRepository;
use App\Repositories\UserRepository;
use App\Support\Db;
use App\Support\Money;
use App\Support\Sequences;
use App\Support\Ulid;

/**
 * Refunds of money already received. pending → approved → paid (or rejected).
 *
 * Integrity rules (docs/00-ARCHITECTURE.md T9):
 *  - a refund can never exceed what the payment still holds: for a refund tied to an invoice,
 *    what that payment applied to that invoice minus refunds already reserved against the pair;
 *    for a refund of un-invoiced credit, the payment's unallocated credit minus reserved refunds.
 *    Pending, approved and paid refunds all reserve their amount. The payment row is locked while
 *    this is checked, so two requests can never both take the last of the money;
 *  - separation of duties: whoever requested a refund cannot approve it (unless the office
 *    explicitly switches `finance.allow_self_approval` on);
 *  - money moves only at `paid`: then the invoice's `amount_refunded` rises, it may fall back from
 *    paid to partially paid / issued, and the change lands in the invoice history;
 *  - nothing is deleted; every move is history-logged, audited and optimistic-locked.
 */
final class RefundService
{
    public function __construct(
        private readonly Db $db,
        private readonly RefundRepository $refunds,
        private readonly RefundHistoryRepository $history,
        private readonly PaymentRepository $payments,
        private readonly PaymentAllocationRepository $allocations,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceHistoryRepository $invoiceHistory,
        private readonly UserRepository $users,
        private readonly StatusMachine $statuses,
        private readonly Sequences $sequences,
        private readonly Gate $gate,
        private readonly AuditService $audit,
        private readonly BranchScopeResolver $scopes,
        private readonly NotificationService $notifications,
        private readonly bool $allowSelfApproval = false,
    ) {
    }

    /**
     * Ask for (part of) a payment to be returned.
     *
     * @param array{amount:string,method:string,reason:string,invoice:?string} $input from RefundValidator::request() ($invoice is resolved by the caller)
     */
    public function request(Payment $payment, ?Invoice $invoice, array $input, User $actor): Refund
    {
        if (!$this->gate->forUser($actor)->allows('refunds.create')) {
            throw AuthorizationException::forPermission('refunds.create');
        }
        $scope = $this->scopes->resolve($actor);
        if (!$scope->contains($payment->branchId)) {
            throw new AuthorizationException('You cannot request refunds in that branch.');
        }
        if (!$payment->isRecorded()) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'A reversed payment cannot be refunded.', []);
        }
        $minor = Money::toMinor($input['amount']);
        if ($minor <= 0) {
            throw new ValidationException(['amount' => ['The amount must be above zero.']]);
        }

        $refund = $this->db->transaction(function () use ($payment, $invoice, $input, $minor, $actor, $scope): Refund {
            $this->payments->lockRow($payment->id);
            $fresh = $this->payments->findById($payment->id, $scope) ?? throw new \RuntimeException('Payment vanished mid-operation.');
            if (!$fresh->isRecorded()) {
                throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'A reversed payment cannot be refunded.', []);
            }

            $cap = $this->refundableMinor($fresh, $invoice);
            if ($minor > $cap) {
                throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, $cap <= 0
                    ? 'There is nothing left to refund on that ' . ($invoice !== null ? 'invoice allocation' : 'payment credit') . '.'
                    : 'You can refund at most ' . $fresh->currency . ' ' . number_format($cap / 100, 2) . ' here.', []);
            }

            $id = $this->refunds->create([
                'public_id'     => Ulid::generate(),
                'refund_number' => $this->sequences->next('refund', 'RF', 6),
                'payment_id'    => $payment->id,
                'invoice_id'    => $invoice?->id,
                'person_id'     => $payment->personId,
                'branch_id'     => $payment->branchId,
                'amount'        => Money::fromMinor($minor),
                'currency'      => $payment->currency,
                'method'        => $input['method'],
                'reason'        => $input['reason'],
                'status'        => 'pending',
                'created_by'    => $actor->id,
            ]);
            $this->history->append($id, null, 'pending', $input['reason'], $actor->id);
            $this->audit->log('created', 'refunds', 'refund', $id, null, [
                'payment_id' => $payment->id, 'invoice_id' => $invoice?->id, 'amount' => Money::fromMinor($minor), 'method' => $input['method'],
            ], $input['reason'], $actor);

            return $this->reload($id, $scope);
        });

        foreach ($this->users->activeIdsByRoleInBranch('manager', $refund->branchId) as $userId) {
            if ($userId !== $actor->id) {
                $this->notifications->notify(
                    userId: $userId, type: 'refund_requested', title: "Refund to approve: {$refund->customerName}",
                    body: "{$refund->refundNumber} · {$refund->money()} · {$refund->reason}", linkType: 'refund', linkId: $refund->id, linkFragment: null,
                    dedupeKey: "refund:{$refund->id}:requested:u{$userId}",
                );
            }
        }

        return $refund;
    }

    public function approve(Refund $refund, User $actor, int $expectedVersion): Refund
    {
        $this->authorize('approve', $refund, $actor, 'refunds.approve');
        $this->statuses->assert('refund', $refund->status, 'approved');
        if ($refund->createdBy === $actor->id && !$this->allowSelfApproval) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'A refund has to be approved by someone other than the person who requested it.', []);
        }

        $scope = $this->scopes->resolve($actor);
        $updated = $this->db->transaction(function () use ($refund, $actor, $expectedVersion, $scope): Refund {
            if ($this->refunds->updateVersioned($refund->id, ['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => gmdate('Y-m-d H:i:s')], $expectedVersion, $scope) === 0) {
                throw new StaleRecordException('refund', $refund->publicId);
            }
            $this->history->append($refund->id, $refund->status, 'approved', null, $actor->id);
            $this->audit->log('approved', 'refunds', 'refund', $refund->id, ['status' => $refund->status], ['status' => 'approved'], null, $actor);

            return $this->reload($refund->id, $scope);
        });
        $this->tellRequester($updated, $actor, 'approved', "Refund approved: {$updated->customerName}");

        return $updated;
    }

    public function reject(Refund $refund, string $reason, User $actor, int $expectedVersion): Refund
    {
        $this->authorize('reject', $refund, $actor, 'refunds.reject');
        $this->statuses->assert('refund', $refund->status, 'rejected');
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['reason' => ['Please give a reason.']]);
        }

        $scope = $this->scopes->resolve($actor);
        $updated = $this->db->transaction(function () use ($refund, $reason, $actor, $expectedVersion, $scope): Refund {
            if ($this->refunds->updateVersioned($refund->id, ['status' => 'rejected'], $expectedVersion, $scope) === 0) {
                throw new StaleRecordException('refund', $refund->publicId);
            }
            $this->history->append($refund->id, $refund->status, 'rejected', mb_substr($reason, 0, 255), $actor->id);
            $this->audit->log('rejected', 'refunds', 'refund', $refund->id, ['status' => $refund->status], ['status' => 'rejected'], $reason, $actor);

            return $this->reload($refund->id, $scope);
        });
        $this->tellRequester($updated, $actor, 'rejected', "Refund rejected: {$updated->customerName}");

        return $updated;
    }

    /** The money has gone back to the customer. An invoice-linked refund reopens the debt on its invoice. */
    public function markPaid(Refund $refund, User $actor, int $expectedVersion): Refund
    {
        $this->authorize('markPaid', $refund, $actor, 'refunds.mark_paid');
        $this->statuses->assert('refund', $refund->status, 'paid');

        $scope = $this->scopes->resolve($actor);
        $updated = $this->db->transaction(function () use ($refund, $actor, $expectedVersion, $scope): Refund {
            if ($this->refunds->updateVersioned($refund->id, ['status' => 'paid', 'refunded_at' => gmdate('Y-m-d H:i:s')], $expectedVersion, $scope) === 0) {
                throw new StaleRecordException('refund', $refund->publicId);
            }
            $this->history->append($refund->id, $refund->status, 'paid', null, $actor->id);

            if ($refund->invoiceId !== null) {
                $locked = $this->invoices->lockForPayment($refund->invoiceId);
                if ($locked !== null) {
                    $refunded = Money::toMinor($locked['amount_refunded']) + Money::toMinor($refund->amount);
                    $paid = Money::toMinor($locked['amount_paid']);
                    if ($refunded > $paid) {
                        throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'That would refund more than the invoice ever received.', []);
                    }
                    $to = Invoice::statusFor($locked['status'], Money::toMinor($locked['grand_total']), $paid, $refunded);
                    $this->invoices->setRefundState($refund->invoiceId, Money::fromMinor($refunded), $to);
                    if ($to !== $locked['status']) {
                        $this->invoiceHistory->append($refund->invoiceId, $locked['status'], $to, "Refund {$refund->refundNumber} paid", $actor->id);
                    }
                }
            }
            $this->audit->log('paid', 'refunds', 'refund', $refund->id, ['status' => $refund->status], ['status' => 'paid', 'amount' => $refund->amount], null, $actor);

            return $this->reload($refund->id, $scope);
        });
        $this->tellRequester($updated, $actor, 'paid', "Refund paid out: {$updated->customerName}");

        return $updated;
    }

    // ---- internals -------------------------------------------------

    /** What can still be refunded from this payment for the given target (an invoice, or the un-invoiced credit), in minor units. */
    private function refundableMinor(Payment $payment, ?Invoice $invoice): int
    {
        if ($invoice !== null) {
            $applied = Money::toMinor($this->allocations->amountFor($payment->id, $invoice->id));

            return max(0, $applied - Money::toMinor($this->refunds->activeSum($payment->id, $invoice->id)));
        }

        return max(0, Money::toMinor($payment->amount) - Money::toMinor($payment->allocated) - Money::toMinor($this->refunds->activeSum($payment->id, null)));
    }

    private function tellRequester(Refund $refund, User $actor, string $event, string $title): void
    {
        if ($refund->createdBy === null || $refund->createdBy === $actor->id) {
            return;
        }
        $this->notifications->notify(
            userId: $refund->createdBy, type: "refund_{$event}", title: $title,
            body: "{$refund->refundNumber} · {$refund->money()}", linkType: 'refund', linkId: $refund->id, linkFragment: null,
            dedupeKey: "refund:{$refund->id}:{$event}",
        );
    }

    private function authorize(string $ability, Refund $refund, User $actor, string $permission): void
    {
        if (!$this->gate->forUser($actor)->allows($ability, $refund)) {
            throw AuthorizationException::forPermission($permission);
        }
    }

    private function reload(int $id, BranchScope $scope): Refund
    {
        $refund = $this->refunds->findById($id, $scope);
        if ($refund === null) {
            throw new \RuntimeException('Refund vanished mid-operation.');
        }

        return $refund;
    }
}
