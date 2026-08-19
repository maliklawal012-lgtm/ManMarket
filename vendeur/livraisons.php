<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$vendorUser = require_vendor();
$vendorShop = current_vendor_shop((int) $vendorUser['id']);

if (!$vendorShop) {
    header('Location: /market/vendeur/index');
    exit;
}

$pageTitle = 'Livraisons';
require_once __DIR__ . '/../includes/vendor_header.php';

$db = get_db();
$shopId = (int) $vendorShop['id'];

$stmt = $db->prepare("
    SELECT DISTINCT o.id, o.created_at, o.fulfillment_status, o.customer_name AS name, o.customer_phone AS phone, o.delivery_location
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    WHERE oi.shop_id = :shop_id AND o.fulfillment_status IN ('processing', 'shipping')
    ORDER BY FIELD(o.fulfillment_status, 'shipping', 'processing'), o.created_at ASC
");
$stmt->execute(['shop_id' => $shopId]);
$orders = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT o.fulfillment_status AS status, COUNT(DISTINCT o.id) AS total
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    WHERE oi.shop_id = :shop_id AND o.fulfillment_status IN ('processing', 'shipping')
    GROUP BY o.fulfillment_status
");
$stmt->execute(['shop_id' => $shopId]);
$counts = array_column($stmt->fetchAll(), 'total', 'status');
$preparingCount = (int) ($counts['processing'] ?? 0);
$shippingCount = (int) ($counts['shipping'] ?? 0);

$itemsStmt = $db->prepare('SELECT * FROM order_items WHERE order_id = :order_id AND shop_id = :shop_id');
?>

<div class="admin-stats-grid" style="grid-template-columns: repeat(2, 1fr);">
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#e0e7ff; color:#4338ca;"><?= icon('shopping-basket', 18) ?></span>
        <span class="admin-stat-value"><?= $preparingCount ?></span>
        <span class="admin-stat-label">Commande(s) en préparation</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#dbeafe; color:#1d4ed8;"><?= icon('truck', 18) ?></span>
        <span class="admin-stat-value"><?= $shippingCount ?></span>
        <span class="admin-stat-label">Commande(s) en livraison</span>
    </div>
</div>

<div class="card">
    <div class="admin-toolbar">
        <h2>À livrer (<?= count($orders) ?>)</h2>
        <a href="/market/vendeur/commandes" class="link-more">Toutes mes commandes <?= icon('chevron-right', 14) ?></a>
    </div>

    <?php if (!$orders): ?>
        <p class="empty-state">Aucune commande en préparation ou en livraison pour le moment.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Livraison</th>
                        <th>Articles (chez vous)</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <?php
                        $itemsStmt->execute(['order_id' => $order['id'], 'shop_id' => $shopId]);
                        $items = $itemsStmt->fetchAll();
                        ?>
                        <tr>
                            <td><?= e(date('d/m/Y H:i', strtotime((string) $order['created_at']))) ?></td>
                            <td><?= e($order['name']) ?><?php if ($order['phone']): ?><br><span class="char-count"><?= e($order['phone']) ?></span><?php endif; ?></td>
                            <td><?= $order['delivery_location'] ? '<strong>' . e($order['delivery_location']) . '</strong>' : '<span class="char-count">—</span>' ?></td>
                            <td class="wrap">
                                <?php foreach ($items as $item): ?>
                                    <?= (int) $item['quantity'] ?> x <?= e($item['product_name']) ?><br>
                                <?php endforeach; ?>
                            </td>
                            <td><span class="tag <?= order_status_tag_class($order['fulfillment_status']) ?>"><?= e(order_status_label($order['fulfillment_status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="char-count">Le statut de la commande est mis à jour par l'équipe ManMarket lors de la préparation et de la livraison.</p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
