<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class WalletRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findByVendorId(int $vendorId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM wallets WHERE vendor_id = :vendor_id');
        $stmt->execute(['vendor_id' => $vendorId]);

        return $stmt->fetch() ?: null;
    }

    /** Cree le wallet s'il n'existe pas encore, puis le verrouille pour la transaction en cours. */
    public function findOrCreateForUpdate(int $vendorId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM wallets WHERE vendor_id = :vendor_id FOR UPDATE');
        $stmt->execute(['vendor_id' => $vendorId]);
        $wallet = $stmt->fetch();

        if ($wallet) {
            return $wallet;
        }

        $stmt = $this->db->prepare('INSERT INTO wallets (vendor_id) VALUES (:vendor_id)');
        $stmt->execute(['vendor_id' => $vendorId]);

        $stmt = $this->db->prepare('SELECT * FROM wallets WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => (int) $this->db->lastInsertId()]);

        return $stmt->fetch();
    }

    public function applyDelta(int $walletId, array $deltas): void
    {
        $sets = [];
        $params = ['id' => $walletId];
        foreach ($deltas as $column => $amount) {
            $sets[] = "{$column} = {$column} + :{$column}";
            $params[$column] = $amount;
        }
        if (!$sets) {
            return;
        }

        $sql = 'UPDATE wallets SET ' . implode(', ', $sets) . ', updated_at = CURRENT_TIMESTAMP WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }
}
