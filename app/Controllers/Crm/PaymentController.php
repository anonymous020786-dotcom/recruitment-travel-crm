<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Models\Invoice;
use App\Models\Payment;
use App\Repositories\InvoiceRepository;
use App\Repositories\PaymentAllocationRepository;
use App\Repositories\PaymentRepository;
use App\Services\PaymentService;
use App\Support\ListQuery;
use App\Validators\PaymentValidator;

/** Payment screens: the register, recording a payment against an invoice, the payment page, receipts, allocation and reversal. */
final class PaymentController extends CrmController
{
    private const PAYMENT_FIELDS = ['amount', 'method', 'reference', 'paid_at', 'notes', 'idempotency_key'];

    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly PaymentAllocationRepository $allocations,
        private readonly InvoiceRepository $invoices,
        private readonly PaymentService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = ListQuery::fromRequest($request, PaymentRepository::SORT, PaymentRepository::FILTER_KEYS, 'paid_at');

        return view_response('crm.payments.index', ['page' => $this->payments->paginate($query, $this->scope()), 'query' => $query]);
    }

    /** The "record a payment" form for one invoice. */
    public function create(string $invoice): Response
    {
        $model = $this->findInvoice($invoice);
        if (!in_array($model->status, Invoice::COLLECTIBLE, true) || $model->outstandingMinor() <= 0) {
            session()?->flash('error_toast', 'Payments can only be recorded against an issued invoice with something outstanding.');

            return Response::redirect('/invoices/' . $model->publicId);
        }

        return view_response('crm.payments.create', ['invoice' => $model, 'token' => bin2hex(random_bytes(16))]);
    }

    public function store(Request $request, string $invoice): Response
    {
        $model = $this->findInvoice($invoice);

        try {
            $result = $this->service->recordForInvoice($model, (new PaymentValidator())->payment($request->only(self::PAYMENT_FIELDS)), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->all(), '/invoices/' . $model->publicId . '/payments/create');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());

            return Response::redirect('/invoices/' . $model->publicId);
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to record payments.');

            return Response::redirect('/invoices/' . $model->publicId);
        }

        $p = $result['payment'];
        flash('status', $result['created']
            ? "Payment {$p->paymentNumber} recorded — receipt {$p->receiptNumber}."
            : "That payment had already been recorded ({$p->paymentNumber}); nothing was added twice.");

        return Response::redirect('/payments/' . $p->publicId);
    }

    public function show(string $payment): Response
    {
        $model = $this->find($payment);
        authorize('view', $model);

        return view_response('crm.payments.show', [
            'payment'     => $model,
            'allocations' => $this->allocations->forPayment($model->id),
            'canAllocate' => can('allocate', $model) && $model->unallocatedMinor() > 0,
            'canReverse'  => can('reverse', $model) && $model->isRecorded(),
            'canEdit'     => can('edit', $model) && $model->isRecorded(),
            'canReceipt'  => can('viewReceipt', $model),
        ]);
    }

    /** Printable receipt: the snapshot taken when the money was received. */
    public function receipt(string $payment): Response
    {
        $model = $this->find($payment);

        try {
            $receipt = $this->service->receipt($model, $this->currentUser());
        } catch (AuthorizationException) {
            abort(403, 'You cannot view receipts.');
        }
        if ($receipt === null) {
            abort(404, 'No receipt was issued for this payment.');
        }

        return view_response('crm.payments.receipt', ['payment' => $model, 'receipt' => $receipt]);
    }

    public function allocate(Request $request, string $payment): Response
    {
        $model = $this->find($payment);

        return $this->run($model, 'Payment allocated.', function () use ($model, $request): void {
            $a = (new PaymentValidator())->allocation($request->only(['invoice', 'amount']));
            $invoice = $this->invoices->findByNumber($a['invoice'], $this->scope())
                ?? throw new ValidationException(['invoice' => ['No invoice with that number in your branches.']]);
            $this->service->allocate($model, $invoice, $a['amount'], $this->currentUser(), (int) $request->input('record_version', $model->recordVersion));
        });
    }

    public function reverse(Request $request, string $payment): Response
    {
        $model = $this->find($payment);

        return $this->run($model, 'Payment reversed.', function () use ($model, $request): void {
            $this->service->reverse($model, (new PaymentValidator())->reason($request->only(['reason'])), $this->currentUser(), (int) $request->input('record_version', $model->recordVersion));
        });
    }

    public function update(Request $request, string $payment): Response
    {
        $model = $this->find($payment);

        return $this->run($model, 'Payment details updated.', function () use ($model, $request): void {
            $this->service->updateDetails($model, $request->input('reference'), $request->input('notes'), $this->currentUser(), (int) $request->input('record_version', $model->recordVersion));
        });
    }

    // ---- internals -------------------------------------------------

    private function run(Payment $model, string $success, callable $do): Response
    {
        try {
            $do();
            flash('status', $success);
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Please check the details.');
        } catch (StaleRecordException) {
            session()?->flash('error_toast', 'This payment changed just now. Please review it and try again.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to do that.');
        }

        return Response::redirect('/payments/' . $model->publicId);
    }

    private function find(string $publicId): Payment
    {
        $model = $this->payments->findByPublicId($publicId, $this->scope());
        if ($model === null) {
            abort(404, 'Payment not found.');
        }

        return $model;
    }

    private function findInvoice(string $publicId): Invoice
    {
        $model = $this->invoices->findByPublicId($publicId, $this->scope());
        if ($model === null) {
            abort(404, 'Invoice not found.');
        }

        return $model;
    }
}
