<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\VendorRepository;
use App\Repositories\WalletRepository;
use App\Repositories\WithdrawalRepository;
use App\Support\Logger;
use PDO;

/**
 * Retraits vendeur. Genius Pay ne fournit pas d'API Payout publique a ce jour
 * (voir GeniusPayService::createPayout) : tout retrait est execute MANUELLEMENT
 * par un administrateur (virement Mobile Money hors plateforme), ce service ne
 * fait que suivre le cycle de vie de la demande et proteger le solde.
 *
 * Cycle de vie : PENDING -> APPROVED -> PROCESSING -> COMPLETED
 *                       \-> REJECTED      \-> CANCELLED
 *                                          `-> FAILED
 *
 * available_balance n'est debite QU'A la completion reelle (complete()) : entre
 * la demande et la completion, l'eligibilite d'une NOUVELLE demande est verifiee
 * via WithdrawalRepository::openReservedAmountForVendor() (retraits encore en
 * cours), ce qui empeche un vendeur de sur-engager son solde avec plusieurs
 * demandes simultanees sans jamais debiter deux fois le meme solde.
 */
final class WithdrawalService
{
    private const ALLOWED_METHODS = ['wave', 'orange_money', 'mtn_money', 'moov_money'];

    private const TRANSITIONS = [
        'PENDING' => ['APPROVED', 'REJECTED', 'CANCELLED'],
        'APPROVED' => ['PROCESSING', 'REJECTED', 'CANCELLED'],
        'PROCESSING' => ['COMPLETED', 'FAILED'],
    ];

    public function __construct(
        private PDO $db,
        private WithdrawalRepository $withdrawals,
        private WalletRepository $wallets,
        private WalletService $walletService,
        private ?VendorRepository $vendors = null,
        private ?NotificationService $notifications = null
    ) {
    }

    /**
     * Demande de retrait initiee par le vendeur. Ne modifie AUCUN solde : reserve
     * seulement le montant via le statut PENDING (voir openReservedAmountForVendor).
     */
    public function requestWithdrawal(int $vendorId, int $amount, string $paymentMethod, string $accountNumber): WithdrawalResult
    {
        if ($amount <= 0) {
            return WithdrawalResult::failure('Le montant doit etre positif.');
        }
        if (!in_array($paymentMethod, self::ALLOWED_METHODS, true)) {
            return WithdrawalResult::failure('Moyen de paiement invalide.');
        }
        if (trim($accountNumber) === '') {
            return WithdrawalResult::failure('Veuillez indiquer un numero de reception.');
        }
        if ($this->vendors !== null) {
            $vendor = $this->vendors->findById($vendorId);
            if ($vendor && $vendor['status'] !== 'active') {
                return WithdrawalResult::failure('Votre compte vendeur est actuellement suspendu, les retraits ne sont pas disponibles.');
            }
        }

        $this->db->beginTransaction();
        try {
            $wallet = $this->wallets->findOrCreateForUpdate($vendorId);
            $openReserved = $this->withdrawals->openReservedAmountForVendor($vendorId);
            $spendable = (int) round((float) $wallet['available_balance']) - $openReserved;

            if ($amount > $spendable) {
                $this->db->rollBack();

                return WithdrawalResult::failure(
                    "Solde disponible insuffisant (disponible : {$spendable} FCFA, deja reserve par d'autres demandes en cours : {$openReserved} FCFA)."
                );
            }

            $reference = $this->generateReference();
            $withdrawalId = $this->withdrawals->create([
                'vendor_id' => $vendorId,
                'wallet_id' => (int) $wallet['id'],
                'amount' => $amount,
                'fee' => 0,
                'net_amount' => $amount,
                'payment_method' => $paymentMethod,
                'account_number' => $accountNumber,
                'reference' => $reference,
            ]);

            $this->db->commit();

            Logger::info('withdrawal', 'Demande de retrait creee', ['withdrawal_id' => $withdrawalId, 'vendor_id' => $vendorId, 'amount' => $amount]);
            $this->notifications?->withdrawalRequested($withdrawalId);

            return WithdrawalResult::success($withdrawalId, $reference);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            Logger::error('withdrawal', 'Erreur creation demande', ['vendor_id' => $vendorId, 'error' => $e->getMessage()]);

            return WithdrawalResult::failure('Erreur interne : ' . $e->getMessage());
        }
    }

    public function approve(int $withdrawalId, int $adminUserId): WithdrawalResult
    {
        return $this->transition($withdrawalId, 'APPROVED', $adminUserId);
    }

    public function reject(int $withdrawalId, int $adminUserId, string $reason): WithdrawalResult
    {
        return $this->transition($withdrawalId, 'REJECTED', $adminUserId, $reason);
    }

    public function markProcessing(int $withdrawalId, int $adminUserId): WithdrawalResult
    {
        return $this->transition($withdrawalId, 'PROCESSING', $adminUserId);
    }

    public function cancel(int $withdrawalId, ?int $actorUserId, string $reason = ''): WithdrawalResult
    {
        return $this->transition($withdrawalId, 'CANCELLED', $actorUserId, $reason !== '' ? $reason : null);
    }

