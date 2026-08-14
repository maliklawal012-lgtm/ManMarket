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
$shopId = (int) ($_GET['id'] ?? 0);

$stmt = $db->prepare('
    SELECT s.*, u.name AS owner_name, u.email AS owner_email, v.id AS vendor_id, v.status AS vendor_status
    FROM shops s
    LEFT JOIN users u ON u.id = s.owner_id
    LEFT JOIN vendors v ON v.id = s.vendor_id
    WHERE s.id = :id
');
$stmt->execute(['id' => $shopId]);
$shop = $stmt->fetch() ?: null;

if (!$shop) {
    header('Location: /market/admin/boutiques.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_open'])) {
    $db->prepare('UPDATE shops SET is_open = IF(is_open = 1, 0, 1) WHERE id = :id')->execute(['id' => $shopId]);
    header('Location: /market/admin/boutique-detail.php?id=' . $shopId);
    exit;
}

$pageTitle = $shop['name'];
require_once __DIR__ . '/../includes/admin_header.php';

$activeSubscription = get_shop_active_subscription($shopId);
$subscriptionHistory = $db->prepare('SELECT * FROM shop_subscriptions WHERE shop_id = :id ORDER BY ends_at DESC LIMIT 10');
$subscriptionHistory->execute(['id' => $shopId]);
$subscriptionHistory = $subscriptionHistory->fetchAll();

$stmt = $db->prepare('SELECT COUNT(*) FROM products WHERE shop_id = :id');
$stmt->execute(['id' => $shopId]);
$productCount = (int) $stmt->fetchColumn();

