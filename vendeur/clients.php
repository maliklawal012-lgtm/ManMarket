<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$vendorUser = require_vendor();
$vendorShop = current_vendor_shop((int) $vendorUser['id']);

if (!$vendorShop) {
    header('Location: /market/vendeur/index.php');
    exit;
}

$db = get_db();
$shopId = (int) $vendorShop['id'];

$viewEmail = trim((string) ($_GET['email'] ?? ''));
$viewCustomer = null;
$viewOrders = [];
$viewReviews = [];

if ($viewEmail !== '') {
    $stmt = $db->prepare("
        SELECT
            o.customer_name AS name, o.customer_email AS email, o.customer_phone AS phone,
            MAX(o.customer_user_id) AS customer_user_id,
            COUNT(DISTINCT o.id) AS order_count,
            MAX(o.created_at) AS last_order_at,
            COALESCE(SUM(CASE WHEN o.fulfillment_status = 'delivered' THEN oi.unit_price * oi.quantity ELSE 0 END), 0) AS total_spent
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        WHERE oi.shop_id = :shop_id AND o.customer_email = :email
        GROUP BY o.customer_email, o.customer_name, o.customer_phone
    ");
    $stmt->execute(['shop_id' => $shopId, 'email' => $viewEmail]);
    $viewCustomer = $stmt->fetch() ?: null;

    // Garde-fou vie privee : n'affiche que les clients ayant reellement commande dans CETTE boutique.
    if (!$viewCustomer) {
        header('Location: /market/vendeur/clients.php');
        exit;
    }

    $stmt = $db->prepare('
        SELECT o.id, o.created_at, o.fulfillment_status, o.delivery_location
        FROM orders o
        WHERE o.customer_email = :email AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND oi.shop_id = :shop_id)
        ORDER BY o.created_at DESC
        LIMIT 20
    ');
    $stmt->execute(['email' => $viewEmail, 'shop_id' => $shopId]);
    $viewOrders = $stmt->fetchAll();

    $itemsByOrder = [];
    $viewOrderIds = array_column($viewOrders, 'id');
    if ($viewOrderIds) {
        $placeholders = implode(',', array_fill(0, count($viewOrderIds), '?'));
        $itemsStmt = $db->prepare("SELECT * FROM order_items WHERE order_id IN ($placeholders) AND shop_id = ?");
        $itemsStmt->execute([...$viewOrderIds, $shopId]);
        foreach ($itemsStmt->fetchAll() as $item) {
            $itemsByOrder[(int) $item['order_id']][] = $item;
        }
    }
    foreach ($viewOrders as &$order) {
        $order['items'] = $itemsByOrder[(int) $order['id']] ?? [];
    }
    unset($order);

    if ($viewCustomer['customer_user_id']) {
        $stmt = $db->prepare('
            SELECT r.*, p.name AS product_name, p.slug AS product_slug
            FROM reviews r JOIN products p ON p.id = r.product_id
            WHERE p.shop_id = :shop_id AND r.user_id = :user_id
            ORDER BY r.created_at DESC
        ');
        $stmt->execute(['shop_id' => $shopId, 'user_id' => (int) $viewCustomer['customer_user_id']]);
        $viewReviews = $stmt->fetchAll();
    }
}

$pageTitle = $viewCustomer ? $viewCustomer['name'] : 'Clients';
require_once __DIR__ . '/../includes/vendor_header.php';

if (!$viewCustomer) {
    $countStmt = $db->prepare('SELECT COUNT(DISTINCT o.customer_email) FROM orders o JOIN order_items oi ON oi.order_id = o.id WHERE oi.shop_id = :shop_id');
    $countStmt->execute(['shop_id' => $shopId]);
    $pagination = paginate((int) $countStmt->fetchColumn(), 20);

    $stmt = $db->prepare("
        SELECT
            o.customer_name AS name, o.customer_email AS email, o.customer_phone AS phone,
            COUNT(DISTINCT o.id) AS order_count,
            MAX(o.created_at) AS last_order_at,
            COALESCE(SUM(CASE WHEN o.fulfillment_status = 'delivered' THEN oi.unit_price * oi.quantity ELSE 0 END), 0) AS total_spent
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        WHERE oi.shop_id = :shop_id
        GROUP BY o.customer_email, o.customer_name, o.customer_phone
        ORDER BY last_order_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue('shop_id', $shopId, PDO::PARAM_INT);
    $stmt->bindValue('limit', $pagination['per_page'], PDO::PARAM_INT);
    $stmt->bindValue('offset', $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();
    $customers = $stmt->fetchAll();
}
?>

<?php if ($viewCustomer): ?>

    <div class="admin-toolbar" style="margin-bottom: var(--gap);">
        <a href="/market/vendeur/clients.php" class="link-more"><?= icon('chevron-right', 14) ?> Retour à la liste</a>
    </div>

    <div class="card" style="margin-bottom: var(--gap);">
        <div class="admin-toolbar">
            <h2><?= e($viewCustomer['name']) ?></h2>
        </div>
        <ul class="account-info-list">
            <li><span class="account-info-label"><?= icon('send', 16) ?> Email</span><span><?= e($viewCustomer['email']) ?></span></li>
            <li><span class="account-info-label"><?= icon('phone', 16) ?> Téléphone</span><span><?= e($viewCustomer['phone'] ?: 'Non renseigné') ?></span></li>
        </ul>
    </div>

    <div class="admin-stats-grid" style="grid-template-columns: repeat(3, 1fr);">
        <div class="card admin-stat-card">
            <span class="admin-stat-icon" style="background:#eef2ff; color:#4f46e5;"><?= icon('send', 18) ?></span>
            <span class="admin-stat-value"><?= (int) $viewCustomer['order_count'] ?></span>
            <span class="admin-stat-label">Commande(s) chez vous</span>
        </div>
        <div class="card admin-stat-card">
            <span class="admin-stat-icon" style="background:#e8f8ee; color:#16a34a;"><?= icon('cart', 18) ?></span>
            <span class="admin-stat-value"><?= format_price((int) round((float) $viewCustomer['total_spent'])) ?></span>
            <span class="admin-stat-label">Total dépensé (livré)</span>
        </div>
        <div class="card admin-stat-card">
            <span class="admin-stat-icon" style="background:#fdf2f8; color:#db2777;"><?= icon('star-filled', 18) ?></span>
            <span class="admin-stat-value"><?= count($viewReviews) ?></span>
            <span class="admin-stat-label">Avis déposé(s) sur vos produits</span>
        </div>
    </div>

    <div class="admin-dashboard-grid" style="margin-top: var(--gap);">
        <div class="card">
            <div class="admin-toolbar">
                <h2>Commandes chez vous (<?= count($viewOrders) ?>)</h2>
            </div>
            <?php if (!$viewOrders): ?>
                <p class="empty-state">Aucune commande.</p>
            <?php else: ?>
                <div class="admin-activity-list">
                    <?php foreach ($viewOrders as $order): ?>
                        <?php $orderTotal = array_sum(array_map(fn ($i) => (int) $i['unit_price'] * (int) $i['quantity'], $order['items'])); ?>
                        <div class="admin-activity-item">
                            <span class="admin-activity-icon"><?= icon('cart', 15) ?></span>
                            <div>
                                <div class="admin-activity-text">
                                    <?= format_price($orderTotal) ?>
                                    <span class="tag <?= order_status_tag_class($order['fulfillment_status']) ?>"><?= e(order_status_label($order['fulfillment_status'])) ?></span>
                                </div>
                                <div class="admin-activity-time">
                                    <?= e(date('d/m/Y à H:i', strtotime((string) $order['created_at']))) ?>
                                    — <?php foreach ($order['items'] as $item): ?><?= (int) $item['quantity'] ?> x <?= e($item['product_name']) ?><?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="admin-toolbar">
                <h2>Avis sur vos produits</h2>
            </div>
            <?php if (!$viewReviews): ?>
                <p class="empty-state">Aucun avis pour le moment.</p>
            <?php else: ?>
                <div class="admin-activity-list">
                    <?php foreach ($viewReviews as $r): ?>
                        <div class="admin-activity-item">
                            <span class="admin-activity-icon"><?= icon('star-filled', 15) ?></span>
                            <div>
                                <div class="admin-activity-text"><a href="/market/produit.php?slug=<?= e($r['product_slug']) ?>" class="link-muted"><?= e($r['product_name']) ?></a> <?= render_stars((float) $r['rating']) ?></div>
                                <div class="admin-activity-time"><?= $r['comment'] ? e(mb_strimwidth($r['comment'], 0, 80, '…')) . ' · ' : '' ?><?= e(date('d/m/Y', strtotime((string) $r['created_at']))) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>

<div class="card">
    <div class="admin-toolbar">
        <h2>Clients ayant commandé chez vous (<?= $pagination['total_items'] ?>)</h2>
    </div>

    <?php if (!$customers): ?>
        <p class="empty-state">Aucun client pour le moment.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Contact</th>
                        <th>Commandes</th>
                        <th>Total dépensé (livré)</th>
                        <th>Dernière commande</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $c): ?>
                        <tr>
                            <td><?= e($c['name']) ?></td>
                            <td><?= e($c['email']) ?><?php if ($c['phone']): ?><br><span class="char-count"><?= e($c['phone']) ?></span><?php endif; ?></td>
                            <td><?= (int) $c['order_count'] ?></td>
                            <td><?= $c['total_spent'] > 0 ? format_price((int) $c['total_spent']) : '—' ?></td>
                            <td><?= e(date('d/m/Y', strtotime((string) $c['last_order_at']))) ?></td>
                            <td><a href="/market/vendeur/clients.php?email=<?= urlencode((string) $c['email']) ?>" class="btn btn-outline-primary btn-sm">Détail</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pagination_html($pagination['page'], $pagination['total_pages'], '/market/vendeur/clients.php') ?>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
