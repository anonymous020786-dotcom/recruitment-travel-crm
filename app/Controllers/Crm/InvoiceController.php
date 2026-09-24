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
use App\Repositories\ApplicationRepository;
use App\Repositories\InvoiceHistoryRepository;
use App\Repositories\InvoiceLineRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\TourBookingRepository;
use App\Services\InvoiceService;
use App\Support\ListQuery;
use App\Validators\InvoiceValidator;

/** Invoice screens: register with money summary, create/edit a draft, invoice page, issue and void. */
final class InvoiceController extends CrmController
{
    private const FIELDS = ['currency', 'due_on', 'discount_total', 'tax_total', 'notes', 'line_description', 'line_quantity', 'line_unit_price'];

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceLineRepository $lines,
        private readonly InvoiceHistoryRepository $history,
        private readonly ApplicationRepository $applications,
        private readonly TourBookingRepository $bookings,
        private readonly InvoiceService $service,
        private readonly \App\Repositories\PaymentAllocationRepository $allocations,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = ListQuery::fromRequest($request, InvoiceRepository::SORT, InvoiceRepository::FILTER_KEYS, 'created_at');

        return view_response('crm.invoices.index', [
            'page'      => $this->invoices->paginate($query, $this->scope()),
            'query'     => $query,
            'summary'   => $this->invoices->summary($this->scope()),
            'canCreate' => can('invoices.create'),
        ]);
    }

    /** Receivables ageing: what is owed and how overdue it is, plus the biggest debtors. */
    public function aging(): Response
    {
        return view_response('crm.invoices.aging', [
            'aging'   => $this->invoices->aging($this->scope()),
            'debtors' => $this->invoices->topDebtors($this->scope()),
        ]);
    }

    public function create(Request $request): Response
    {
        $type = (string) $request->input('type', 'application');
        $reference = strtoupper(trim((string) $request->input('reference', '')));
        $prefill = [];

        // Coming from a booking page: start with its trip as the first line.
        if ($type === 'tour_booking' && $reference !== '' && ($b = $this->bookings->findByNumber($reference, $this->scope())) !== null && (float) $b->totalAmount > 0) {
            $prefill = ['currency' => $b->currency, 'lines' => [[
                'description' => $b->tripLabel() . ' — ' . $b->travellersLabel(),
                'quantity' => '1.00', 'unit_price' => number_format((float) $b->totalAmount, 2, '.', ''),
            ]]];
        }

        return view_response('crm.invoices.create', ['type' => $type, 'reference' => $reference, 'prefill' => $prefill]);
    }

    public function store(Request $request): Response
    {
        try {
            $validator = new InvoiceValidator();
            $target = $validator->target($request->only(['type', 'reference']));
            $data = $validator->invoice($request->only(self::FIELDS));

            if ($target['type'] === 'application') {
                $app = $this->applications->findByNumber($target['reference'], $this->scope())
                    ?? throw new ValidationException(['reference' => ['No application with that number in your branches.']]);
                $invoice = $this->service->createForApplication($app, $data, $this->currentUser());
            } else {
                $booking = $this->bookings->findByNumber($target['reference'], $this->scope())
                    ?? throw new ValidationException(['reference' => ['No tour booking with that number in your branches.']]);
                $invoice = $this->service->createForTourBooking($booking, $data, $this->currentUser());
            }
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->all(), '/invoices/create');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());

            return redirect_with_errors([], $request->all(), '/invoices/create');
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You cannot create invoices there.');

            return redirect_with_errors([], $request->all(), '/invoices/create');
        }

        flash('status', "Invoice {$invoice->invoiceNumber} created as a draft.");

        return Response::redirect('/invoices/' . $invoice->publicId);
    }

    public function show(string $invoice): Response
    {
        $model = $this->find($invoice);
        authorize('view', $model);

        return view_response('crm.invoices.show', [
            'invoice'   => $model,
            'lines'     => $this->lines->forInvoice($model->id),
            'history'   => $this->history->forInvoice($model->id),
            'payments'  => can('payments.view') ? $this->allocations->forInvoice($model->id) : null,
            'canPay'    => can('payments.create') && in_array($model->status, Invoice::COLLECTIBLE, true) && $model->outstandingMinor() > 0,
            'canEdit'   => can('edit', $model) && $model->isDraft(),
            'canIssue'  => can('issue', $model) && $model->isDraft(),
            'canVoid'   => can('void', $model) && $model->status !== 'void' && $model->status !== 'paid',
        ]);
    }

    public function edit(string $invoice): Response
    {
        $model = $this->find($invoice);
        authorize('edit', $model);
        if (!$model->isDraft()) {
            session()?->flash('error_toast', 'Only a draft invoice can be edited.');

            return Response::redirect('/invoices/' . $model->publicId);
        }

        return view_response('crm.invoices.edit', ['invoice' => $model, 'lines' => $this->lines->forInvoice($model->id)]);
    }

    public function update(Request $request, string $invoice): Response
    {
        $model = $this->find($invoice);
        authorize('edit', $model);

        try {
            $this->service->update($model, (new InvoiceValidator())->invoice($request->only(self::FIELDS)), $this->currentUser(), (int) $request->input('record_version', $model->recordVersion));
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->all(), '/invoices/' . $model->publicId . '/edit');
        } catch (StaleRecordException) {
            session()?->flash('error_toast', 'This invoice changed just now. Please review it and try again.');

            return Response::redirect('/invoices/' . $model->publicId);
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());

            return Response::redirect('/invoices/' . $model->publicId);
        }

        flash('status', 'Invoice updated.');

        return Response::redirect('/invoices/' . $model->publicId);
    }

    public function issue(Request $request, string $invoice): Response
    {
        $model = $this->find($invoice);

        return $this->run($model, 'Invoice issued.', function () use ($model, $request): void {
            $due = trim((string) $request->input('due_on', ''));
            $this->service->issue($model, $this->currentUser(), (int) $request->input('record_version', $model->recordVersion), $due !== '' ? $due : null);
        });
    }

    public function void(Request $request, string $invoice): Response
    {
        $model = $this->find($invoice);

        return $this->run($model, 'Invoice voided.', function () use ($model, $request): void {
            $this->service->void($model, (string) $request->input('reason', ''), $this->currentUser(), (int) $request->input('record_version', $model->recordVersion));
        });
    }

    // ---- internals -------------------------------------------------

    private function run(Invoice $model, string $success, callable $do): Response
    {
        try {
            $do();
            flash('status', $success);
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Please check the details.');
        } catch (StaleRecordException) {
            session()?->flash('error_toast', 'This invoice changed just now. Please review it and try again.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to do that.');
        }

        return Response::redirect('/invoices/' . $model->publicId);
    }

    private function find(string $publicId): Invoice
    {
        $model = $this->invoices->findByPublicId($publicId, $this->scope());
        if ($model === null) {
            abort(404, 'Invoice not found.');
        }

        return $model;
    }
}
