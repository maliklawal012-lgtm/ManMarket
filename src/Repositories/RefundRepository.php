<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class RefundRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function create(int $orderId, int $paymentId, string $reason, ?int $requestedBy): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO refunds (order_id, payment_id, total_amount, reason, requested_by)
            VALUES (:order_id, :payment_id, 0, :reason, :requested_by)
        ');
        $stmt->execute([
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'reason' => $reason,
            'requested_by' => $requestedBy,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function addItem(int $refundId, int $orderItemId, int $vendorId, int $quantity, int $grossAmount, int $commissionAmount, int $netAmount): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO refund_items (refund_id, order_item_id, vendor_id, quantity, refunded_gross_amount, refunded_commission_amount, refunded_net_amount)
            VALUES (:refund_id, :order_item_id, :vendor_id, :quantity, :gross, :commission, :net)
        ');
        $stmt->execute([
            'refund_id' => $refundId,
            'order_item_id' => $orderItemId,
            'vendor_id' => $vendorId,
            'quantity' => $quantity,
            'gross' => $grossAmount,
            'commission' => $commissionAmount,
            'net' => $netAmount,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function finalizeTotal(int $refundId): void
    {
        $stmt = $this->db->prepare('
            UPDATE refunds SET total_amount = (SELECT COALESCE(SUM(refunded_gross_amount), 0) FROM refund_items WHERE refund_id = :id1)
            WHERE id = :id2
        ');
        $stmt->execute(['id1' => $refundId, 'id2' => $refundId]);
    }

    public function setStatus(int $refundId, string $status, ?int $processedBy = null): void
    {
        $stmt = $this->db->prepare('UPDATE refunds SET status = :status, processed_by = :processed_by, processed_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute(['status' => $status, 'processed_by' => $processedBy, 'id' => $refundId]);
    }

    public function itemsForRefund(int $refundId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM refund_items WHERE refund_id = :id');
        $stmt->execute(['id' => $refundId]);

        return $stmt->fetchAll();
    }

    public function totalRefundedForOrderItem(int $orderItemId): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(refunded_gross_amount), 0) FROM refund_items WHERE order_item_id = :id');
        $stmt->execute(['id' => $orderItemId]);

        return (int) $stmt->fetchColumn();
    }
}
