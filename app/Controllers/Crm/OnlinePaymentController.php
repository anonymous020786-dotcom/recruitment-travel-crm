<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Payments\OnlinePaymentService;
use App\Repositories\GatewayPaymentRepository;
use App\Repositories\InvoiceRepository;

/** Staff side of online payments: create a pay link for an invoice, or cancel one. The rules live in OnlinePaymentService. */
final class OnlinePaymentController extends CrmController
{
    public function __construct(
        private readonly OnlinePaymentService $online,
        private readonly InvoiceRepository $invoices,
        private readonly GatewayPaymentRepository $links,
    ) {
    }

    public function store(Request $request, string $invoice): Response
    {
        $model = $this->invoices->findByPublicId($invoice, $this->scope()) ?? abort(404, 'Invoice not found.');
        $back = '/invoices/' . $model->publicId . '#online';
        try {
            $id = $this->online->createLink($model, (string) $request->input('gateway', ''), $this->blank((string) $request->input('amount', '')), $this->currentUser());
        } catch (ValidationException $e) {
            session()?->flash('error_toast', implode(' ', array_map(static fn (array $m): string => $m[0], $e->errors())));

            return Response::redirect($back);
        } catch (DomainRuleException | AuthorizationException $e) {
            session()?->flash('error_toast', $e->getMessage());

            return Response::redirect($back);
        }
        flash('status', 'Pay link created. Send it to the customer — it works for ' . OnlinePaymentService::LINK_DAYS . ' days.');
        flash('new_pay_link', $id);

        return Response::redirect($back);
    }

    public function cancel(string $link): Response
    {
        $row = $this->links->findByPublicId($link) ?? abort(404, 'Link not found.');
        $invoice = $this->invoices->findById((int) $row['invoice_id'], $this->scope()) ?? abort(404, 'Link not found.');   // a link in another branch looks missing
        try {
            $this->online->cancel($row, $this->currentUser());
            flash('status', 'Pay link cancelled.');
        } catch (AuthorizationException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/invoices/' . $invoice->publicId . '#online');
    }

    private function blank(string $v): ?string
    {
        return trim($v) === '' ? null : trim($v);
    }
}
