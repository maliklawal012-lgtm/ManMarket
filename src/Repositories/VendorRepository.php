<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class VendorRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM vendors WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function findByUserId(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM vendors WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetch() ?: null;
    }

    public function findByShopId(int $shopId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT v.* FROM vendors v
            JOIN shops s ON s.vendor_id = v.id
            WHERE s.id = :shop_id
        ');
        $stmt->execute(['shop_id' => $shopId]);

        return $stmt->fetch() ?: null;
    }

    public function create(int $userId, string $businessName): int
    {
        $stmt = $this->db->prepare('INSERT INTO vendors (user_id, business_name) VALUES (:user_id, :business_name)');
        $stmt->execute(['user_id' => $userId, 'business_name' => $businessName]);

        return (int) $this->db->lastInsertId();
    }

    public function setStatus(int $vendorId, string $status, ?string $reason = null): void
    {
        $stmt = $this->db->prepare('UPDATE vendors SET status = :status, suspended_reason = :reason, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute(['status' => $status, 'reason' => $reason, 'id' => $vendorId]);
    }
}
