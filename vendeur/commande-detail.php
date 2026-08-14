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
    header('Location: /market/vendeur/index.php');
    exit;
}

$db = get_db();
$shopId = (int) $vendorShop['id'];
$vendorEntity = wallet_vendor_repo()->findByUserId((int) $vendorUser['id']);
$vendorStatuses = ['pending', 'confirmed', 'rejected'];
$orderId = (int) ($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM orders WHERE id = :id');
$stmt->execute(['id' => $orderId]);
$order = $stmt->fetch() ?: null;

// Garde-fou vie privée : la commande doit contenir au moins un article de CETTE boutique.
if ($order) {
    $stmt = $db->prepare('SELECT 1 FROM order_items WHERE order_id = :order_id AND shop_id = :shop_id LIMIT 1');
    $stmt->execute(['order_id' => $orderId, 'shop_id' => $shopId]);
    if (!$stmt->fetchColumn()) {
        $order = null;
    }
}

if (!$order) {
    header('Location: /market/vendeur/commandes.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vendor_status'])) {
    if (in_array($_POST['vendor_status'], $vendorStatuses, true)) {
        $stmt = $db->prepare('UPDATE order_items SET fulfillment_status = :status WHERE order_id = :order_id AND shop_id = :shop_id');
        $stmt->execute(['status' => $_POST['vendor_status'], 'order_id' => $orderId, 'shop_id' => $shopId]);
    }
    header('Location: /market/vendeur/commande-detail.php?id=' . $orderId);
    exit;
}

$pageTitle = 'Commande #' . $orderId;
require_once __DIR__ . '/../includes/vendor_header.php';

$stmt = $db->prepare('SELECT * FROM order_items WHERE order_id = :order_id AND shop_id = :shop_id ORDER BY id');
$stmt->execute(['order_id' => $orderId, 'shop_id' => $shopId]);
$items = $stmt->fetchAll();

$subtotal = 0;
$netTotal = 0;
foreach ($items as $item) {
    $subtotal += (int) round((float) $item['subtotal']);
    $netTotal += (int) round((float) $item['vendor_net_amount']);
}

$vendorStatus = $items ? $items[0]['fulfillment_status'] : 'pending';
$payment = wallet_payment_repo()->findByOrderId($orderId);

$refunds = [];
if ($vendorEntity) {
    $stmt = $db->prepare('
        SELECT ri.*, r.reason, r.created_at
        FROM refund_items ri
        JOIN refunds r ON r.id = ri.refund_id
        WHERE r.order_id = :order_id AND ri.vendor_id = :vendor_id
        ORDER BY ri.created_at DESC
    ');
    $stmt->execute(['order_id' => $orderId, 'vendor_id' => (int) $vendorEntity['id']]);
    $refunds = $stmt->fetchAll();
}
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/vendeur/commandes.php" class="link-more"><?= icon('chevron-right', 14) ?> Retour aux commandes</a>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Commande #<?= $orderId ?></h2>
        <form method="post" action="/market/vendeur/commande-detail.php?id=<?= $orderId ?>">
            <?= csrf_field() ?>
            <select name="vendor_status" class="admin-inline-select" onchange="this.form.submit()">
                <?php foreach ($vendorStatuses as $s): ?>
                    <option value="<?= e($s) ?>" <?= $vendorStatus === $s ? 'selected' : '' ?>><?= e(vendor_item_status_label($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <ul class="account-info-list">
        <li><span class="account-info-label"><?= icon('user', 16) ?> Client</span><span><?= e($order['customer_name']) ?><?php if ($order['customer_phone']): ?> — <?= e($order['customer_phone']) ?><?php endif; ?></span></li>
        <li><span class="account-info-label"><?= icon('map-pin', 16) ?> Livraison</span><span><?= $order['delivery_location'] ? e($order['delivery_location']) : '—' ?></span></li>
        <li><span class="account-info-label"><?= icon('clock', 16) ?> Passée le</span><span><?= e(date('d/m/Y à H:i', strtotime((string) $order['created_at']))) ?></span></li>
        <li><span class="account-info-label"><?= icon('cart', 16) ?> Sous-total (chez vous)</span><span><?= format_price($subtotal) ?></span></li>
        <li><span class="account-info-label"><?= icon('check-circle', 16) ?> Net vendeur (chez vous)</span><span><?= format_price($netTotal) ?></span></li>
        <li><span class="account-info-label"><?= icon('shield', 16) ?> Paiement</span><span>
            <?php if ($payment): ?>
                <span class="tag <?= payment_status_tag_class($payment['status']) ?>"><?= e(payment_status_label($payment['status'])) ?></span>
            <?php else: ?>
                À la livraison
            <?php endif; ?>
        </span></li>
    </ul>
    <p class="char-count" style="margin-top:8px;">Le statut de livraison global de la commande reste géré par l'équipe ManMarket. Ici, indiquez si vous confirmez pouvoir fournir vos produits, ou si vous devez rejeter (rupture de stock, etc).</p>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Vos articles (<?= count($items) ?>)</h2>
    </div>
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Produit</th>
                    <th>Qté</th>
                    <th>Sous-total</th>
                    <th>Commission</th>
                    <th>Net vendeur</th>
                    <th>Statut</th>
                    <th>Remboursé</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= e($item['product_name']) ?></td>
                        <td><?= (int) $item['quantity'] ?></td>
                        <td><?= format_price((int) round((float) $item['subtotal'])) ?></td>
                        <td><?= format_price((int) round((float) $item['commission_amount'])) ?></td>
                        <td><?= format_price((int) round((float) $item['vendor_net_amount'])) ?></td>
                        <td>
                            <span class="tag <?= vendor_item_status_tag_class($item['fulfillment_status']) ?>"><?= e(vendor_item_status_label($item['fulfillment_status'])) ?></span>
                            <?php if ((int) $item['wallet_credited'] === 1): ?><br><span class="char-count">Wallet crédité</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int) $item['refunded_quantity'] > 0): ?>
                                <?= (int) $item['refunded_quantity'] ?>/<?= (int) $item['quantity'] ?> — <?= format_price((int) round((float) $item['refunded_amount'])) ?>
                            <?php else: ?>
                                <span class="char-count">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($refunds): ?>
    <div class="card">
        <div class="admin-toolbar">
            <h2>Remboursements sur vos articles (<?= count($refunds) ?>)</h2>
        </div>
        <div class="admin-activity-list">
            <?php foreach ($refunds as $r): ?>
                <div class="admin-activity-item">
                    <span class="admin-activity-icon"><?= icon('x', 15) ?></span>
                    <div>
                        <div class="admin-activity-text"><?= (int) $r['quantity'] ?> unité(s) — <?= format_price((int) round((float) $r['refunded_gross_amount'])) ?> (net déduit de votre solde : <?= format_price((int) round((float) $r['refunded_net_amount'])) ?>)</div>
                        <div class="admin-activity-time"><?= e($r['reason']) ?> · <?= e(date('d/m/Y à H:i', strtotime((string) $r['created_at']))) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
