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
$orderStatuses = ['pending', 'processing', 'shipping', 'delivered', 'cancelled', 'not_collected'];

$releaseFeedback = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['status'])) {
    if (in_array($_POST['status'], $orderStatuses, true)) {
        $orderId = (int) $_POST['order_id'];

        if ($_POST['status'] === 'cancelled') {
            $currentStatusStmt = $db->prepare('SELECT fulfillment_status FROM orders WHERE id = :id');
            $currentStatusStmt->execute(['id' => $orderId]);
            if ($currentStatusStmt->fetchColumn() !== 'cancelled') {
                restore_order_stock($orderId);
            }
        }

        wallet_order_repo()->setFulfillmentStatus($orderId, $_POST['status']);
        wallet_order_item_repo()->setFulfillmentStatusByOrderId($orderId, $_POST['status']);

        if ($_POST['status'] === 'delivered') {
            wallet_release_service()->releaseMaturedHolds();
        }
        if ($_POST['status'] === 'not_collected') {
            $customerUserId = $db->prepare('SELECT customer_user_id FROM orders WHERE id = :id');
            $customerUserId->execute(['id' => $orderId]);
            $customerUserId = $customerUserId->fetchColumn();
            if ($customerUserId) {
                record_failed_pickup((int) $customerUserId);
            }
        }
        wallet_notification_service()->orderStatusChanged($orderId, $_POST['status']);
    }
    header('Location: /market/admin/commandes-actives.php' . (isset($_GET['status']) ? '?status=' . urlencode((string) $_GET['status']) : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'release_now') {
    $releaseFeedback = wallet_release_service()->releaseMaturedHolds();
}

$pageTitle = 'Commandes (nouveau système)';
require_once __DIR__ . '/../includes/admin_header.php';

$statusFilter = (string) ($_GET['status'] ?? '');
$sql = 'SELECT * FROM orders';
$params = [];
if (in_array($statusFilter, $orderStatuses, true)) {
    $sql .= ' WHERE fulfillment_status = :status';
    $params['status'] = $statusFilter;
}
$sql .= ' ORDER BY created_at DESC LIMIT 100';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$itemsStmt = $db->prepare('
    SELECT oi.*, s.name AS shop_name
    FROM order_items oi
    JOIN shops s ON s.id = oi.shop_id
    WHERE oi.order_id = :order_id
');
$paymentStmt = $db->prepare('SELECT * FROM payments WHERE order_id = :order_id ORDER BY id DESC LIMIT 1');

$holdDays = wallet_release_service()->currentHoldDays();
?>

<?php if ($releaseFeedback): ?>
    <div class="alert alert-success">
        <?= icon('check-circle', 18) ?>
        <span><?= $releaseFeedback['released'] ?> ligne(s) libérée(s) vers le solde disponible (<?= format_price($releaseFeedback['total_amount']) ?>)<?= $releaseFeedback['errors'] > 0 ? ', ' . $releaseFeedback['errors'] . ' erreur(s) — voir logs/wallet_release.log' : '' ?>.</span>
    </div>
<?php endif; ?>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Libération des soldes vendeurs</h2>
        <form method="post" action="/market/admin/commandes-actives.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="release_now">
            <button type="submit" class="btn btn-outline-primary btn-sm">Exécuter la libération maintenant</button>
        </form>
    </div>
    <p class="char-count">
        Délai de retenue configuré : <strong><?= $holdDays ?> jour(s)</strong> (<code>settings.wallet_hold_days</code>).
        Ce job tourne normalement automatiquement via <code>cron/release_wallet_holds.php</code> ; ce bouton permet de le déclencher immédiatement pour test ou rattrapage.
    </p>
</div>

<div class="card">
    <div class="admin-toolbar">
        <h2>Commandes (<?= count($orders) ?>)</h2>
        <div class="filter-sort">
            <label for="status-filter">Statut</label>
            <select id="status-filter" onchange="location.href = this.value">
                <option value="/market/admin/commandes-actives.php" <?= $statusFilter === '' ? 'selected' : '' ?>>Toutes</option>
                <?php foreach ($orderStatuses as $s): ?>
                    <option value="/market/admin/commandes-actives.php?status=<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(order_status_label($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if (!$orders): ?>
        <p class="empty-state">Aucune commande ne correspond à ce filtre. Les commandes historiques restent visibles dans <a href="/market/admin/commandes.php">Commandes (archive)</a>.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Livraison</th>
                        <th>Boutique(s)</th>
                        <th>Total</th>
                        <th>Statut</th>
                        <th>Paiement</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <?php
                        $itemsStmt->execute(['order_id' => $order['id']]);
                        $items = $itemsStmt->fetchAll();
                        $shopNames = array_unique(array_column($items, 'shop_name'));
                        $paymentStmt->execute(['order_id' => $order['id']]);
                        $payment = $paymentStmt->fetch();
                        ?>
                        <tr>
                            <td><?= e(date('d/m/Y H:i', strtotime((string) $order['created_at']))) ?></td>
                            <td><?= e($order['customer_name']) ?><?php if ($order['customer_phone']): ?><br><span class="char-count"><?= e($order['customer_phone']) ?></span><?php endif; ?></td>
                            <td><?= $order['delivery_location'] ? e($order['delivery_location']) : '<span class="char-count">—</span>' ?></td>
                            <td class="wrap"><?= e(implode(', ', $shopNames)) ?></td>
                            <td><?= format_price((int) round((float) $order['total_amount'])) ?></td>
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
                                    <a href="/market/admin/commande-detail.php?id=<?= (int) $order['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a>
                                    <form method="post" action="/market/admin/commandes-actives.php<?= $statusFilter !== '' ? '?status=' . e($statusFilter) : '' ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                        <select name="status" class="admin-inline-select" onchange="this.form.submit()">
                                            <?php foreach ($orderStatuses as $s): ?>
                                                <option value="<?= e($s) ?>" <?= $order['fulfillment_status'] === $s ? 'selected' : '' ?>><?= e(order_status_label($s)) ?></option>
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
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
