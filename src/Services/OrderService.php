<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderItemRepository;
use App\Repositories\OrderRepository;
use App\Repositories\VendorRepository;
use PDO;

/**
 * Cree une commande a partir d'un panier envoye par le client. Le navigateur
 * ne fournit QUE des couples (product_id, qty) : le prix, le vendor_id, le
 * shop_id et la commission sont TOUJOURS relus/calcules cote serveur depuis
 * la base de donnees, jamais acceptes tels quels du client.
 */
final class OrderService
{
    public function __construct(
        private PDO $db,
        private OrderRepository $orders,
        private OrderItemRepository $orderItems,
        private VendorRepository $vendors,
        private CommissionService $commissionService,
        private ?NotificationService $notifications = null
    ) {
    }

    /**
     * @param array<int, array{id:int, qty:int, size?:string}> $cartItems venant du navigateur — id/qty/size UNIQUEMENT.
     * @throws \InvalidArgumentException si le panier est vide apres validation serveur.
     */
    public function createFromCart(array $cartItems, array $customer, ?string $deliveryLocation, string $paymentChoice): OrderCreationResult
    {
        $rate = $this->commissionService->currentRate();
        $resolvedItems = $this->resolveCartItems($cartItems, $rate);

        if (!$resolvedItems) {
            throw new \InvalidArgumentException('Panier vide ou produits indisponibles.');
        }

        $subtotal = array_sum(array_column($resolvedItems, 'subtotal'));

        $this->db->beginTransaction();
        try {
            $orderId = $this->orders->create([
                'customer_user_id' => $customer['user_id'] ?? null,
                'customer_name' => $customer['name'],
                'customer_email' => $customer['email'],
                'customer_phone' => $customer['phone'] ?? null,
                'delivery_location' => $deliveryLocation,
                'subtotal_amount' => $subtotal,
                'total_amount' => $subtotal,
                'payment_choice' => $paymentChoice,
            ]);

            $orderItemIds = [];
            foreach ($resolvedItems as $item) {
                $orderItemIds[] = $this->orderItems->create([
                    'order_id' => $orderId,
                    'product_id' => $item['product_id'],
                    'shop_id' => $item['shop_id'],
                    'vendor_id' => $item['vendor_id'],
                    'product_name' => $item['product_name'],
                    'unit_price' => $item['unit_price'],
                    'quantity' => $item['quantity'],
                    'size' => $item['size'],
                    'subtotal' => $item['subtotal'],
                    'commission_rate' => $rate,
                    'commission_amount' => $item['commission_amount'],
                    'vendor_net_amount' => $item['net_amount'],
                ]);
            }

            $this->db->commit();

            $this->notifications?->orderCreated($orderId);
            $this->notifications?->newOrderForVendors($orderId, $resolvedItems);

            return new OrderCreationResult($orderId, $subtotal, $orderItemIds);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Relit chaque produit en base (prix, boutique, vendeur, stock, statut boutique,
     * abonnement actif) et calcule le sous-total/commission. Ignore silencieusement
     * les lignes invalides (produit inexistant, boutique fermee, quantite absurde)
     * exactement comme le fait deja le code de commande actuel du site.
     */
    private function resolveCartItems(array $cartItems, float $rate): array
    {
        $stmt = $this->db->prepare('
            SELECT p.*, s.name AS shop_name, s.is_open AS shop_is_open, s.vendor_id AS shop_vendor_id, s.owner_id AS shop_owner_id
            FROM products p
            JOIN shops s ON s.id = p.shop_id
            WHERE p.id = :id
        ');
        $sizeStmt = $this->db->prepare('SELECT stock FROM product_sizes WHERE product_id = :product_id AND size = :size');

        $resolved = [];
        foreach (array_slice($cartItems, 0, 50) as $rawItem) {
            $productId = (int) ($rawItem['id'] ?? 0);
            $qty = (int) ($rawItem['qty'] ?? 0);
            $size = isset($rawItem['size']) && is_string($rawItem['size']) && $rawItem['size'] !== '' ? $rawItem['size'] : null;
            if ($productId <= 0 || $qty <= 0 || $qty > 99) {
                continue;
            }

            $stmt->execute(['id' => $productId]);
            $product = $stmt->fetch();
            if (!$product || !$product['shop_is_open']) {
                continue;
            }

            $vendorId = $this->resolveVendorId($product);
            if ($vendorId === null) {
                continue;
            }

            $availableStock = max(0, (int) $product['stock']);
            if ($product['size_type'] !== 'none') {
                if ($size === null) {
                    continue;
                }
                $sizeStmt->execute(['product_id' => $productId, 'size' => $size]);
                $sizeStock = $sizeStmt->fetchColumn();
                if ($sizeStock === false) {
                    continue;
                }
                $availableStock = max(0, (int) $sizeStock);
            } else {
                $size = null;
            }

            $qty = min($qty, $availableStock);
            if ($qty <= 0) {
                continue;
            }

            $unitPrice = (int) round((float) $product['price']);
            $subtotal = $unitPrice * $qty;
            $split = $this->commissionService->calculate($subtotal, $rate);

            $resolved[] = [
                'product_id' => (int) $product['id'],
                'shop_id' => (int) $product['shop_id'],
                'vendor_id' => $vendorId,
                'product_name' => $product['name'],
                'unit_price' => $unitPrice,
                'quantity' => $qty,
                'size' => $size,
                'subtotal' => $subtotal,
                'commission_amount' => $split['commission_amount'],
                'net_amount' => $split['net_amount'],
            ];
        }

        return $resolved;
    }

    /**
     * shops.vendor_id est la reference cible (nouvelle architecture). Tant que
     * la migration des boutiques existantes n'a pas rempli cette colonne, on
     * retombe sur shops.owner_id -> vendors.user_id (compatibilite ascendante).
     * Ne cree JAMAIS de vendor a la volee ici : la creation de l'entite vendor
     * est une action admin explicite (voir admin/boutiques.php), jamais un
     * effet de bord d'une commande client. Sans vendor resolu, la ligne est
     * ignoree (voir resolveCartItems) plutot que d'inventer un vendeur.
     */
    private function resolveVendorId(array $product): ?int
    {
        if (!empty($product['shop_vendor_id'])) {
            return (int) $product['shop_vendor_id'];
        }

        if (empty($product['shop_owner_id'])) {
            return null;
        }

        $vendor = $this->vendors->findByUserId((int) $product['shop_owner_id']);
        if ($vendor) {
            return (int) $vendor['id'];
        }

        return null;
    }
}
