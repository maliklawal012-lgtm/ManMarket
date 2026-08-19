<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$db = get_db();
$orderStatuses = ['pending', 'processing', 'shipping', 'delivered', 'cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['status'])) {
    if (in_array($_POST['status'], $orderStatuses, true)) {
        $stmt = $db->prepare("UPDATE contact_messages SET status = :status WHERE id = :id AND subject = 'Commande'");
        $stmt->execute(['status' => $_POST['status'], 'id' => (int) $_POST['order_id']]);
    }
    header('Location: /market/admin/commandes.php' . (isset($_GET['status']) ? '?status=' . urlencode((string) $_GET['status']) : ''));
    exit;
}

$pageTitle = 'Commandes';
require_once __DIR__ . '/../includes/admin_header.php';

$statusFilter = (string) ($_GET['status'] ?? '');
$sql = "SELECT * FROM contact_messages WHERE subject = 'Commande'";
$params = [];
if (in_array($statusFilter, $orderStatuses, true)) {
    $sql .= ' AND status = :status';
    $params['status'] = $statusFilter;
}
$sql .= ' ORDER BY created_at DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$vendorStatusPriority = ['rejected' => 0, 'pending' => 1, 'confirmed' => 2];

// Requetes groupees (au lieu d'une requete par commande) : voir le meme
// correctif deja applique a admin/commandes-actives.php et vendeur/commandes.php.
$vendorRowsByOrder = [];
$paymentsByOrder = [];
$orderIds = array_column($orders, 'id');
if ($orderIds) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $vendorStmt = $db->prepare("
        SELECT oi.order_id, s.id AS shop_id, s.name AS shop_name, oi.vendor_status
        FROM legacy_order_items oi
        JOIN shops s ON s.id = oi.shop_id
        WHERE oi.order_id IN ($placeholders)
    ");
    $vendorStmt->execute($orderIds);
    foreach ($vendorStmt->fetchAll() as $row) {
        $vendorRowsByOrder[(int) $row['order_id']][] = $row;
    }

    $paymentsStmt = $db->prepare("SELECT * FROM legacy_payments WHERE order_id IN ($placeholders) ORDER BY id DESC");
    $paymentsStmt->execute($orderIds);
    foreach ($paymentsStmt->fetchAll() as $row) {
        $paymentsByOrder[(int) $row['order_id']] ??= $row;
    }
}
?>

<div class="card">
    <div class="admin-toolbar">
        <h2>Commandes (<?= count($orders) ?>)</h2>
        <div class="filter-sort">
            <label for="status-filter">Statut</label>
            <select id="status-filter" onchange="location.href = this.value">
                <option value="/market/admin/commandes.php" <?= $statusFilter === '' ? 'selected' : '' ?>>Toutes</option>
                <?php foreach ($orderStatuses as $s): ?>
                    <option value="/market/admin/commandes.php?status=<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(order_status_label($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if (!$orders): ?>
        <p class="empty-state">Aucune commande ne correspond à ce filtre.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Contact</th>
                        <th>Livraison</th>
                        <th>Commerçant(s)</th>
                        <th>Message</th>
                        <th>Statut</th>
                        <th>Paiement</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <?php
                        $vendorRows = $vendorRowsByOrder[(int) $order['id']] ?? [];
                        $vendorByShop = [];
                        foreach ($vendorRows as $vr) {
                            $shopId = (int) $vr['shop_id'];
                            if (!isset($vendorByShop[$shopId]) || $vendorStatusPriority[$vr['vendor_status']] < $vendorStatusPriority[$vendorByShop[$shopId]['vendor_status']]) {
                                $vendorByShop[$shopId] = $vr;
                            }
                        }
                        $payment = $paymentsByOrder[(int) $order['id']] ?? null;
                        ?>
                        <tr>
                            <td><?= e(date('d/m/Y H:i', strtotime((string) $order['created_at']))) ?></td>
                            <td><?= e($order['name']) ?><?= $order['user_id'] ? ' <span class="tag tag-green">Compte</span>' : '' ?></td>
                            <td><?= e($order['email']) ?><?php if ($order['phone']): ?><br><?= e($order['phone']) ?><?php endif; ?></td>
                            <td><?= $order['delivery_location'] ? e($order['delivery_location']) : '<span class="char-count">—</span>' ?></td>
                            <td class="wrap">
                                <?php foreach ($vendorByShop as $vr): ?>
                                    <?= e($vr['shop_name']) ?> <span class="tag <?= vendor_item_status_tag_class($vr['vendor_status']) ?>"><?= e(vendor_item_status_label($vr['vendor_status'])) ?></span><br>
                                <?php endforeach; ?>
                            </td>
                            <td class="wrap"><?= nl2br(e($order['message'])) ?></td>
                            <td><span class="tag <?= order_status_tag_class($order['status']) ?>"><?= e(order_status_label($order['status'])) ?></span></td>
                            <td>
                                <?php if ($payment): ?>
                                    <span class="tag <?= payment_status_tag_class($payment['status']) ?>"><?= e(payment_status_label($payment['status'])) ?></span>
                                    <?php if ($payment['payment_method']): ?><br><span class="char-count"><?= e($payment['payment_method']) ?></span><?php endif; ?>
                                <?php else: ?>
                                    <span class="char-count">Paiement à la livraison</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" action="/market/admin/commandes.php<?= $statusFilter !== '' ? '?status=' . e($statusFilter) : '' ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <select name="status" class="admin-inline-select" onchange="this.form.submit()">
                                        <?php foreach ($orderStatuses as $s): ?>
                                            <option value="<?= e($s) ?>" <?= $order['status'] === $s ? 'selected' : '' ?>><?= e(order_status_label($s)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
