<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SettlementFailureRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function record(int $orderId, ?int $paymentId, int $expectedTotal, int $computedTotal, string $message): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO settlement_failures (order_id, payment_id, expected_total, computed_total, difference, error_message)
            VALUES (:order_id, :payment_id, :expected, :computed, :difference, :message)
        ');
        $stmt->execute([
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'expected' => $expectedTotal,
            'computed' => $computedTotal,
            'difference' => $expectedTotal - $computedTotal,
            'message' => $message,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function findUnresolved(): array
    {
        $stmt = $this->db->query('SELECT * FROM settlement_failures WHERE resolved = 0 ORDER BY created_at DESC');

        return $stmt->fetchAll();
    }

    public function resolve(int $id, int $resolvedBy, string $note): void
    {
        $stmt = $this->db->prepare('
            UPDATE settlement_failures SET resolved = 1, resolved_by = :by, resolved_note = :note, resolved_at = CURRENT_TIMESTAMP WHERE id = :id
        ');
        $stmt->execute(['by' => $resolvedBy, 'note' => $note, 'id' => $id]);
    }
}
