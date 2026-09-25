<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Exceptions\QueryException;
use App\Support\Db;
use App\Support\Sql;

/** SQL for online payments and the gateway event log. Rows are reached through the invoice (branch-scoped) or by their own unguessable ids. */
final class GatewayPaymentRepository
{
    private const COLUMNS = 'g.id, g.public_id, g.reference, g.invoice_id, g.gateway, g.amount, g.currency, g.status, g.provider_order_id, g.provider_payment_id,
        g.checkout_method, g.checkout_url, g.checkout_fields, g.payment_id, g.failure_reason, g.expires_at, g.paid_at, g.created_by, g.created_at';

    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('gateway_payments', $data);
    }

    /** @return array<string,mixed>|null */
    public function findByPublicId(string $publicId): ?array
    {
        return $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM gateway_payments g WHERE g.public_id = :p', ['p' => $publicId]);
    }

    /** @return array<string,mixed>|null */
    public function findByReference(string $reference): ?array
    {
        return $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM gateway_payments g WHERE g.reference = :r', ['r' => $reference]);
    }

    /** @return array<string,mixed>|null */
    public function findByProviderOrder(string $gateway, string $providerOrderId): ?array
    {
        return $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM gateway_payments g WHERE g.gateway = :g AND g.provider_order_id = :o ORDER BY g.id DESC LIMIT 1', ['g' => $gateway, 'o' => $providerOrderId]);
    }

    /** The row locked for the rest of the transaction, so two simultaneous deliveries cannot both act on it. @return array<string,mixed>|null */
    public function lock(int $id): ?array
    {
        return $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM gateway_payments g WHERE g.id = :id FOR UPDATE', ['id' => $id]);
    }

    /** @return list<array<string,mixed>> newest first */
    public function forInvoice(int $invoiceId): array
    {
        return $this->db->select('SELECT ' . self::COLUMNS . ' FROM gateway_payments g WHERE g.invoice_id = :i ORDER BY g.id DESC LIMIT 50', ['i' => $invoiceId]);
    }

    /** @param array<string,mixed> $changes */
    public function update(int $id, array $changes): void
    {
        $sets = [];
        $bind = ['id' => $id];
        foreach ($changes as $column => $value) {
            $sets[] = Sql::assign((string) $column, 'c_');
            $bind['c_' . $column] = $value;
        }
        $this->db->affectingStatement('UPDATE gateway_payments SET ' . implode(', ', $sets) . ' WHERE id = :id', $bind);
    }

    /** Whether a payment id from this gateway has already been recorded (a second webhook for it must do nothing). */
    public function providerPaymentSeen(string $gateway, string $providerPaymentId): bool
    {
        return $this->db->exists("SELECT 1 FROM gateway_payments WHERE gateway = :g AND provider_payment_id = :p AND status = 'paid'", ['g' => $gateway, 'p' => $providerPaymentId]);
    }

    /**
     * Log an inbound delivery. Returns false when this exact delivery was already logged AND processed (a plain retry), so it is not
     * handled twice. A delivery logged but never finished (the process died half-way) is handled again — the handler is idempotent.
     *
     * @param array<string,mixed>|null $payload
     */
    public function logEvent(string $gateway, string $rawBody, bool $signatureOk, string $result, ?string $reference): bool
    {
        try {
            $this->db->insertRow('gateway_events', [
                'gateway' => $gateway, 'body_hash' => hash('sha256', $gateway . '|' . $rawBody), 'signature_ok' => $signatureOk ? 1 : 0,
                'result' => $result, 'reference' => $reference, 'payload' => mb_substr($rawBody, 0, 20000),
            ]);

            return true;
        } catch (QueryException $e) {
            if ($e->isDuplicateKey()) {
                return $this->db->selectValue('SELECT result FROM gateway_events WHERE body_hash = :h', ['h' => hash('sha256', $gateway . '|' . $rawBody)]) === 'received';
            }
            throw $e;
        }
    }

    /** Set the verdict on a logged delivery once it has been processed. */
    public function setEventResult(string $gateway, string $rawBody, string $result, ?string $reference): void
    {
        $this->db->affectingStatement('UPDATE gateway_events SET result = :r, reference = :ref WHERE body_hash = :h', ['r' => $result, 'ref' => $reference, 'h' => hash('sha256', $gateway . '|' . $rawBody)]);
    }
}
