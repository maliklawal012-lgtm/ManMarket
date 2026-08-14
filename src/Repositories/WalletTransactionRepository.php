<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class WalletTransactionRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Insere une ecriture de ledger. Retourne l'id, ou null si cette source
     * (wallet_id, type, reference_type, reference_id) a deja ete enregistree —
     * s'appuie sur la contrainte UNIQUE (idempotence comptable).
     */
    public function record(
        int $walletId,
        int $vendorId,
        string $type,
        string $referenceType,
        int $referenceId,
        int $amount,
        int $balanceBefore,
        int $balanceAfter,
        string $description,
        ?int $createdBy = null
    ): ?int {
        try {
            $stmt = $this->db->prepare('
                INSERT INTO wallet_transactions (wallet_id, vendor_id, type, reference_type, reference_id, amount, balance_before, balance_after, description, created_by)
                VALUES (:wallet_id, :vendor_id, :type, :reference_type, :reference_id, :amount, :before, :after, :description, :created_by)
            ');
            $stmt->execute([
                'wallet_id' => $walletId,
                'vendor_id' => $vendorId,
                'type' => $type,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'amount' => $amount,
                'before' => $balanceBefore,
                'after' => $balanceAfter,
                'description' => $description,
                'created_by' => $createdBy,
            ]);

            return (int) $this->db->lastInsertId();
        } catch (\PDOException $e) {
            if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                return null;
            }
            throw $e;
        }
    }

    public function existsForSource(int $walletId, string $type, string $referenceType, int $referenceId): bool
    {
        $stmt = $this->db->prepare('
            SELECT 1 FROM wallet_transactions
            WHERE wallet_id = :wallet_id AND type = :type AND reference_type = :reference_type AND reference_id = :reference_id
        ');
        $stmt->execute([
            'wallet_id' => $walletId,
            'type' => $type,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    public function findByVendorId(int $vendorId, int $limit = 50): array
    {
        $stmt = $this->db->prepare('SELECT * FROM wallet_transactions WHERE vendor_id = :vendor_id ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue('vendor_id', $vendorId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** Journal complet, filtrable par type, pagine (utilise par la page "Finances" du vendeur). */
    public function findByVendorIdFiltered(int $vendorId, ?string $type, int $limit, int $offset): array
    {
        $sql = 'SELECT * FROM wallet_transactions WHERE vendor_id = :vendor_id';
        $params = ['vendor_id' => $vendorId];
        if ($type !== null) {
            $sql .= ' AND type = :type';
            $params['type'] = $type;
        }
        $sql .= ' ORDER BY id DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countByVendorId(int $vendorId, ?string $type): int
    {
        $sql = 'SELECT COUNT(*) FROM wallet_transactions WHERE vendor_id = :vendor_id';
        $params = ['vendor_id' => $vendorId];
        if ($type !== null) {
            $sql .= ' AND type = :type';
            $params['type'] = $type;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** Sans limite : reserve a l'export CSV (donnees du vendeur lui-meme uniquement). */
    public function findAllByVendorId(int $vendorId, ?string $type): array
    {
        $sql = 'SELECT * FROM wallet_transactions WHERE vendor_id = :vendor_id';
        $params = ['vendor_id' => $vendorId];
        if ($type !== null) {
            $sql .= ' AND type = :type';
            $params['type'] = $type;
        }
        $sql .= ' ORDER BY id DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
