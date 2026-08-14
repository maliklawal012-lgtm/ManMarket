<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderItemRepository;
use App\Support\Logger;
use PDO;

/**
 * Bascule pending_balance -> available_balance pour les lignes de commande
 * livrees dont le delai de retenue (settings.wallet_hold_days) est ecoule.
 *
 * Le montant libere est TOUJOURS vendor_net_amount (subtotal - commission),
 * car c'est exactement ce que OrderSettlementService::createSettlementTransactions()
 * a fait entrer dans pending_balance pour cette ligne (SALE +subtotal, puis
 * COMMISSION -commission_amount sur le meme pending_balance).
 *
 * Idempotence : order_items.wallet_released_at (pas une ecriture de ledger —
 * un changement de disponibilite n'est pas un gain/perte, voir WalletService::
 * releaseToAvailable) verrouille chaque ligne individuellement pour eviter
 * une double liberation, meme en cas d'execution concurrente du job.
 */
final class WalletReleaseService
{
    public function __construct(
        private PDO $db,
        private OrderItemRepository $orderItems,
        private WalletService $walletService
    ) {
    }

    public function currentHoldDays(): int
    {
        $stmt = $this->db->prepare("SELECT value FROM settings WHERE `key` = 'wallet_hold_days'");
        $stmt->execute();
        $value = $stmt->fetchColumn();

        return $value !== false ? max(0, (int) $value) : 0;
    }

    /**
     * @return array{released:int, total_amount:int, errors:int}
     */
    public function releaseMaturedHolds(): array
    {
        $holdDays = $this->currentHoldDays();
        $candidates = $this->orderItems->findMaturedForRelease($holdDays);

        $released = 0;
        $totalAmount = 0;
        $errors = 0;

        foreach ($candidates as $candidate) {
            $result = $this->releaseOne((int) $candidate['id']);
            if ($result === null) {
                continue;
            }
            if ($result >= 0) {
                $released++;
                $totalAmount += $result;
            } else {
                $errors++;
            }
        }

        Logger::info('wallet_release', 'Cycle de liberation termine', [
            'hold_days' => $holdDays, 'released' => $released, 'total_amount' => $totalAmount, 'errors' => $errors,
        ]);

        return ['released' => $released, 'total_amount' => $totalAmount, 'errors' => $errors];
    }

    /** @return int|null Montant libere (>=0), ou null si deja traite entre-temps, ou -1 en cas d'erreur. */
    private function releaseOne(int $orderItemId): ?int
    {
        $this->db->beginTransaction();
        try {
            $item = $this->orderItems->findByIdForUpdate($orderItemId);
            if (!$item || $item['wallet_released_at'] !== null || (int) $item['wallet_credited'] !== 1) {
                $this->db->rollBack();

                return null;
            }

            $amount = (int) round((float) $item['vendor_net_amount']);
            if ($amount > 0) {
                $this->walletService->releaseToAvailable((int) $item['vendor_id'], $amount);
            }
            $this->orderItems->markWalletReleased($orderItemId);

            $this->db->commit();

            return $amount;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            Logger::error('wallet_release', 'Erreur liberation', ['order_item_id' => $orderItemId, 'error' => $e->getMessage()]);

            return -1;
        }
    }
}
