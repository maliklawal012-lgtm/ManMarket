<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class WithdrawalRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM withdrawals WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function findByVendorId(int $vendorId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM withdrawals WHERE vendor_id = :vendor_id ORDER BY created_at DESC');
        $stmt->execute(['vendor_id' => $vendorId]);

        return $stmt->fetchAll();
    }

    /**
     * Somme des retraits deja COMPLETED (montant reellement sorti du wallet —
     * utile pour reporting/audit, sans rapport avec l'eligibilite d'une nouvelle demande).
     */
    public function reservedAmountForVendor(int $vendorId): int
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM withdrawals
            WHERE vendor_id = :vendor_id AND status IN ('PENDING','APPROVED','PROCESSING','COMPLETED')
        ");
        $stmt->execute(['vendor_id' => $vendorId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Somme des retraits ENCORE EN COURS (ni termines, ni annules/rejetes/echoues).
     * Utilisee pour l'eligibilite d'une NOUVELLE demande : available_balance ne
     * baisse qu'a COMPLETED (voir WithdrawalService::complete), donc pour eviter
     * qu'un vendeur sur-engage son solde avec plusieurs demandes simultanees,
     * on retranche ce qui est deja "en vol" (PENDING/APPROVED/PROCESSING) du
     * solde disponible avant d'accepter une nouvelle demande.
     */
    public function openReservedAmountForVendor(int $vendorId): int
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM withdrawals
            WHERE vendor_id = :vendor_id AND status IN ('PENDING','APPROVED','PROCESSING')
        ");
        $stmt->execute(['vendor_id' => $vendorId]);

        return (int) $stmt->fetchColumn();
    }

    public function findByReference(string $reference): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM withdrawals WHERE reference = :reference');
        $stmt->execute(['reference' => $reference]);

        return $stmt->fetch() ?: null;
    }

    /** Verrouille la ligne pour la duree de la transaction en cours (SELECT ... FOR UPDATE). */
    public function findByIdForUpdate(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM withdrawals WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO withdrawals (vendor_id, wallet_id, amount, fee, net_amount, payment_method, account_number, reference)
            VALUES (:vendor_id, :wallet_id, :amount, :fee, :net_amount, :method, :account, :reference)
        ');
        $stmt->execute([
            'vendor_id' => $data['vendor_id'],
            'wallet_id' => $data['wallet_id'],
            'amount' => $data['amount'],
            'fee' => $data['fee'] ?? 0,
            'net_amount' => $data['net_amount'],
            'method' => $data['payment_method'],
            'account' => $data['account_number'],
            'reference' => $data['reference'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateStatus(int $id, string $status, ?int $processedBy = null, ?string $adminNote = null): void
    {
        $stmt = $this->db->prepare('
            UPDATE withdrawals
            SET status = :status, processed_by = :processed_by, admin_note = :note, processed_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ');
        $stmt->execute(['status' => $status, 'processed_by' => $processedBy, 'note' => $adminNote, 'id' => $id]);
    }

    public function findAll(?string $statusFilter = null): array
    {
        $sql = '
            SELECT w.*, v.business_name, u.name AS user_name, u.email AS user_email
            FROM withdrawals w
            JOIN vendors v ON v.id = w.vendor_id
            JOIN users u ON u.id = v.user_id
        ';
        $params = [];
        if ($statusFilter) {
            $sql .= ' WHERE w.status = :status';
            $params['status'] = $statusFilter;
        }
        $sql .= ' ORDER BY w.created_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