    public function fail(int $withdrawalId, int $adminUserId, string $reason): WithdrawalResult
    {
        return $this->transition($withdrawalId, 'FAILED', $adminUserId, $reason);
    }

    /**
     * Seul point ou available_balance est reellement debite : l'administrateur
     * confirme ici que l'argent a ete effectivement envoye (hors plateforme).
     */
    public function complete(int $withdrawalId, int $adminUserId, ?string $note = null): WithdrawalResult
    {
        $this->db->beginTransaction();
        try {
            $withdrawal = $this->withdrawals->findByIdForUpdate($withdrawalId);
            if (!$withdrawal) {
                $this->db->rollBack();

                return WithdrawalResult::failure('Retrait introuvable.');
            }
            if (!$this->canTransition($withdrawal['status'], 'COMPLETED')) {
                $this->db->rollBack();

                return WithdrawalResult::failure("Transition invalide : {$withdrawal['status']} -> COMPLETED.");
            }

            $amount = (int) round((float) $withdrawal['amount']);
            $this->walletService->debit(
                (int) $withdrawal['vendor_id'],
                'WITHDRAWAL',
                'withdrawal',
                $withdrawalId,
                $amount,
                "Retrait #{$withdrawalId} ({$withdrawal['reference']})",
                'available_balance',
                ['total_withdrawn' => $amount]
            );

            $this->withdrawals->updateStatus($withdrawalId, 'COMPLETED', $adminUserId, $note);
            $this->db->commit();

            Logger::info('withdrawal', 'Retrait complete', ['withdrawal_id' => $withdrawalId, 'amount' => $amount]);
            $this->notifications?->withdrawalStatusChanged($withdrawalId);

            return WithdrawalResult::success($withdrawalId, (string) $withdrawal['reference']);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            Logger::error('withdrawal', 'Erreur completion', ['withdrawal_id' => $withdrawalId, 'error' => $e->getMessage()]);

            return WithdrawalResult::failure('Erreur interne : ' . $e->getMessage());
        }
    }

    /**
     * Annule un retrait deja COMPLETED par erreur administrative : recredite
     * available_balance (WITHDRAWAL_REVERSAL). Le statut reste COMPLETED (l'ENUM
     * withdrawals.status n'a pas d'etat REVERSED dedie) mais admin_note et le
     * ledger documentent la reversal de facon tracable et irreversible.
     */
    public function reverse(int $withdrawalId, int $adminUserId, string $reason): WithdrawalResult
    {
        $this->db->beginTransaction();
        try {
            $withdrawal = $this->withdrawals->findByIdForUpdate($withdrawalId);
            if (!$withdrawal || $withdrawal['status'] !== 'COMPLETED') {
                $this->db->rollBack();

                return WithdrawalResult::failure('Seul un retrait COMPLETED peut etre annule/reverse.');
            }

            $amount = (int) round((float) $withdrawal['amount']);
            $this->walletService->credit(
                (int) $withdrawal['vendor_id'],
                'WITHDRAWAL_REVERSAL',
                'withdrawal',
                $withdrawalId,
                $amount,
                "Annulation retrait #{$withdrawalId} ({$withdrawal['reference']}) : {$reason}",
                'available_balance',
                ['total_withdrawn' => -$amount]
            );

            $this->withdrawals->updateStatus($withdrawalId, 'COMPLETED', $adminUserId, 'ANNULE/RECREDITE : ' . $reason);
            $this->db->commit();

            Logger::info('withdrawal', 'Retrait annule/reverse', ['withdrawal_id' => $withdrawalId, 'amount' => $amount]);

            return WithdrawalResult::success($withdrawalId, (string) $withdrawal['reference']);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            Logger::error('withdrawal', 'Erreur reversal', ['withdrawal_id' => $withdrawalId, 'error' => $e->getMessage()]);

            return WithdrawalResult::failure('Erreur interne : ' . $e->getMessage());
        }
    }

    private function transition(int $withdrawalId, string $newStatus, ?int $actorUserId, ?string $note = null): WithdrawalResult
    {
        $this->db->beginTransaction();
        try {
            $withdrawal = $this->withdrawals->findByIdForUpdate($withdrawalId);
            if (!$withdrawal) {
                $this->db->rollBack();

                return WithdrawalResult::failure('Retrait introuvable.');
            }
            if (!$this->canTransition($withdrawal['status'], $newStatus)) {
                $this->db->rollBack();

                return WithdrawalResult::failure("Transition invalide : {$withdrawal['status']} -> {$newStatus}.");
            }

            $this->withdrawals->updateStatus($withdrawalId, $newStatus, $actorUserId, $note);
            $this->db->commit();

            $this->notifications?->withdrawalStatusChanged($withdrawalId);

            return WithdrawalResult::success($withdrawalId, (string) $withdrawal['reference']);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            Logger::error('withdrawal', 'Erreur transition', ['withdrawal_id' => $withdrawalId, 'status' => $newStatus, 'error' => $e->getMessage()]);

            return WithdrawalResult::failure('Erreur interne : ' . $e->getMessage());
        }
    }

    private function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    private function generateReference(): string
    {
        do {
            $reference = 'WD-' . strtoupper(bin2hex(random_bytes(5)));
        } while ($this->withdrawals->findByReference($reference) !== null);

        return $reference;
    }
}
