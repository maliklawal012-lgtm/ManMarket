<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class PaymentRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findByOrderId(int $orderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM payments WHERE order_id = :order_id ORDER BY id DESC LIMIT 1');
        $stmt->execute(['order_id' => $orderId]);

        return $stmt->fetch() ?: null;
    }

    public function findByReference(string $reference): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM payments WHERE provider_reference = :reference');
        $stmt->execute(['reference' => $reference]);

        return $stmt->fetch() ?: null;
    }

    /** Verrouille la ligne pour la duree de la transaction (utilise pendant le reglement). */
    public function findByReferenceForUpdate(string $reference): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM payments WHERE provider_reference = :reference FOR UPDATE');
        $stmt->execute(['reference' => $reference]);

        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO payments (order_id, provider, provider_reference, amount, currency, status, payment_method, environment, payment_url, raw_response)
            VALUES (:order_id, :provider, :reference, :amount, :currency, :status, :method, :environment, :url, :raw)
        ');
        $stmt->execute([
            'order_id' => $data['order_id'],
            'provider' => $data['provider'] ?? 'geniuspay',
            'reference' => $data['provider_reference'],
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'XOF',
            'status' => $data['status'] ?? 'pending',
            'method' => $data['payment_method'] ?? null,
            'environment' => $data['environment'],
            'url' => $data['payment_url'] ?? null,
            'raw' => isset($data['raw_response']) ? json_encode($data['raw_response'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateStatus(int $paymentId, string $status, ?string $method = null, bool $confirmed = false): void
    {
        $stmt = $this->db->prepare('
            UPDATE payments
            SET status = :status,
                payment_method = COALESCE(:method, payment_method),
                confirmed_at = IF(:confirmed = 1, CURRENT_TIMESTAMP, confirmed_at),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ');
        $stmt->execute([
            'status' => $status,
            'method' => $method,
            'confirmed' => $confirmed ? 1 : 0,
            'id' => $paymentId,
        ]);
    }
}
