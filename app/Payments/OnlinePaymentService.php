<?php

declare(strict_types=1);

namespace App\Payments;

use App\Audit\AuditService;
use App\Auth\BranchScope;
use App\Auth\BranchScopeResolver;
use App\Auth\PermissionService;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Integrations\Credentials;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\NotificationService;
use App\Repositories\GatewayPaymentRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\UserRepository;
use App\Services\PaymentService;
use App\Support\Application;
use App\Support\Db;
use App\Support\Money;
use App\Support\Ulid;

/**
 * Collecting invoices online.
 *
 *  - Staff create a pay link for an invoice (`createLink`): the exact outstanding balance — or a smaller amount for an instalment — and
 *    one of the gateways that is configured and can collect the invoice's currency. Nothing is sent to the gateway yet.
 *  - The customer opens `/pay/<id>` and presses Pay: only then is a checkout created at the gateway (`start`), so a link never
 *    holds an expired session, and the customer is sent there.
 *  - The result arrives by a signed webhook or a verified return. `handleEvent` is the only place a payment is ever recorded, and it
 *    records it exactly once: the row is locked, the gateway's payment id is unique, and the payment carries an idempotency key.
 *    Amount and currency must match what was asked; anything else is set aside as "mismatch" and staff are told — the books are
 *    never adjusted on a guess.
 */
final class OnlinePaymentService
{
    public const LINK_DAYS = 7;
    public const PAYABLE = ['created', 'pending', 'failed'];

    public function __construct(
        private readonly GatewayPaymentRepository $repo,
        private readonly GatewayRegistry $gateways,
        private readonly InvoiceRepository $invoices,
        private readonly PaymentService $payments,
        private readonly UserRepository $users,
        private readonly PermissionService $permissions,
        private readonly BranchScopeResolver $scopes,
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
        private readonly Credentials $credentials,
        private readonly Application $app,
        private readonly Db $db,
    ) {
    }

    // ---- staff: creating a link ------------------------------------------------------------------------------------------

