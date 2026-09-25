<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Controllers\Controller;
use App\Exceptions\DomainRuleException;
use App\Http\Request;
use App\Http\Response;
use App\Payments\GatewayException;
use App\Payments\OnlinePaymentService;
use App\Support\Logger;

/**
 * The customer's side of an online payment (`/pay/<id>`), and the endpoint gateways call (`/webhooks/<gateway>`).
 *
 * Pay pages are public but unguessable (a 128-bit id), never cached, never indexed, and show only what the customer needs: the
 * invoice number, their first name and the amount. The webhook trusts nothing until the gateway's signature verifies.
 */
final class PayController extends Controller
{
    /** The only request headers a gateway signature can depend on — read by name, so no arbitrary header ever reaches an adapter. */
    private const HEADERS = ['stripe-signature', 'x-razorpay-signature', 'x-webhook-signature', 'x-webhook-timestamp', 'x-verify', 'content-type',
        'paypal-transmission-id', 'paypal-transmission-time', 'paypal-transmission-sig', 'paypal-cert-url', 'paypal-auth-algo'];

    public function __construct(
        private readonly OnlinePaymentService $online,
        private readonly Logger $logger,
    ) {
    }

    public function show(string $id): Response
    {
        $page = $this->online->page($id);
        if ($page === null) {
            abort(404, 'This payment link does not exist.');
        }

        return $this->page('public.pay.show', ['page' => $page]);
    }

    /** "Pay now": create the checkout at the gateway and send the customer there (a redirect, or a form that posts to it). */
    public function go(string $id): Response
    {
        try {
            $checkout = $this->online->start($id);
        } catch (DomainRuleException) {
            return $this->page('public.pay.show', ['page' => $this->online->page($id) ?? abort(404, 'This payment link does not exist.')])->withStatus(410);
        } catch (GatewayException $e) {
            $this->logger->warning('payment checkout could not be started: {message}', ['message' => $e->getMessage()]);

            return $this->page('public.pay.show', ['page' => $this->online->page($id) ?? abort(404), 'problem' => 'The payment service is not available right now. Please try again in a few minutes, or contact us.'])->withStatus(502);
        }

        if ($checkout->method === 'post') {
            return $this->page('public.pay.post', ['checkout' => $checkout]);
        }

        return Response::redirect($checkout->url, 302, allowExternal: true)->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    /** The customer's browser comes back from the gateway (a GET redirect or a POST of a signed result). */
    public function returned(Request $request, string $id): Response
    {
        $result = $this->online->customerReturned($id, $request->all(), $request->isMethod('POST') ? $request->all() : []);
        $page = $this->online->page($id) ?? abort(404, 'This payment link does not exist.');

        return $this->page('public.pay.result', ['page' => $page, 'status' => $result['status'], 'cancelled' => $request->query('cancelled') !== null]);
    }

    /** POST /webhooks/<gateway> — answer quickly; the verdict is in the status code. */
    public function webhook(Request $request, string $gateway): Response
    {
        $headers = [];
        foreach (self::HEADERS as $name) {
            $value = $request->header($name);
            if ($value !== null && $value !== '') {
                $headers[$name] = $value;
            }
        }
        $r = $this->online->webhook(strtolower($gateway), $request->rawBody(), $headers, $request->isMethod('POST') ? $request->all() : []);
        if ($r['status'] >= 500 || $r['status'] === 401) {
            $this->logger->warning('gateway webhook {gateway} answered {status} ({result})', ['gateway' => $gateway, 'status' => $r['status'], 'result' => $r['result']]);
        }

        return Response::json(['result' => $r['result']], $r['status'])->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string,mixed> $data */
    private function page(string $view, array $data): Response
    {
        return view_response($view, $data)->withHeaders(['Cache-Control' => 'private, no-store, max-age=0', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer']);
    }
}
