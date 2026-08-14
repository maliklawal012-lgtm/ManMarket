<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\VendorRepository;
use App\Support\Logger;
use PDO;

/**
 * Actions administratives sur un vendeur (hors retraits, geres par WithdrawalService).
 * Toute action passe par audit_logs : c'est la trace exigee pour un ajustement manuel
 * ou un blocage. Un ajustement de wallet est TOUJOURS lie a la ligne audit_logs qui l'a
 * declenche (reference_type='audit_log') pour garder un lien explicite justification <-> ecriture.
 */
final class VendorAdminService
{
    public function __construct(
        private PDO $db,
        private VendorRepository $vendors,
        private AuditLogRepository $auditLogs,
        private WalletService $walletService
    ) {
    }

    public function suspend(int $vendorId, int $adminUserId, string $reason, ?string $ipAddress = null): void
    {
        $this->db->beginTransaction();
        try {
            $this->vendors->setStatus($vendorId, 'suspended', $reason);
            $this->auditLogs->record($adminUserId, 'vendor_suspended', 'vendor', $vendorId, $reason, $ipAddress);
            $this->db->commit();
            Logger::info('vendor_admin', 'Vendeur suspendu', ['vendor_id' => $vendorId, 'admin_id' => $adminUserId]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function reactivate(int $vendorId, int $adminUserId, ?string $ipAddress = null): void
    {
        $this->db->beginTransaction();
        try {
            $this->vendors->setStatus($vendorId, 'active', null);
            $this->auditLogs->record($adminUserId, 'vendor_reactivated', 'vendor', $vendorId, null, $ipAddress);
            $this->db->commit();
            Logger::info('vendor_admin', 'Vendeur reactive', ['vendor_id' => $vendorId, 'admin_id' => $adminUserId]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Ajustement manuel du wallet (correction d'erreur, geste commercial, etc).
     * $amount signe : positif = credit, negatif = debit. Toujours applique sur
     * available_balance (un ajustement admin est par definition immediatement
     * disponible, ce n'est pas une vente en attente de livraison).
     */
    public function adjustWallet(int $vendorId, int $adminUserId, int $amount, string $reason, ?string $ipAddress = null): void
    {
        if ($amount === 0) {
            throw new \InvalidArgumentException('Le montant de l\'ajustement ne peut pas etre zero.');
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Une justification est obligatoire pour tout ajustement manuel.');
        }

        $this->db->beginTransaction();
        try {
            $auditLogId = $this->auditLogs->record($adminUserId, 'wallet_adjustment', 'vendor', $vendorId, $reason, $ipAddress);

            if ($amount > 0) {
                $this->walletService->credit($vendorId, 'ADJUSTMENT', 'audit_log', $auditLogId, $amount, $reason, 'available_balance');
            } else {
                $this->walletService->debit($vendorId, 'ADJUSTMENT', 'audit_log', $auditLogId, abs($amount), $reason, 'available_balance');
            }

            $this->db->commit();
            Logger::info('vendor_admin', 'Ajustement wallet manuel', ['vendor_id' => $vendorId, 'admin_id' => $adminUserId, 'amount' => $amount]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
