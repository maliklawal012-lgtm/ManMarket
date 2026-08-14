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
    header('Location: /market/admin/livraisons.php');
    exit;
}

$pageTitle = 'Livraisons';
require_once __DIR__ . '/../includes/admin_header.php';

$stmt = $db->prepare("
    SELECT * FROM contact_messages
    WHERE subject = 'Commande' AND status IN ('processing', 'shipping')
    ORDER BY FIELD(status, 'shipping', 'processing'), created_at ASC
");
$stmt->execute();
$orders = $stmt->fetchAll();

$preparingCount = (int) $db->query("SELECT COUNT(*) FROM contact_messages WHERE subject = 'Commande' AND status = 'processing'")->fetchColumn();
$shippingCount = (int) $db->query("SELECT COUNT(*) FROM contact_messages WHERE subject = 'Commande' AND status = 'shipping'")->fetchColumn();
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
        <a href="/market/admin/commandes.php" class="link-more">Toutes les commandes <?= icon('chevron-right', 14) ?></a>
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
                        <th>Contact</th>
                        <th>Livraison</th>
                        <th>Message</th>
                        <th>Statut</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><?= e(date('d/m/Y H:i', strtotime((string) $order['created_at']))) ?></td>
                            <td><?= e($order['name']) ?></td>
                            <td>
                                <?= e($order['email']) ?>
                                <?php if ($order['phone']): ?><br><?= e($order['phone']) ?><?php endif; ?>
                            </td>
                            <td><?= $order['delivery_location'] ? '<strong>' . e($order['delivery_location']) . '</strong>' : '<span class="char-count">—</span>' ?></td>
                            <td class="wrap"><?= nl2br(e($order['message'])) ?></td>
                            <td><span class="tag <?= order_status_tag_class($order['status']) ?>"><?= e(order_status_label($order['status'])) ?></span></td>
                            <td>
                                <form method="post" action="/market/admin/livraisons.php">
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
