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

$pageTitle = 'Ma boutique';
require_once __DIR__ . '/../includes/vendor_header.php';

$db = get_db();
$shopId = (int) $vendorShop['id'];

$activeSubscription = get_shop_active_subscription($shopId);

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
    SELECT COALESCE(SUM(oi.unit_price * oi.quantity), 0) AS revenue, COUNT(DISTINCT oi.order_id) AS order_count
    FROM order_items oi JOIN orders o ON o.id = oi.order_id
    WHERE oi.shop_id = :id AND o.fulfillment_status = 'delivered'
");
$stmt->execute(['id' => $shopId]);
$revenueStats = $stmt->fetch();

$stmt = $db->prepare('SELECT id, name, slug, stock FROM products WHERE shop_id = :id ORDER BY stock ASC, name ASC LIMIT 10');
$stmt->execute(['id' => $shopId]);
$products = $stmt->fetchAll();

$stmt = $db->prepare('
    SELECT r.*, p.name AS product_name
    FROM reviews r JOIN products p ON p.id = r.product_id
    WHERE p.shop_id = :id
    ORDER BY r.created_at DESC LIMIT 5
');
$stmt->execute(['id' => $shopId]);
$recentReviews = $stmt->fetchAll();
?>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>
            <span class="shop-logo" style="background:<?= e($vendorShop['color']) ?>; width:32px; height:32px; font-size:.72rem; display:inline-flex; vertical-align:middle; margin-right:8px;"><?= shop_logo_html($vendorShop) ?></span>
            <?= e($vendorShop['name']) ?>
        </h2>
        <div class="admin-table-actions">
            <span class="tag <?= $vendorShop['is_open'] ? 'tag-open' : 'tag-closed' ?>"><?= $vendorShop['is_open'] ? 'Ouverte' : 'Fermée' ?></span>
            <span class="tag <?= $activeSubscription ? 'tag-open' : 'tag-closed' ?>"><?= $activeSubscription ? 'Abonnement actif' : 'Sans abonnement (invisible sur le site)' ?></span>
            <a href="/market/boutique?slug=<?= urlencode((string) $vendorShop['slug']) ?>" class="btn btn-outline-primary btn-sm" target="_blank"><?= icon('chevron-right', 14) ?> Voir ma page publique</a>
            <a href="/market/vendeur/parametres" class="btn btn-outline-primary btn-sm">Modifier</a>
        </div>
    </div>
    <p class="char-count">
        <?= e($vendorShop['neighborhood']) ?><?php if ($vendorShop['phone']): ?> · <?= e($vendorShop['phone']) ?><?php endif; ?><?php if ($vendorShop['whatsapp']): ?> · WhatsApp <?= e($vendorShop['whatsapp']) ?><?php endif; ?>
        <?php if ($activeSubscription): ?>
            <br>Abonnement <strong><?= e($activeSubscription['plan_name']) ?></strong> actif jusqu'au <?= e(date('d/m/Y', strtotime((string) $activeSubscription['ends_at']))) ?>.
            <a href="/market/vendeur/abonnements" class="link-muted">Gérer <?= icon('chevron-right', 12) ?></a>
        <?php else: ?>
            <br><span style="color:#dc2626;">Votre boutique n'apparaît plus sur le site public tant qu'aucun abonnement n'est actif.</span>
            <a href="/market/vendeur/abonnements" class="link-muted">Renouveler <?= icon('chevron-right', 12) ?></a>
        <?php endif; ?>
    </p>
</div>

<div class="admin-stats-grid">
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#e8f8ee; color:#16a34a;"><?= icon('cart', 18) ?></span>
        <span class="admin-stat-value"><?= format_price((int) $revenueStats['revenue']) ?></span>
        <span class="admin-stat-label">Chiffre d'affaires (livré)</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#eef2ff; color:#4f46e5;"><?= icon('send', 18) ?></span>
        <span class="admin-stat-value"><?= (int) $revenueStats['order_count'] ?></span>
        <span class="admin-stat-label">Commande(s) livrée(s)</span>
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
    <div class="card">
        <div class="admin-toolbar">
            <h2>Produits</h2>
            <a href="/market/vendeur/produits" class="link-more">Gérer mes produits <?= icon('chevron-right', 14) ?></a>
        </div>
        <?php if (!$products): ?>
            <p class="empty-state">Vous n'avez encore ajouté aucun produit. <a href="/market/vendeur/produits?action=new">Ajouter mon premier produit</a></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead><tr><th>Produit</th><th>Stock</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($products as $p): ?>
                            <tr>
                                <td><?= e($p['name']) ?></td>
                                <td><?= (int) $p['stock'] === 0 ? '<span class="tag tag-closed">Rupture</span>' : (int) $p['stock'] ?></td>
                                <td><a href="/market/vendeur/produit-detail?id=<?= (int) $p['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($productCount > 10): ?><p class="char-count">+ <?= $productCount - 10 ?> autre(s) produit(s).</p><?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="admin-toolbar">
            <h2>Derniers avis</h2>
            <a href="/market/vendeur/avis" class="link-more">Tout voir <?= icon('chevron-right', 14) ?></a>
        </div>
        <?php if (!$recentReviews): ?>
            <p class="empty-state">Aucun avis pour le moment.</p>
        <?php else: ?>
            <div class="admin-activity-list">
                <?php foreach ($recentReviews as $r): ?>
                    <div class="admin-activity-item">
                        <span class="admin-activity-icon"><?= icon('star-filled', 15) ?></span>
                        <div>
                            <div class="admin-activity-text"><a href="/market/vendeur/avis-detail?id=<?= (int) $r['id'] ?>" class="link-muted"><?= e($r['name']) ?></a> — <?= e($r['product_name']) ?></div>
                            <div class="admin-activity-time"><?= render_stars((float) $r['rating']) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
