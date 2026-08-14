<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wallet_bootstrap.php';
require_once __DIR__ . '/../includes/csrf.php';

$adminUser = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$db = get_db();
$orderStatuses = ['pending', 'processing', 'shipping', 'delivered', 'cancelled'];
$orderId = (int) ($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM orders WHERE id = :id');
$stmt->execute(['id' => $orderId]);
$order = $stmt->fetch() ?: null;

if (!$order) {
    header('Location: /market/admin/commandes-actives.php');
    exit;
}

$actionError = null;
$refundFeedback = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['status']) && ($_POST['action'] ?? '') === 'change_status') {
    if (in_array($_POST['status'], $orderStatuses, true)) {
        wallet_order_repo()->setFulfillmentStatus($orderId, $_POST['status']);
        wallet_order_item_repo()->setFulfillmentStatusByOrderId($orderId, $_POST['status']);
        if ($_POST['status'] === 'delivered') {
            wallet_release_service()->releaseMaturedHolds();
        }
    }
    header('Location: /market/admin/commande-detail.php?id=' . $orderId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refund') {
    $orderItemId = (int) ($_POST['order_item_id'] ?? 0);
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $reason = trim((string) ($_POST['reason'] ?? ''));

    if ($reason === '') {
        $actionError = 'Veuillez indiquer un motif de remboursement.';
    } else {
        $result = wallet_refund_service()->refundOrderItem($orderItemId, $quantity, $reason, (int) $adminUser['id']);
        if ($result->ok) {
            header('Location: /market/admin/commande-detail.php?id=' . $orderId);
            exit;
        }
        $actionError = $result->error;
    }
}

$pageTitle = 'Commande #' . $orderId;
require_once __DIR__ . '/../includes/admin_header.php';

$stmt = $db->prepare('
    SELECT oi.*, s.name AS shop_name, v.business_name AS vendor_name
    FROM order_items oi
    JOIN shops s ON s.id = oi.shop_id
    LEFT JOIN vendors v ON v.id = oi.vendor_id
    WHERE oi.order_id = :order_id
    ORDER BY oi.id
');
$stmt->execute(['order_id' => $orderId]);
$items = $stmt->fetchAll();

$payment = (new App\Repositories\PaymentRepository($db))->findByOrderId($orderId);

$stmt = $db->prepare('
    SELECT r.*, ri.order_item_id, ri.quantity AS refund_qty, ri.refunded_gross_amount, ri.refunded_net_amount
    FROM refunds r JOIN refund_items ri ON ri.refund_id = r.id
    WHERE r.order_id = :order_id
    ORDER BY r.created_at DESC
');
$stmt->execute(['order_id' => $orderId]);
$refunds = $stmt->fetchAll();
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/admin/commandes-actives.php" class="link-more"><?= icon('chevron-right', 14) ?> Retour aux commandes</a>
</div>

<?php if ($actionError): ?>
    <div class="alert alert-error"><?= icon('x', 18) ?><span><?= e($actionError) ?></span></div>
<?php endif; ?>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Commande #<?= $orderId ?></h2>
        <form method="post" action="/market/admin/commande-detail.php?id=<?= $orderId ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_status">
            <select name="status" class="admin-inline-select" onchange="this.form.submit()">
                <?php foreach ($orderStatuses as $s): ?>
                    <option value="<?= e($s) ?>" <?= $order['fulfillment_status'] === $s ? 'selected' : '' ?>><?= e(order_status_label($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <ul class="account-info-list">
        <li><span class="account-info-label"><?= icon('user', 16) ?> Client</span><span><?= e($order['customer_name']) ?> — <?= e($order['customer_email']) ?><?php if ($order['customer_phone']): ?> — <?= e($order['customer_phone']) ?><?php endif; ?></span></li>
        <li><span class="account-info-label"><?= icon('map-pin', 16) ?> Livraison</span><span><?= $order['delivery_location'] ? e($order['delivery_location']) : '—' ?></span></li>
        <li><span class="account-info-label"><?= icon('clock', 16) ?> Passée le</span><span><?= e(date('d/m/Y à H:i', strtotime((string) $order['created_at']))) ?></span></li>
        <li><span class="account-info-label"><?= icon('cart', 16) ?> Total</span><span><?= format_price((int) round((float) $order['total_amount'])) ?></span></li>
        <li><span class="account-info-label"><?= icon('shield', 16) ?> Paiement choisi</span><span><?= $order['payment_choice'] === 'online' ? 'En ligne' : 'À la livraison' ?></span></li>
    </ul>
</div>

<?php if ($payment): ?>
    <div class="card" style="margin-bottom: var(--gap);">
        <div class="admin-toolbar">
            <h2>Paiement</h2>
            <span class="tag <?= payment_status_tag_class($payment['status']) ?>"><?= e(payment_status_label($payment['status'])) ?></span>
        </div>
        <ul class="account-info-list">
            <li><span class="account-info-label">Référence</span><span><?= e($payment['provider_reference']) ?></span></li>
            <li><span class="account-info-label">Montant</span><span><?= format_price((int) round((float) $payment['amount'])) ?> <?= e($payment['currency']) ?></span></li>
            <li><span class="account-info-label">Moyen</span><span><?= e($payment['payment_method'] ?? '—') ?></span></li>
            <li><span class="account-info-label">Confirmé le</span><span><?= $payment['confirmed_at'] ? e(date('d/m/Y à H:i', strtotime((string) $payment['confirmed_at']))) : '—' ?></span></li>
        </ul>
    </div>
<?php endif; ?>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Articles (<?= count($items) ?>)</h2>
    </div>
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Boutique / Vendeur</th>
                    <th>Produit</th>
                    <th>Qté</th>
                    <th>Sous-total</th>
                    <th>Commission</th>
                    <th>Net vendeur</th>
                    <th>Statut</th>
                    <th>Remboursé</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item):
                    $refundableQty = (int) $item['quantity'] - (int) $item['refunded_quantity'];
                    $canRefund = $payment && $payment['status'] === 'completed' && $refundableQty > 0;
                ?>
                    <tr>
                        <td><?= e($item['shop_name']) ?><?php if ($item['vendor_name']): ?><br><span class="char-count"><?= e($item['vendor_name']) ?></span><?php endif; ?></td>
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
                        <td>
                            <?php if ($canRefund): ?>
                                <details>
                                    <summary class="btn btn-outline-primary btn-sm" style="display:inline-block; cursor:pointer;">Rembourser</summary>
                                    <form method="post" action="/market/admin/commande-detail.php?id=<?= $orderId ?>" style="margin-top:8px; min-width:220px;" onsubmit="return confirm('Confirmer le remboursement ?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="refund">
                                        <input type="hidden" name="order_item_id" value="<?= (int) $item['id'] ?>">
                                        <div class="form-field">
                                            <label>Quantité (max <?= $refundableQty ?>)</label>
                                            <input type="number" name="quantity" min="1" max="<?= $refundableQty ?>" value="<?= $refundableQty ?>" required>
                                        </div>
                                        <div class="form-field">
                                            <label>Motif</label>
                                            <input type="text" name="reason" required>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-sm">Confirmer</button>
                                    </form>
                                </details>
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
            <h2>Historique des remboursements (<?= count($refunds) ?>)</h2>
        </div>
        <div class="admin-activity-list">
            <?php foreach ($refunds as $r): ?>
                <div class="admin-activity-item">
                    <span class="admin-activity-icon"><?= icon('x', 15) ?></span>
                    <div>
                        <div class="admin-activity-text"><?= (int) $r['refund_qty'] ?> unité(s) — <?= format_price((int) round((float) $r['refunded_gross_amount'])) ?> (net vendeur : <?= format_price((int) round((float) $r['refunded_net_amount'])) ?>)</div>
                        <div class="admin-activity-time"><?= e($r['reason']) ?> · <?= e(date('d/m/Y à H:i', strtotime((string) $r['created_at']))) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
