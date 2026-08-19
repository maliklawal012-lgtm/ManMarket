<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wallet_bootstrap.php';
require_once __DIR__ . '/../includes/csrf.php';

$vendorUser = require_vendor();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}
$vendorShop = current_vendor_shop((int) $vendorUser['id']);

if (!$vendorShop) {
    header('Location: /market/vendeur/index');
    exit;
}

$db = get_db();
$shopId = (int) $vendorShop['id'];
$vendorStatuses = ['pending', 'confirmed', 'rejected'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['vendor_status'])) {
    if (in_array($_POST['vendor_status'], $vendorStatuses, true)) {
        $postedOrderId = (int) $_POST['order_id'];
        if ($_POST['vendor_status'] === 'rejected') {
            $justRejectedItems = restore_rejected_order_items_stock($postedOrderId, $shopId);
            if ($justRejectedItems) {
                wallet_notification_service()->orderItemRejected($postedOrderId, $shopId, $justRejectedItems);
            }
        }
        $stmt = $db->prepare('UPDATE order_items SET fulfillment_status = :status WHERE order_id = :order_id AND shop_id = :shop_id');
        $stmt->execute(['status' => $_POST['vendor_status'], 'order_id' => $postedOrderId, 'shop_id' => $shopId]);
    }
    header('Location: /market/vendeur/commandes');
    exit;
}

$pageTitle = 'Commandes';
require_once __DIR__ . '/../includes/vendor_header.php';

$stmt = $db->prepare('
    SELECT DISTINCT o.id, o.created_at, o.fulfillment_status, o.customer_name AS name, o.customer_phone AS phone, o.delivery_location
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    WHERE oi.shop_id = :shop_id
    ORDER BY o.created_at DESC
');
$stmt->execute(['shop_id' => $shopId]);
$orders = $stmt->fetchAll();

// Articles et paiements de toutes les commandes affichees, recuperes en 2
// requetes groupees (IN (...)) plutot qu'une requete par commande dans la
// boucle d'affichage ci-dessous (page la plus consultee de l'espace vendeur).
$itemsByOrder = [];
$paymentsByOrder = [];
$orderIds = array_column($orders, 'id');
if ($orderIds) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

    $itemsStmt = $db->prepare("SELECT * FROM order_items WHERE order_id IN ($placeholders) AND shop_id = ?");
    $itemsStmt->execute([...$orderIds, $shopId]);
    foreach ($itemsStmt->fetchAll() as $item) {
        $itemsByOrder[(int) $item['order_id']][] = $item;
    }

    // Le paiement le plus recent par commande : tries par id DESC, on ne
    // garde que la premiere occurrence par order_id (equivalent a l'ancien
    // findByOrderId() execute une fois par commande).
    $paymentsStmt = $db->prepare("SELECT * FROM payments WHERE order_id IN ($placeholders) ORDER BY id DESC");
    $paymentsStmt->execute($orderIds);
    foreach ($paymentsStmt->fetchAll() as $payment) {
        $paymentsByOrder[(int) $payment['order_id']] ??= $payment;
    }
}

// Fait correspondre le texte libre "Ville" / "Ville - Quartier" a l'ID de la localite, pour lier vers sa fiche.
$locationIdsByLabel = [];
$allCities = $db->query('SELECT * FROM locations WHERE parent_id IS NULL')->fetchAll();
foreach ($allCities as $city) {
    $locationIdsByLabel[$city['name']] = (int) $city['id'];
}
$stmt = $db->query('SELECT l.id, l.name, p.name AS parent_name FROM locations l JOIN locations p ON p.id = l.parent_id');
foreach ($stmt->fetchAll() as $n) {
    $locationIdsByLabel[$n['parent_name'] . ' - ' . $n['name']] = (int) $n['id'];
}
?>

<div class="card">
    <div class="admin-toolbar">
        <h2>Commandes contenant mes produits (<?= count($orders) ?>)</h2>
    </div>

    <?php if (!$orders): ?>
        <p class="empty-state">Aucune commande ne contient encore l'un de vos produits.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Livraison</th>
                        <th>Articles commandés (chez vous)</th>
                        <th>Sous-total</th>
                        <th>Statut livraison</th>
                        <th>Paiement</th>
                        <th>Ma commande</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <?php
                        $items = $itemsByOrder[(int) $order['id']] ?? [];
                        $subtotal = 0;
                        foreach ($items as $item) {
                            $subtotal += (int) $item['unit_price'] * (int) $item['quantity'];
                        }
                        $vendorStatus = $items ? $items[0]['fulfillment_status'] : 'pending';
                        $payment = $paymentsByOrder[(int) $order['id']] ?? null;
                        ?>
                        <tr>
                            <td><?= e(date('d/m/Y H:i', strtotime((string) $order['created_at']))) ?></td>
                            <td><?= e($order['name']) ?><?php if ($order['phone']): ?><br><span class="char-count"><?= e($order['phone']) ?></span><?php endif; ?></td>
                            <td>
                                <?php if (!$order['delivery_location']): ?>
                                    <span class="char-count">—</span>
                                <?php elseif (isset($locationIdsByLabel[$order['delivery_location']])): ?>
                                    <a href="/market/vendeur/localite-detail?id=<?= $locationIdsByLabel[$order['delivery_location']] ?>" class="link-muted"><?= e($order['delivery_location']) ?></a>
                                <?php else: ?>
                                    <?= e($order['delivery_location']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="wrap">
                                <?php foreach ($items as $item): ?>
                                    <?= (int) $item['quantity'] ?> x <?= e($item['product_name']) ?><br>
                                <?php endforeach; ?>
                            </td>
                            <td><?= format_price($subtotal) ?></td>
                            <td><span class="tag <?= order_status_tag_class($order['fulfillment_status']) ?>"><?= e(order_status_label($order['fulfillment_status'])) ?></span></td>
                            <td>
                                <?php if ($payment): ?>
                                    <span class="tag <?= payment_status_tag_class($payment['status']) ?>"><?= e(payment_status_label($payment['status'])) ?></span>
                                <?php else: ?>
                                    <span class="char-count">À la livraison</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="admin-table-actions">
                                    <a href="/market/vendeur/commande-detail?id=<?= (int) $order['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a>
                                    <form method="post" action="/market/vendeur/commandes">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                        <select name="vendor_status" class="admin-inline-select" onchange="this.form.submit()">
                                            <?php foreach ($vendorStatuses as $s): ?>
                                                <option value="<?= e($s) ?>" <?= $vendorStatus === $s ? 'selected' : '' ?>><?= e(vendor_item_status_label($s)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="char-count">« Ma commande » : indiquez si vous confirmez pouvoir fournir vos produits pour cette commande, ou si vous devez la rejeter (rupture de stock, etc). Le statut de livraison reste géré par l'équipe ManMarket.</p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
