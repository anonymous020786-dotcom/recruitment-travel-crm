<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Models\Refund;
use App\Repositories\InvoiceRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\RefundHistoryRepository;
use App\Repositories\RefundRepository;
use App\Services\RefundService;
use App\Support\ListQuery;
use App\Validators\RefundValidator;

/** Refund screens: the register, requesting a refund from a payment, and the approve / reject / mark-paid flow. */
final class RefundController extends CrmController
{
    public function __construct(
        private readonly RefundRepository $refunds,
        private readonly RefundHistoryRepository $history,
        private readonly PaymentRepository $payments,
        private readonly InvoiceRepository $invoices,
        private readonly RefundService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = ListQuery::fromRequest($request, RefundRepository::SORT, RefundRepository::FILTER_KEYS, 'created_at');

        return view_response('crm.refunds.index', [
            'page'   => $this->refunds->paginate($query, $this->scope()),
            'query'  => $query,
            'counts' => $this->refunds->statusCounts($this->scope()),
        ]);
    }

    public function show(string $refund): Response
    {
        $model = $this->find($refund);
        authorize('view', $model);

        return view_response('crm.refunds.show', [
            'refund'     => $model,
            'history'    => $this->history->forRefund($model->id),
            'canApprove' => can('approve', $model) && $model->status === 'pending',
            'canReject'  => can('reject', $model) && in_array($model->status, ['pending', 'approved'], true),
            'canPay'     => can('markPaid', $model) && $model->status === 'approved',
        ]);
    }

    /** Posted from the payment page. */
    public function store(Request $request, string $payment): Response
    {
        $pay = $this->payments->findByPublicId($payment, $this->scope());
        if ($pay === null) {
            abort(404, 'Payment not found.');
        }

        try {
            $input = (new RefundValidator())->request($request->only(['amount', 'method', 'reason', 'invoice']));
            $invoice = null;
            if ($input['invoice'] !== null) {
                $invoice = $this->invoices->findByPublicId($input['invoice'], $this->scope())
                    ?? throw new ValidationException(['invoice' => ['Choose one of the invoices this payment was applied to.']]);
            }
            $refund = $this->service->request($pay, $invoice, $input, $this->currentUser());
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Please check the refund details.');

            return Response::redirect('/payments/' . $pay->publicId . '#refunds');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());

            return Response::redirect('/payments/' . $pay->publicId . '#refunds');
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to request refunds.');

            return Response::redirect('/payments/' . $pay->publicId);
        }

        flash('status', "Refund {$refund->refundNumber} requested. It now needs approval.");

        return Response::redirect('/refunds/' . $refund->publicId);
    }

    public function approve(Request $request, string $refund): Response
    {
        $model = $this->find($refund);

        return $this->run($model, 'Refund approved.', fn () => $this->service->approve($model, $this->currentUser(), $this->version($request, $model)));
    }

    public function reject(Request $request, string $refund): Response
    {
        $model = $this->find($refund);

        return $this->run($model, 'Refund rejected.', fn () => $this->service->reject(
            $model,
            (new RefundValidator())->reason($request->only(['reason'])),
            $this->currentUser(),
            $this->version($request, $model),
        ));
    }

    public function markPaid(Request $request, string $refund): Response
    {
        $model = $this->find($refund);

        return $this->run($model, 'Refund marked as paid.', fn () => $this->service->markPaid($model, $this->currentUser(), $this->version($request, $model)));
    }

    // ---- internals -------------------------------------------------

    private function run(Refund $model, string $success, callable $do): Response
    {
        try {
            $do();
            flash('status', $success);
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Please check the details.');
        } catch (StaleRecordException) {
            session()?->flash('error_toast', 'This refund changed just now. Please review it and try again.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to do that.');
        }

        return Response::redirect('/refunds/' . $model->publicId);
    }

    private function version(Request $request, Refund $model): int
    {
        return (int) $request->input('record_version', $model->recordVersion);
    }

    private function find(string $publicId): Refund
    {
        $model = $this->refunds->findByPublicId($publicId, $this->scope());
        if ($model === null) {
            abort(404, 'Refund not found.');
        }

        return $model;
    }
}
