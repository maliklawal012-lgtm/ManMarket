<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AuditLogRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function record(int $adminUserId, string $action, string $entityType, int $entityId, ?string $reason, ?string $ipAddress = null): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO audit_logs (admin_user_id, action, entity_type, entity_id, reason, ip_address)
            VALUES (:admin_user_id, :action, :entity_type, :entity_id, :reason, :ip)
        ');
        $stmt->execute([
            'admin_user_id' => $adminUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'reason' => $reason,
            'ip' => $ipAddress,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function findByEntity(string $entityType, int $entityId): array
    {
        $stmt = $this->db->prepare('
            SELECT al.*, u.name AS admin_name
            FROM audit_logs al
            JOIN users u ON u.id = al.admin_user_id
            WHERE al.entity_type = :entity_type AND al.entity_id = :entity_id
            ORDER BY al.created_at DESC
        ');
        $stmt->execute(['entity_type' => $entityType, 'entity_id' => $entityId]);

        return $stmt->fetchAll();
    }

    public function findRecent(int $limit = 50): array
    {
        $stmt = $this->db->prepare('
            SELECT al.*, u.name AS admin_name
            FROM audit_logs al
            JOIN users u ON u.id = al.admin_user_id
            ORDER BY al.created_at DESC
            LIMIT :limit
        ');
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
