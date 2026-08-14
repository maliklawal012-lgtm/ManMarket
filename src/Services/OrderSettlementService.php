<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CommissionRepository;
use App\Repositories\OrderItemRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\SettlementFailureRepository;
use App\Support\Logger;
use PDO;

/**
 * Credite les wallets vendeurs UNIQUEMENT apres confirmation reelle d'un
 * paiement (jamais sur une simple redirection). Point d'entree unique :
 * settleOrder(). Appele par WebhookService ou par une verification serveur
 * explicite (jamais par une action navigateur directe).
 */
final class OrderSettlementService
{
    public function __construct(
        private PDO $db,
        private OrderRepository $orders,
        private OrderItemRepository $orderItems,
        private PaymentRepository $payments,
        private CommissionRepository $commissions,
        private SettlementFailureRepository $failures,
        private WalletService $wallets
    ) {
    }

    public function settleOrder(int $orderId): SettlementResult
    {
        $this->db->beginTransaction();

        try {
            $order = $this->orders->findByIdForUpdate($orderId);
            if (!$order) {
                $this->db->rollBack();

                return SettlementResult::failure("Commande #{$orderId} introuvable.");
            }

            $items = $this->orderItems->findByOrderIdForUpdate($orderId);
            if (!$items) {
                $this->db->rollBack();

                return SettlementResult::failure("Commande #{$orderId} sans article.");
            }

            // Idempotence : deja reglee -> no-op.
            $alreadyCredited = array_reduce($items, fn ($carry, $item) => $carry && (bool) $item['wallet_credited'], true);
            if ($order['payment_status'] === 'paid' && $alreadyCredited) {
                $this->db->commit();

                return SettlementResult::alreadySettled();
            }

            $payment = $this->payments->findByOrderId($orderId);
            if (!$payment || $payment['status'] !== 'completed') {
                $this->db->rollBack();

                return SettlementResult::failure("Paiement non confirme pour la commande #{$orderId}.");
            }

            $reconciliation = $this->reconcile($order, $items, $payment);
            if (!$reconciliation->ok) {
                $this->failures->record(
                    $orderId,
                    (int) $payment['id'],
                    $reconciliation->expectedTotal,
                    $reconciliation->computedTotal,
                    $reconciliation->message
                );
                $this->db->rollBack();
                Logger::error('settlement', 'Rapprochement echoue, aucun credit effectue', [
                    'order_id' => $orderId,
                    'expected' => $reconciliation->expectedTotal,
                    'computed' => $reconciliation->computedTotal,
                ]);

                return SettlementResult::failure('Rapprochement echoue : ' . $reconciliation->message);
            }

            $vendorShares = $this->calculateVendorShares($items);
            $this->createSettlementTransactions($orderId, $vendorShares);

            $this->orders->setPaymentStatus($orderId, 'paid');
            $this->db->commit();

            Logger::info('settlement', 'Commande reglee avec succes', [
                'order_id' => $orderId,
                'vendors' => array_keys($vendorShares),
                'total' => $reconciliation->expectedTotal,
            ]);

            return SettlementResult::success($vendorShares);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            Logger::error('settlement', 'Exception pendant le reglement', ['order_id' => $orderId, 'error' => $e->getMessage()]);

            return SettlementResult::failure('Erreur interne : ' . $e->getMessage());
        }
    }

    /** Regroupe les order_items par vendor_id (pour affichage/rapport ; le ledger reste ecrit par item). */
    public function calculateVendorShares(array $items): array
    {
        $shares = [];
        foreach ($items as $item) {
            $vendorId = (int) $item['vendor_id'];
            $shares[$vendorId] ??= ['vendor_id' => $vendorId, 'items' => [], 'gross_total' => 0, 'commission_total' => 0, 'net_total' => 0];
            $shares[$vendorId]['items'][] = $item;
            $shares[$vendorId]['gross_total'] += (int) round((float) $item['subtotal']);
            $shares[$vendorId]['commission_total'] += (int) round((float) $item['commission_amount']);
            $shares[$vendorId]['net_total'] += (int) round((float) $item['vendor_net_amount']);
        }

        return $shares;
    }

    /** Cree les ecritures de ledger : une SALE + une COMMISSION par order_item (jamais agregees). */
    public function createSettlementTransactions(int $orderId, array $vendorShares): void
    {
        foreach ($vendorShares as $share) {
            foreach ($share['items'] as $item) {
                $orderItemId = (int) $item['id'];
                $vendorId = (int) $item['vendor_id'];
                $subtotal = (int) round((float) $item['subtotal']);
                $commissionAmount = (int) round((float) $item['commission_amount']);

                $this->wallets->creditPending(
                    $vendorId,
                    'SALE',
                    'order_item',
                    $orderItemId,
                    $subtotal,
                    "Vente - commande #{$orderId}, article #{$orderItemId}",
                    ['total_earned' => $subtotal]
                );

                if ($commissionAmount > 0) {
                    $this->wallets->debit(
                        $vendorId,
                        'COMMISSION',
                        'order_item',
                        $orderItemId,
                        $commissionAmount,
                        "Commission ManMarket - commande #{$orderId}, article #{$orderItemId}",
                        'pending_balance',
                        ['total_commission_paid' => $commissionAmount]
                    );
                }

                $this->orderItems->markWalletCredited($orderItemId);

                $this->commissions->create([
                    'order_id' => $orderId,
                    'order_item_id' => $orderItemId,
                    'vendor_id' => $vendorId,
                    'gross_amount' => $subtotal,
                    'commission_rate' => (float) $item['commission_rate'],
                    'commission_amount' => $commissionAmount,
                    'net_amount' => (int) round((float) $item['vendor_net_amount']),
                ]);
            }
        }
    }

    /**
     * Verifie : montant confirme par Genius Pay == somme(vendor_net_amount) + somme(commission_amount).
     * Aucune perte, aucune creation d'argent ne doit etre possible.
     */
    private function reconcile(array $order, array $items, array $payment): ReconciliationResult
    {
        $expected = (int) round((float) $payment['amount']);

        $computed = 0;
        foreach ($items as $item) {
            $computed += (int) round((float) $item['vendor_net_amount']) + (int) round((float) $item['commission_amount']);
        }

        if ($expected !== $computed) {
            return ReconciliationResult::failure(
                $expected,
                $computed,
                "Total paye ({$expected}) != somme des parts vendeurs + commission ({$computed})"
            );
        }

        return ReconciliationResult::success($expected, $computed);
    }
}
