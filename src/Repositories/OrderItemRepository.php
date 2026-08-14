<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class OrderItemRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findByOrderId(int $orderId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM order_items WHERE order_id = :order_id ORDER BY id');
        $stmt->execute(['order_id' => $orderId]);

        return $stmt->fetchAll();
    }

    /** Verrouille toutes les lignes de la commande pour la duree de la transaction. */
    public function findByOrderIdForUpdate(int $orderId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM order_items WHERE order_id = :order_id ORDER BY id FOR UPDATE');
        $stmt->execute(['order_id' => $orderId]);

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM order_items WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function findByVendorId(int $vendorId, int $limit = 50): array
    {
        $stmt = $this->db->prepare('
            SELECT oi.*, o.created_at AS order_created_at, o.customer_name, o.delivery_location
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE oi.vendor_id = :vendor_id
            ORDER BY oi.id DESC
            LIMIT :limit
        ');
        $stmt->bindValue('vendor_id', $vendorId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO order_items (
                order_id, product_id, shop_id, vendor_id, product_name, unit_price, quantity,
                subtotal, commission_rate, commission_amount, vendor_net_amount
            ) VALUES (
                :order_id, :product_id, :shop_id, :vendor_id, :product_name, :unit_price, :quantity,
                :subtotal, :commission_rate, :commission_amount, :vendor_net_amount
            )
        ');
        $stmt->execute([
            'order_id' => $data['order_id'],
            'product_id' => $data['product_id'],
            'shop_id' => $data['shop_id'],
            'vendor_id' => $data['vendor_id'],
            'product_name' => $data['product_name'],
            'unit_price' => $data['unit_price'],
            'quantity' => $data['quantity'],
            'subtotal' => $data['subtotal'],
            'commission_rate' => $data['commission_rate'],
            'commission_amount' => $data['commission_amount'],
            'vendor_net_amount' => $data['vendor_net_amount'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function markWalletCredited(int $orderItemId): void
    {
        $stmt = $this->db->prepare("UPDATE order_items SET wallet_credited = 1, wallet_credited_at = CURRENT_TIMESTAMP, payment_status = 'paid' WHERE id = :id");
        $stmt->execute(['id' => $orderItemId]);
    }

    public function setFulfillmentStatus(int $orderItemId, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE order_items SET fulfillment_status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $orderItemId]);
    }

    /**
     * Fait avancer TOUTES les lignes d'une commande vers le meme statut logistique
     * (une commande = une livraison physique, meme si elle contient des articles
     * de plusieurs boutiques). Fige delivered_at au passage a 'delivered' : c'est
     * le point de depart du delai settings.wallet_hold_days (voir WalletReleaseService).
     */
    public function setFulfillmentStatusByOrderId(int $orderId, string $status): void
    {
        if ($status === 'delivered') {
            $stmt = $this->db->prepare("
                UPDATE order_items
                SET fulfillment_status = :status, delivered_at = CURRENT_TIMESTAMP
                WHERE order_id = :order_id AND delivered_at IS NULL
            ");
        } else {
            $stmt = $this->db->prepare('UPDATE order_items SET fulfillment_status = :status WHERE order_id = :order_id');
        }
        $stmt->execute(['status' => $status, 'order_id' => $orderId]);
    }

    /** Verrouille la ligne pour la duree de la transaction (utilise par WalletReleaseService). */
    public function findByIdForUpdate(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM order_items WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Lignes livrees, deja creditees au wallet (pending), pas encore liberees vers
     * available_balance, et dont le delai de retenue (wallet_hold_days) est ecoule.
     */
    public function findMaturedForRelease(int $holdDays): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM order_items
            WHERE wallet_credited = 1
              AND wallet_released_at IS NULL
              AND fulfillment_status = 'delivered'
              AND delivered_at IS NOT NULL
              AND delivered_at <= DATE_SUB(NOW(), INTERVAL :hold_days DAY)
            ORDER BY delivered_at ASC
        ");
        $stmt->bindValue('hold_days', $holdDays, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function markWalletReleased(int $orderItemId): void
    {
        $stmt = $this->db->prepare('UPDATE order_items SET wallet_released_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute(['id' => $orderItemId]);
    }

    public function applyRefund(int $orderItemId, int $refundedQuantity, int $refundedAmount, string $refundStatus): void
    {
        $stmt = $this->db->prepare('
            UPDATE order_items
            SET refunded_quantity = refunded_quantity + :qty,
                refunded_amount = refunded_amount + :amount,
                refund_status = :status,
                payment_status = IF(:status2 = "full", "refunded", "partially_refunded")
            WHERE id = :id
        ');
        $stmt->execute([
            'qty' => $refundedQuantity,
            'amount' => $refundedAmount,
            'status' => $refundStatus,
            'status2' => $refundStatus,
            'id' => $orderItemId,
        ]);
    }
}
