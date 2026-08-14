<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CommissionRepository;
use App\Repositories\OrderItemRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\RefundRepository;
use App\Support\Logger;
use PDO;

/**
 * Remboursement partiel ou total d'UNE ligne de commande (order_item). Reverse
 * exactement ce que OrderSettlementService a credite pour cette ligne : le
 * wallet vendeur perd vendor_net_amount (proportionnel a la quantite remboursee),
 * la commission associee est annulee (CommissionRepository::applyReversal).
 *
 * Genius Pay n'a pas d'API de remboursement automatique documentee utilisee ici :
 * comme pour les retraits, le remboursement au client se fait manuellement hors
 * plateforme (virement Mobile Money) ; ce service gere uniquement la comptabilite
 * interne (ledger, wallet, commission), jamais un appel a un endpoint de paiement.
 *
 * Aucun remboursement n'est jamais fabrique : si la ligne n'a pas ete creditee
 * (wallet_credited = 0, ex. commande payee a la livraison jamais reglee en ligne),
 * aucun mouvement de wallet n'est effectue — seul l'etat de la commande change.
 */
final class RefundService
{
    public function __construct(
        private PDO $db,
        private OrderItemRepository $orderItems,
        private PaymentRepository $payments,
        private RefundRepository $refunds,
        private CommissionRepository $commissions,
        private WalletService $walletService
    ) {
    }

    public function refundOrderItem(int $orderItemId, int $quantity, string $reason, ?int $requestedBy = null): RefundResult
    {
        if ($quantity <= 0) {
            return RefundResult::failure('La quantite a rembourser doit etre positive.');
        }

        $this->db->beginTransaction();
        try {
            $item = $this->orderItems->findByIdForUpdate($orderItemId);
            if (!$item) {
                $this->db->rollBack();

                return RefundResult::failure('Article introuvable.');
            }

            $remaining = (int) $item['quantity'] - (int) $item['refunded_quantity'];
            if ($quantity > $remaining) {
                $this->db->rollBack();

                return RefundResult::failure("Quantite superieure a ce qui peut encore etre rembourse ({$remaining} restante(s)).");
            }

            $payment = $this->payments->findByOrderId((int) $item['order_id']);
            if (!$payment) {
                $this->db->rollBack();

                return RefundResult::failure('Aucun paiement en ligne associe a cette commande (paiement a la livraison : remboursement a gerer hors plateforme).');
            }

            $unitPrice = (int) round((float) $item['unit_price']);
            $grossAmount = $unitPrice * $quantity;
            $commissionRate = (float) $item['commission_rate'];
            $commissionAmount = (int) round($grossAmount * $commissionRate / 100);
            $netAmount = $grossAmount - $commissionAmount;
            $isFull = $quantity === $remaining;

            $refundId = $this->refunds->create((int) $item['order_id'], (int) $payment['id'], $reason, $requestedBy);
            $refundItemId = $this->refunds->addItem($refundId, $orderItemId, (int) $item['vendor_id'], $quantity, $grossAmount, $commissionAmount, $netAmount);
            $this->refunds->finalizeTotal($refundId);
            $this->refunds->setStatus($refundId, 'completed', $requestedBy);

            if ((int) $item['wallet_credited'] === 1 && $netAmount > 0) {
                $fromColumn = $item['wallet_released_at'] !== null ? 'available_balance' : 'pending_balance';
                $this->walletService->debit(
                    (int) $item['vendor_id'],
                    'REFUND',
                    'refund_item',
                    $refundItemId,
                    $netAmount,
                    "Remboursement #{$refundId} — article #{$orderItemId} ({$reason})",
                    $fromColumn,
                    ['total_refunded' => $netAmount]
                );

                if ($commissionAmount > 0) {
                    $this->commissions->applyReversal($orderItemId, $commissionAmount, $isFull);
                }
            }

            $this->orderItems->applyRefund($orderItemId, $quantity, $grossAmount, $isFull ? 'full' : 'partial');

            $this->db->commit();

            Logger::info('refund', 'Remboursement effectue', [
                'refund_id' => $refundId, 'order_item_id' => $orderItemId, 'quantity' => $quantity, 'net_amount' => $netAmount,
            ]);

            return RefundResult::success($refundId);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            Logger::error('refund', 'Erreur remboursement', ['order_item_id' => $orderItemId, 'error' => $e->getMessage()]);

            return RefundResult::failure('Erreur interne : ' . $e->getMessage());
        }
    }
}