    /**
     * @param string|null $amount decimal amount for an instalment; null = the whole outstanding balance
     * @return string the new link's public id
     * @throws AuthorizationException|DomainRuleException|ValidationException
     */
    public function createLink(Invoice $invoice, string $gateway, ?string $amount, User $actor): string
    {
        if (!$this->permissions->userCan($actor, 'payments.create')) {
            throw AuthorizationException::forPermission('payments.create');
        }
        if (!$this->scopes->resolve($actor)->contains($invoice->branchId)) {
            throw new AuthorizationException('You cannot collect payments in that branch.');
        }
        if (!in_array($invoice->status, Invoice::COLLECTIBLE, true) || $invoice->outstandingMinor() <= 0) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Only an issued invoice with a balance can be paid online.', [], 422);
        }
        $adapter = $this->gateways->usable($gateway);
        if ($adapter === null) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'That payment gateway is not set up (or is switched off). The super admin configures it under Admin → Integrations.', [], 422);
        }
        if (!in_array(strtoupper($invoice->currency), $adapter->currencies(), true)) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, $this->label($gateway) . ' cannot collect ' . $invoice->currency . '. Choose another gateway.', [], 422);
        }

        $minor = $invoice->outstandingMinor();
        if ($amount !== null && trim($amount) !== '') {
            if (preg_match('/^\d{1,10}(\.\d{1,2})?$/D', trim($amount)) !== 1 || Money::toMinor(trim($amount)) <= 0 || Money::toMinor(trim($amount)) > $invoice->outstandingMinor()) {
                throw new ValidationException(['amount' => ['Enter an amount above zero and no more than the balance of ' . $invoice->money($invoice->outstanding()) . '.']]);
            }
            $minor = Money::toMinor(trim($amount));
        }

        $publicId = Ulid::generate();
        $id = $this->repo->create([
            'public_id' => $publicId, 'reference' => 'GP' . strtoupper(bin2hex(random_bytes(9))), 'invoice_id' => $invoice->id, 'gateway' => $gateway,
            'amount' => Money::fromMinor($minor), 'currency' => strtoupper($invoice->currency), 'status' => 'created',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::LINK_DAYS * 86400), 'created_by' => $actor->id,
        ]);
        $this->audit->log('online_payment_link_created', 'payments', 'invoice', $invoice->id, null, ['gateway' => $gateway, 'amount' => Money::fromMinor($minor), 'link' => $id], null, $actor);

        return $publicId;
    }

    /** @param array<string,mixed> $row */
    public function cancel(array $row, User $actor): void
    {
        if (!$this->permissions->userCan($actor, 'payments.create')) {
            throw AuthorizationException::forPermission('payments.create');
        }
        if (in_array($row['status'], ['created', 'pending', 'failed'], true)) {
            $this->repo->update((int) $row['id'], ['status' => 'cancelled']);
            $this->audit->log('online_payment_link_cancelled', 'payments', 'invoice', (int) $row['invoice_id'], null, ['link' => (int) $row['id']], null, $actor);
        }
    }

    /**
     * What the invoice's "Collect online" card shows.
     *
     * @return array{gateways:array<string,string>,links:list<array<string,mixed>>,can_create:bool,base:string,new_link:?string}
     */
    public function panel(Invoice $invoice, User $viewer, ?string $newLink = null): array
    {
        $canCreate = $this->permissions->userCan($viewer, 'payments.create') && in_array($invoice->status, Invoice::COLLECTIBLE, true) && $invoice->outstandingMinor() > 0;
        $links = array_map(fn (array $l): array => $l + ['gateway_label' => $this->label((string) $l['gateway'])], $this->repo->forInvoice($invoice->id));

        return [
            'gateways' => $canCreate ? $this->gateways->forCurrency($invoice->currency) : [], 'links' => $links, 'can_create' => $canCreate,
            'base' => rtrim((string) $this->app->config()->get('app.url', ''), '/'), 'new_link' => $newLink !== null && preg_match('/^[0-9A-Z]{26}$/D', $newLink) === 1 ? $newLink : null,
        ];
    }

    // ---- the customer: opening the page and going to the gateway ---------------------------------------------------------------

    /**
     * What the public pay page may show, or null for an unknown link. Never includes internal ids or other customers' data.
     *
     * @return array{public_id:string,status:string,payable:bool,expired:bool,amount:string,currency:string,invoice_number:string,customer:string,description:string,gateway:string,gateway_label:string}|null
     */
    public function page(string $publicId): ?array
    {
        $row = $this->repo->findByPublicId($publicId);
        $invoice = $row === null ? null : $this->invoices->findById((int) $row['invoice_id'], BranchScope::orgWide());
        if ($row === null || $invoice === null) {
            return null;
        }
        $expired = strtotime((string) $row['expires_at'] . ' UTC') < time() && $row['status'] !== 'paid';
        $payable = !$expired && in_array($row['status'], self::PAYABLE, true) && in_array($invoice->status, Invoice::COLLECTIBLE, true)
            && $invoice->outstandingMinor() >= Money::toMinor((string) $row['amount']) && $this->gateways->usable((string) $row['gateway']) !== null;

        return [
            'public_id' => (string) $row['public_id'], 'status' => $expired && $row['status'] !== 'paid' ? 'expired' : (string) $row['status'], 'payable' => $payable, 'expired' => $expired,
            'amount' => (string) $row['amount'], 'currency' => (string) $row['currency'], 'invoice_number' => $invoice->invoiceNumber,
            'customer' => $this->firstName($invoice->customerName), 'description' => 'Invoice ' . $invoice->invoiceNumber . ' — ' . $this->business(),
            'gateway' => (string) $row['gateway'], 'gateway_label' => $this->label((string) $row['gateway']),
        ];
    }

    /**
     * Create the checkout at the gateway for this link and return where to send the customer.
     *
     * @throws DomainRuleException|GatewayException
     */
    public function start(string $publicId): Checkout
    {
        $info = $this->page($publicId);
        if ($info === null || !$info['payable']) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'This payment link can no longer be used.', [], 410);
        }
        $row = (array) $this->repo->findByPublicId($publicId);
        $invoice = $this->invoices->findById((int) $row['invoice_id'], BranchScope::orgWide()) ?? throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Invoice not found.', [], 404);
        $gateway = $this->gateways->usable((string) $row['gateway']) ?? throw new GatewayException('The payment gateway is not available right now.');

        $base = rtrim((string) $this->app->config()->get('app.url', ''), '/');
        $checkout = $gateway->createCheckout(new CheckoutRequest(
            (string) $row['reference'], Money::toMinor((string) $row['amount']), (string) $row['currency'], $info['description'], $invoice->customerName,
            null, $invoice->customerPhone, $base . '/pay/' . $publicId . '/return', $base . '/webhooks/' . $row['gateway'],
        ));
        $this->repo->update((int) $row['id'], [
            'status' => 'pending', 'provider_order_id' => $checkout->providerOrderId, 'checkout_method' => $checkout->method,
            'checkout_url' => $checkout->url, 'checkout_fields' => $checkout->fields === [] ? null : json_encode($checkout->fields, JSON_UNESCAPED_SLASHES),
        ]);

        return $checkout;
    }

    // ---- gateway → us -----------------------------------------------------------------------------------------------------------

    /**
     * A server-to-server delivery. Returns the HTTP status to answer and a short result word. The signature is verified first;
     * nothing else about the message is trusted until it is.
     *
     * @param array<string,string> $headers lower-cased
     * @param array<string,mixed> $post
     * @return array{status:int,result:string}
     */
    public function webhook(string $gatewayKey, string $rawBody, array $headers, array $post): array
    {
        if (strlen($rawBody) > 65536) {
            return ['status' => 413, 'result' => 'too_large'];   // cheapest check first: nothing large is ever read further
        }
        if (!GatewayRegistry::knows($gatewayKey)) {
            return ['status' => 404, 'result' => 'unknown_gateway'];
        }
        $gateway = $this->gateways->usable($gatewayKey);
        if ($gateway === null) {
            return ['status' => 409, 'result' => 'not_configured'];
        }

        try {
            $event = $gateway->parseWebhook($rawBody, $headers, $post);
        } catch (InvalidSignature) {
            $this->repo->logEvent($gatewayKey, $rawBody . '#' . bin2hex(random_bytes(4)), false, 'bad_signature', null);   // every forged attempt is kept, none deduplicated away

            return ['status' => 401, 'result' => 'bad_signature'];
        } catch (GatewayException) {
            return ['status' => 500, 'result' => 'error'];
        }
        if (!$this->repo->logEvent($gatewayKey, $rawBody, true, 'received', $event?->reference)) {
            return ['status' => 200, 'result' => 'duplicate'];
        }
        if ($event === null) {
            $this->repo->setEventResult($gatewayKey, $rawBody, 'ignored', null);

            return ['status' => 200, 'result' => 'ignored'];
        }

        $result = $this->handleEvent($gatewayKey, $event);
        $this->repo->setEventResult($gatewayKey, $rawBody, $result, $event->reference);

        return ['status' => 200, 'result' => $result];
    }

    /**
     * The customer's browser returned. Verifies whatever the gateway signed, acts on it, and reports the link's state.
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @return array{status:string,verified:bool}
     */
    public function customerReturned(string $publicId, array $query, array $post): array
    {
        $row = $this->repo->findByPublicId($publicId);
        if ($row === null) {
            return ['status' => 'unknown', 'verified' => false];
        }
        $gateway = $this->gateways->usable((string) $row['gateway']);
        $verified = false;
        if ($gateway !== null) {
            try {
                $event = $gateway->handleReturn($query, $post);
                if ($event !== null) {
                    $verified = true;
                    $this->handleEvent((string) $row['gateway'], $event);
                }
            } catch (InvalidSignature) {
                $this->audit->log('online_payment_bad_return', 'payments', 'invoice', (int) $row['invoice_id'], null, ['gateway' => $row['gateway'], 'link' => (int) $row['id']], null, null);
            }
        }
        $fresh = (array) $this->repo->findByPublicId($publicId);

        return ['status' => (string) $fresh['status'], 'verified' => $verified];
    }

    /**
     * Apply a verified event. Idempotent: the same event twice changes nothing the second time.
     *
     * @return string paid | paid_again | failed | pending | mismatch | unknown_payment | ignored
     */
    public function handleEvent(string $gatewayKey, PaymentEvent $event): string
    {
        $row = $event->reference !== null ? $this->repo->findByReference($event->reference) : null;
        $row ??= $event->providerOrderId !== null && $event->providerOrderId !== '' ? $this->repo->findByProviderOrder($gatewayKey, $event->providerOrderId) : null;
        if ($row === null || $row['gateway'] !== $gatewayKey) {
            return 'unknown_payment';
        }

        return $this->db->transaction(function () use ($row, $gatewayKey, $event): string {
            $locked = $this->repo->lock((int) $row['id']) ?? $row;

            if ($event->kind === PaymentEvent::PENDING) {
                return 'pending';
            }
            if ($event->kind === PaymentEvent::FAILED) {
                if (in_array($locked['status'], ['created', 'pending'], true)) {
                    $this->repo->update((int) $locked['id'], ['status' => 'failed', 'failure_reason' => mb_substr((string) ($event->reason ?? 'The payment did not go through.'), 0, 255)]);
                }

                return 'failed';
            }

            $providerPaymentId = (string) ($event->providerPaymentId ?? '');
            if ($providerPaymentId === '') {
                return 'ignored';
            }
            if ($this->repo->providerPaymentSeen($gatewayKey, $providerPaymentId)) {
                return 'ignored';   // this exact payment was recorded before
            }
            $expectedMinor = Money::toMinor((string) $locked['amount']);
            if (($event->amountMinor !== null && $event->amountMinor !== $expectedMinor) || ($event->currency !== null && strtoupper($event->currency) !== strtoupper((string) $locked['currency']))) {
                $this->repo->update((int) $locked['id'], ['status' => 'mismatch', 'failure_reason' => 'The gateway reported ' . ($event->currency ?? '?') . ' ' . Money::fromMinor((int) $event->amountMinor) . ' but ' . $locked['currency'] . ' ' . $locked['amount'] . ' was requested.']);
                $this->tell($locked, 'Online payment needs attention', 'A ' . $this->label($gatewayKey) . ' payment for a different amount than requested was received (' . $providerPaymentId . '). It was not recorded — check the gateway dashboard.', true);

                return 'mismatch';
            }

            $actor = $this->users->findById((int) $locked['created_by']);
            $invoice = $this->invoices->findById((int) $locked['invoice_id'], BranchScope::orgWide());
            try {
                if ($actor === null || !$actor->isActive || $invoice === null) {
                    throw new AuthorizationException('The person who created this link is no longer active, so it cannot be recorded under their name.');
                }
                $recorded = $this->payments->recordForInvoice($invoice, [
                    'amount' => (string) $locked['amount'], 'method' => in_array($event->method, ['upi', 'card', 'bank_transfer', 'other'], true) ? $event->method : 'other',
                    'reference' => mb_substr($this->label($gatewayKey) . ' ' . $providerPaymentId, 0, 120), 'paid_at' => gmdate('Y-m-d H:i:s'),
                    'notes' => 'Paid online via ' . $this->label($gatewayKey) . ' (link ' . $locked['reference'] . ')', 'idempotency_key' => hash('sha256', 'gw:' . $gatewayKey . ':' . $providerPaymentId),
                ], $actor);
            } catch (AuthorizationException | DomainRuleException | ValidationException $e) {
                // A link that was already paid is never downgraded; a second payment the books cannot take (the invoice is settled) is flagged.
                $second = $locked['status'] === 'paid';
                if (!$second) {
                    $this->repo->update((int) $locked['id'], ['status' => 'mismatch', 'failure_reason' => mb_substr('Received but not recorded: ' . $e->getMessage(), 0, 255)]);
                }
                $this->tell($locked, $second ? 'Second online payment needs attention' : 'Online payment needs attention',
                    'Money arrived through ' . $this->label($gatewayKey) . ' (' . $providerPaymentId . ') but could not be recorded automatically: ' . $e->getMessage() . ($second ? ' Refund it in the gateway dashboard or record it by hand.' : ''), true);

                return $second ? 'second_payment' : 'mismatch';
            }

            $again = $locked['status'] === 'paid';
            if (!$again) {
                $this->repo->update((int) $locked['id'], ['status' => 'paid', 'provider_payment_id' => $providerPaymentId, 'payment_id' => $recorded['payment']->id, 'paid_at' => gmdate('Y-m-d H:i:s'), 'failure_reason' => null]);
            }
            $this->audit->log('online_payment_recorded', 'payments', 'invoice', (int) $locked['invoice_id'], null, ['gateway' => $gatewayKey, 'payment_id' => $recorded['payment']->id, 'amount' => (string) $locked['amount'], 'repeat' => $again], null, $actor);
            $this->tell($locked, $again ? 'Second online payment received' : 'Online payment received',
                $locked['currency'] . ' ' . $locked['amount'] . ' paid through ' . $this->label($gatewayKey) . ' for ' . ($invoice?->invoiceNumber ?? 'an invoice') . '.' . ($again ? ' This link had already been paid — consider a refund.' : ''));

            return $again ? 'paid_again' : 'paid';
        });
    }

    // ---- helpers -----------------------------------------------------------------------------------------------------------------

    /**
     * Tell the person who made the link — and, when something needs attention (or they have left), every active super admin, so a
     * payment that could not be recorded is never left for nobody to see.
     *
     * @param array<string,mixed> $row
     */
    private function tell(array $row, string $title, string $body, bool $alert = false): void
    {
        $ids = [];
        $creator = $this->users->findById((int) $row['created_by']);
        if ($creator !== null && $creator->isActive) {
            $ids[] = $creator->id;
        }
        if ($alert || $ids === []) {
            foreach ($this->db->select("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'super_admin' AND u.is_active = 1 AND u.deleted_at IS NULL") as $a) {
                $ids[] = (int) $a['id'];
            }
        }
        foreach (array_unique($ids) as $id) {
            $this->notifications->notify($id, 'online_payment', $title, $body, 'invoice', (int) $row['invoice_id']);
        }
    }

    private function label(string $gateway): string
    {
        return (string) ($this->credentials->service($gateway)['label'] ?? ucfirst($gateway));
    }

    private function firstName(string $name): string
    {
        return trim(explode(' ', trim($name))[0] ?? '');
    }

    private function business(): string
    {
        return (string) setting('business.name', $this->app->config()->get('app.name'));
    }
}
