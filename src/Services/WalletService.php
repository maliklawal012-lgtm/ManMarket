<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\WalletRepository;
use App\Repositories\WalletTransactionRepository;
use App\Support\Logger;

/**
 * SEULE classe autorisee a modifier wallets.*. Toute mutation passe par une
 * ecriture wallet_transactions correspondante (ledger). Aucune methode ici
 * ne modifie un solde sans creer la ligne de ledger dans la MEME transaction
 * SQL (appelant : voir OrderSettlementService, WithdrawalService).
 *
 * Chaque methode credit()/debit() est idempotente : si une ecriture existe
 * deja pour (walletId, type, referenceType, referenceId), l'appel est un
 * no-op silencieux (retourne null) au lieu de crediter/debiter une seconde fois.
 */
final class WalletService
{
    public function __construct(
        private WalletRepository $wallets,
        private WalletTransactionRepository $transactions
    ) {
    }

    /**
     * Credite le solde EN ATTENTE (pending_balance) — jamais directement
     * available_balance. Voir releaseToAvailable() pour le transfert.
     */
    public function creditPending(
        int $vendorId,
        string $type,
        string $referenceType,
        int $referenceId,
        int $amount,
        string $description,
        array $walletTotalsDelta = []
    ): ?int {
        return $this->credit($vendorId, $type, $referenceType, $referenceId, $amount, $description, 'pending_balance', $walletTotalsDelta);
    }

    /** Credite une colonne de solde donnee (pending_balance ou available_balance). */
    public function credit(
        int $vendorId,
        string $type,
        string $referenceType,
        int $referenceId,
        int $amount,
        string $description,
        string $toColumn = 'pending_balance',
        array $walletTotalsDelta = []
    ): ?int {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Le montant a crediter doit etre positif.');
        }
        if (!in_array($toColumn, ['pending_balance', 'available_balance'], true)) {
            throw new \InvalidArgumentException('Colonne de solde invalide.');
        }

        $wallet = $this->wallets->findOrCreateForUpdate($vendorId);

        if ($this->transactions->existsForSource((int) $wallet['id'], $type, $referenceType, $referenceId)) {
            Logger::info('wallet', 'Credit ignore (deja applique)', compact('vendorId', 'type', 'referenceType', 'referenceId'));

            return null;
        }

        $before = (int) round((float) $wallet[$toColumn]);
        $after = $before + $amount;

        $txId = $this->transactions->record(
            (int) $wallet['id'],
            $vendorId,
            $type,
            $referenceType,
            $referenceId,
            $amount,
            $before,
            $after,
            $description
        );

        if ($txId === null) {
            return null;
        }

        $deltas = array_merge([$toColumn => $amount], $walletTotalsDelta);
        $this->wallets->applyDelta((int) $wallet['id'], $deltas);

        return $txId;
    }

    /** Debite pending_balance ou available_balance (ex: COMMISSION, REFUND). */
    public function debit(
        int $vendorId,
        string $type,
        string $referenceType,
        int $referenceId,
        int $amount,
        string $description,
        string $fromColumn = 'pending_balance',
        array $walletTotalsDelta = []
    ): ?int {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Le montant a debiter doit etre positif.');
        }
        if (!in_array($fromColumn, ['pending_balance', 'available_balance'], true)) {
            throw new \InvalidArgumentException('Colonne de solde invalide.');
        }

        $wallet = $this->wallets->findOrCreateForUpdate($vendorId);

        if ($this->transactions->existsForSource((int) $wallet['id'], $type, $referenceType, $referenceId)) {
            Logger::info('wallet', 'Debit ignore (deja applique)', compact('vendorId', 'type', 'referenceType', 'referenceId'));

            return null;
        }

        $before = (int) round((float) $wallet[$fromColumn]);
        $after = $before - $amount;

        $txId = $this->transactions->record(
            (int) $wallet['id'],
            $vendorId,
            $type,
            $referenceType,
            $referenceId,
            -$amount,
            $before,
            $after,
            $description
        );

        if ($txId === null) {
            return null;
        }

        $deltas = array_merge([$fromColumn => -$amount], $walletTotalsDelta);
        $this->wallets->applyDelta((int) $wallet['id'], $deltas);

        return $txId;
    }

    /** Bascule un montant de pending_balance vers available_balance (aucun mouvement de ledger : pas un gain/perte, juste un changement de disponibilite). */
    public function releaseToAvailable(int $vendorId, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        $wallet = $this->wallets->findOrCreateForUpdate($vendorId);
        $this->wallets->applyDelta((int) $wallet['id'], [
            'pending_balance' => -$amount,
            'available_balance' => $amount,
        ]);
    }

    public function getOrCreateWallet(int $vendorId): array
    {
        $existing = $this->wallets->findByVendorId($vendorId);
        if ($existing) {
            return $existing;
        }

        return $this->wallets->findOrCreateForUpdate($vendorId);
    }
}