$stmt = $db->prepare('
    SELECT COUNT(r.id) AS review_count, COALESCE(AVG(r.rating), 0) AS avg_rating
    FROM reviews r JOIN products p ON p.id = r.product_id
    WHERE p.shop_id = :id
');
$stmt->execute(['id' => $shopId]);
$reviewStats = $stmt->fetch();

$stmt = $db->prepare("
    SELECT COALESCE(SUM(oi.unit_price * oi.quantity), 0) AS revenue, COALESCE(SUM(oi.quantity), 0) AS items_sold, COUNT(DISTINCT oi.order_id) AS order_count
    FROM order_items oi JOIN orders o ON o.id = oi.order_id
    WHERE oi.shop_id = :id AND o.fulfillment_status = 'delivered'
");
$stmt->execute(['id' => $shopId]);
$revenueStats = $stmt->fetch();

$products = $db->prepare('SELECT * FROM products WHERE shop_id = :id ORDER BY stock ASC, name ASC');
$products->execute(['id' => $shopId]);
$products = $products->fetchAll();

$recentOrders = $db->prepare("
    SELECT DISTINCT o.id, o.created_at, o.fulfillment_status, o.customer_name
    FROM orders o JOIN order_items oi ON oi.order_id = o.id
    WHERE oi.shop_id = :id
    ORDER BY o.created_at DESC LIMIT 5
");
$recentOrders->execute(['id' => $shopId]);
$recentOrders = $recentOrders->fetchAll();

$recentReviews = $db->prepare('
    SELECT r.*, p.name AS product_name, p.slug AS product_slug
    FROM reviews r JOIN products p ON p.id = r.product_id
    WHERE p.shop_id = :id
    ORDER BY r.created_at DESC LIMIT 5
');
$recentReviews->execute(['id' => $shopId]);
$recentReviews = $recentReviews->fetchAll();
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/admin/boutiques.php" class="link-more"><?= icon('chevron-right', 14) ?> Retour aux boutiques</a>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>
            <span class="shop-logo" style="background:<?= e($shop['color']) ?>; width:32px; height:32px; font-size:.72rem; display:inline-flex; vertical-align:middle; margin-right:8px;"><?= shop_logo_html($shop) ?></span>
            <?= e($shop['name']) ?>
        </h2>
        <div class="admin-table-actions">
            <span class="tag <?= $shop['is_open'] ? 'tag-open' : 'tag-closed' ?>"><?= $shop['is_open'] ? 'Ouverte' : 'Fermée' ?></span>
            <span class="tag <?= $activeSubscription ? 'tag-open' : 'tag-closed' ?>"><?= $activeSubscription ? 'Abonnement actif' : 'Sans abonnement' ?></span>
            <a href="/market/boutique.php?slug=<?= urlencode((string) $shop['slug']) ?>" class="btn btn-outline-primary btn-sm" target="_blank"><?= icon('chevron-right', 14) ?> Voir la page publique</a>
            <a href="/market/admin/boutiques.php?action=edit&id=<?= $shopId ?>" class="btn btn-outline-primary btn-sm">Modifier</a>
            <form method="post" action="/market/admin/boutique-detail.php?id=<?= $shopId ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="toggle_open" value="1">
                <button type="submit" class="btn btn-outline-primary btn-sm"><?= $shop['is_open'] ? 'Fermer' : 'Ouvrir' ?></button>
            </form>
        </div>
    </div>
    <p class="char-count">
        <?= e($shop['neighborhood']) ?><?php if ($shop['phone']): ?> · <?= e($shop['phone']) ?><?php endif; ?>
        <?php if ($shop['owner_name']): ?><br>Propriétaire : <?= e($shop['owner_name']) ?> (<?= e($shop['owner_email']) ?>)<?php else: ?><br>Aucun propriétaire assigné.<?php endif; ?>
        <?php if ($shop['vendor_id']): ?>
            — <a href="/market/admin/vendeur-finance.php?id=<?= (int) $shop['vendor_id'] ?>" class="link-muted">Voir les finances du vendeur <?= icon('chevron-right', 12) ?></a>
            <?php if ($shop['vendor_status'] === 'suspended'): ?><span class="tag tag-closed">Vendeur suspendu</span><?php endif; ?>
        <?php endif; ?>
    </p>
</div>

<div class="admin-stats-grid">
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#e8f8ee; color:#16a34a;"><?= icon('cart', 18) ?></span>
        <span class="admin-stat-value"><?= format_price((int) $revenueStats['revenue']) ?></span>
        <span class="admin-stat-label">Chiffre d'affaires (livré, nouveau système)</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#eef2ff; color:#4f46e5;"><?= icon('send', 18) ?></span>
        <span class="admin-stat-value"><?= (int) $revenueStats['order_count'] ?></span>
        <span class="admin-stat-label">Commande(s) (nouveau système)</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fff7ed; color:#d97706;"><?= icon('shopping-basket', 18) ?></span>
        <span class="admin-stat-value"><?= $productCount ?></span>
        <span class="admin-stat-label">Produit(s) en ligne</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fdf2f8; color:#db2777;"><?= icon('star-filled', 18) ?></span>
        <span class="admin-stat-value"><?= $reviewStats['review_count'] > 0 ? number_format((float) $reviewStats['avg_rating'], 1) : '—' ?></span>
        <span class="admin-stat-label"><?= (int) $reviewStats['review_count'] ?> avis</span>
    </div>
</div>

<div class="admin-dashboard-grid">
    <div class="col">
        <div class="card" style="margin-bottom: var(--gap);">
            <div class="admin-toolbar">
                <h2>Produits (<?= count($products) ?>)</h2>
                <a href="/market/admin/produits.php" class="link-more">Gérer les produits <?= icon('chevron-right', 14) ?></a>
            </div>
            <?php if (!$products): ?>
                <p class="empty-state">Aucun produit pour le moment.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead><tr><th>Produit</th><th>Prix</th><th>Stock</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach (array_slice($products, 0, 10) as $p): ?>
                                <tr>
                                    <td><?= e($p['name']) ?></td>
                                    <td><?= format_price((int) $p['price']) ?></td>
                                    <td><?= (int) $p['stock'] === 0 ? '<span class="tag tag-closed">Rupture</span>' : (int) $p['stock'] ?></td>
                                    <td><a href="/market/admin/produit-detail.php?id=<?= (int) $p['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($products) > 10): ?><p class="char-count">+ <?= count($products) - 10 ?> autre(s) produit(s).</p><?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="admin-toolbar">
                <h2>Historique d'abonnement</h2>
                <a href="/market/admin/abonnements.php" class="link-more">Gérer <?= icon('chevron-right', 14) ?></a>
            </div>
            <?php if (!$subscriptionHistory): ?>
                <p class="empty-state">Aucun abonnement enregistré.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead><tr><th>Formule</th><th>Prix payé</th><th>Début</th><th>Fin</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($subscriptionHistory as $sub): ?>
                                <tr>
                                    <td><?= e($sub['plan_name']) ?></td>
                                    <td><?= format_price((int) $sub['price_paid']) ?></td>
                                    <td><?= e(date('d/m/Y', strtotime((string) $sub['starts_at']))) ?></td>
                                    <td><?= e(date('d/m/Y', strtotime((string) $sub['ends_at']))) ?></td>
                                    <td><a href="/market/admin/abonnement-detail.php?id=<?= (int) $sub['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col">
        <div class="card" style="margin-bottom: var(--gap);">
            <div class="admin-toolbar">
                <h2>Dernières commandes</h2>
            </div>
            <?php if (!$recentOrders): ?>
                <p class="empty-state">Aucune commande sur le nouveau système pour cette boutique.</p>
            <?php else: ?>
                <div class="admin-activity-list">
                    <?php foreach ($recentOrders as $o): ?>
                        <div class="admin-activity-item">
                            <span class="admin-activity-icon"><?= icon('cart', 15) ?></span>
                            <div>
                                <div class="admin-activity-text"><a href="/market/admin/commande-detail.php?id=<?= (int) $o['id'] ?>" class="link-muted"><?= e($o['customer_name']) ?></a> <span class="tag <?= order_status_tag_class($o['fulfillment_status']) ?>"><?= e(order_status_label($o['fulfillment_status'])) ?></span></div>
                                <div class="admin-activity-time"><?= e(date('d/m/Y H:i', strtotime((string) $o['created_at']))) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="admin-toolbar">
                <h2>Derniers avis</h2>
                <a href="/market/admin/avis.php" class="link-more">Tout voir <?= icon('chevron-right', 14) ?></a>
            </div>
            <?php if (!$recentReviews): ?>
                <p class="empty-state">Aucun avis pour le moment.</p>
            <?php else: ?>
                <div class="admin-activity-list">
                    <?php foreach ($recentReviews as $r): ?>
                        <div class="admin-activity-item">
                            <span class="admin-activity-icon"><?= icon('star-filled', 15) ?></span>
                            <div>
                                <div class="admin-activity-text"><a href="/market/admin/avis-detail.php?id=<?= (int) $r['id'] ?>" class="link-muted"><?= e($r['name']) ?></a> — <?= e($r['product_name']) ?></div>
                                <div class="admin-activity-time"><?= render_stars((float) $r['rating']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
