<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class CommissionRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findByOrderItemId(int $orderItemId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM commissions WHERE order_item_id = :id');
        $stmt->execute(['id' => $orderItemId]);

        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT c.*, v.business_name, oi.product_name, oi.shop_id, s.name AS shop_name
            FROM commissions c
            JOIN vendors v ON v.id = c.vendor_id
            JOIN order_items oi ON oi.id = c.order_item_id
            JOIN shops s ON s.id = oi.shop_id
            WHERE c.id = :id
        ');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function findAll(?string $statusFilter, int $limit, int $offset): array
    {
        $sql = '
            SELECT c.*, v.business_name, oi.product_name
            FROM commissions c
            JOIN vendors v ON v.id = c.vendor_id
            JOIN order_items oi ON oi.id = c.order_item_id
        ';
        $params = [];
        if ($statusFilter) {
            $sql .= ' WHERE c.status = :status';
            $params['status'] = $statusFilter;
        }
        $sql .= ' ORDER BY c.created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function countAll(?string $statusFilter): int
    {
        $sql = 'SELECT COUNT(*) FROM commissions';
        $params = [];
        if ($statusFilter) {
            $sql .= ' WHERE status = :status';
            $params['status'] = $statusFilter;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function findByVendorId(int $vendorId, int $limit = 20): array
    {
        $stmt = $this->db->prepare('
            SELECT c.*, oi.product_name
            FROM commissions c
            JOIN order_items oi ON oi.id = c.order_item_id
            WHERE c.vendor_id = :vendor_id
            ORDER BY c.created_at DESC
            LIMIT ' . $limit
        );
        $stmt->execute(['vendor_id' => $vendorId]);

        return $stmt->fetchAll();
    }

    public function findByVendorIdFiltered(int $vendorId, ?string $statusFilter, int $limit, int $offset): array
    {
        $sql = '
            SELECT c.*, oi.product_name
            FROM commissions c
            JOIN order_items oi ON oi.id = c.order_item_id
            WHERE c.vendor_id = :vendor_id
        ';
        $params = ['vendor_id' => $vendorId];
        if ($statusFilter) {
            $sql .= ' AND c.status = :status';
            $params['status'] = $statusFilter;
        }
        $sql .= ' ORDER BY c.created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function countByVendorId(int $vendorId, ?string $statusFilter): int
    {
        $sql = 'SELECT COUNT(*) FROM commissions WHERE vendor_id = :vendor_id';
        $params = ['vendor_id' => $vendorId];
        if ($statusFilter) {
            $sql .= ' AND status = :status';
            $params['status'] = $statusFilter;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO commissions (order_id, order_item_id, vendor_id, gross_amount, commission_rate, commission_amount, net_amount, status, applied_at)
            VALUES (:order_id, :order_item_id, :vendor_id, :gross, :rate, :commission, :net, "applied", CURRENT_TIMESTAMP)
        ');
        $stmt->execute([
            'order_id' => $data['order_id'],
            'order_item_id' => $data['order_item_id'],
            'vendor_id' => $data['vendor_id'],
            'gross' => $data['gross_amount'],
            'rate' => $data['commission_rate'],
            'commission' => $data['commission_amount'],
            'net' => $data['net_amount'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function applyReversal(int $orderItemId, int $reversedAmount, bool $isFull): void
    {
        $stmt = $this->db->prepare('
            UPDATE commissions
            SET reversed_amount = reversed_amount + :amount,
                status = IF(:is_full = 1, "reversed", "partially_reversed"),
                reversed_at = CURRENT_TIMESTAMP
            WHERE order_item_id = :id
        ');
        $stmt->execute(['amount' => $reversedAmount, 'is_full' => $isFull ? 1 : 0, 'id' => $orderItemId]);
    }

    public function totalForPeriod(string $from, string $to): int
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(commission_amount - reversed_amount), 0)
            FROM commissions
            WHERE created_at BETWEEN :from AND :to AND status != 'reversed'
        ");
        $stmt->execute(['from' => $from, 'to' => $to]);

        return (int) $stmt->fetchColumn();
    }
}
