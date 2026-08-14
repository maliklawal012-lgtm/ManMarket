<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
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
$productId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $stmt = $db->prepare('SELECT image FROM products WHERE id = :id AND shop_id = :shop_id');
    $stmt->execute(['id' => $productId, 'shop_id' => $shopId]);
    $row = $stmt->fetch();

    if ($row) {
        $stmt = $db->prepare('DELETE FROM products WHERE id = :id AND shop_id = :shop_id');
        $stmt->execute(['id' => $productId, 'shop_id' => $shopId]);
        delete_uploaded_image($row['image']);
    }

    header('Location: /market/vendeur/produits.php');
    exit;
}

$stmt = $db->prepare('
    SELECT p.*, c.name AS category_name
    FROM products p
    JOIN categories c ON c.id = p.category_id
    WHERE p.id = :id AND p.shop_id = :shop_id
');
$stmt->execute(['id' => $productId, 'shop_id' => $shopId]);
$product = $stmt->fetch() ?: null;

if (!$product) {
    header('Location: /market/vendeur/produits.php');
    exit;
}

$pageTitle = $product['name'];
require_once __DIR__ . '/../includes/vendor_header.php';

$stmt = $db->prepare("
    SELECT COALESCE(SUM(quantity), 0) AS qty, COALESCE(SUM(subtotal), 0) AS revenue, COUNT(DISTINCT order_id) AS order_count
    FROM order_items WHERE product_id = :id AND payment_status != 'failed'
");
$stmt->execute(['id' => $productId]);
$salesStats = $stmt->fetch();

$stmt = $db->prepare('
    SELECT oi.*, o.created_at AS order_created_at
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE oi.product_id = :id
    ORDER BY o.created_at DESC
    LIMIT 10
');
$stmt->execute(['id' => $productId]);
$recentSales = $stmt->fetchAll();

$stmt = $db->prepare('SELECT AVG(rating) AS avg_rating, COUNT(*) AS review_count FROM reviews WHERE product_id = :id');
$stmt->execute(['id' => $productId]);
$reviewStats = $stmt->fetch();

$stmt = $db->prepare('SELECT * FROM reviews WHERE product_id = :id ORDER BY created_at DESC LIMIT 10');
$stmt->execute(['id' => $productId]);
$recentReviews = $stmt->fetchAll();
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/vendeur/produits.php" class="link-more"><?= icon('chevron-right', 14) ?> Retour à mes produits</a>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2><?= e($product['name']) ?></h2>
        <div class="admin-table-actions">
            <a href="/market/vendeur/produits.php?action=edit&id=<?= $productId ?>" class="btn btn-outline-primary btn-sm">Modifier</a>
            <form method="post" action="/market/vendeur/produit-detail.php?id=<?= $productId ?>" onsubmit="return confirm('Supprimer ce produit ?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="btn btn-outline-primary btn-sm">Supprimer</button>
            </form>
        </div>
    </div>
    <div style="display:flex; gap:16px; align-items:flex-start; flex-wrap:wrap;">
        <div class="product-thumb" style="width:80px; height:80px; flex-shrink:0;"><?= product_thumb_html($product, 40) ?></div>
        <ul class="account-info-list" style="flex:1; min-width:260px;">
            <li><span class="account-info-label"><?= icon('menu', 16) ?> Catégorie</span><span><?= e($product['category_name']) ?></span></li>
            <li><span class="account-info-label"><?= icon('cart', 16) ?> Prix</span><span><?= format_price((int) $product['price']) ?><?php if ($product['original_price']): ?> <span class="price-old"><?= format_price((int) $product['original_price']) ?></span><?php endif; ?></span></li>
            <li><span class="account-info-label"><?= icon('shopping-basket', 16) ?> Stock</span><span><?= (int) $product['stock'] > 0 ? (int) $product['stock'] . ' unité(s)' : '<span class="tag tag-closed">Rupture</span>' ?></span></li>
            <li><span class="account-info-label"><?= icon('zap', 16) ?> Vedette</span><span><?= $product['is_featured'] ? 'Oui' : 'Non' ?></span></li>
            <li><span class="account-info-label"><?= icon('clock', 16) ?> Ajouté le</span><span><?= e(date('d/m/Y', strtotime((string) $product['created_at']))) ?></span></li>
        </ul>
    </div>
    <?php if ($product['description']): ?>
        <p style="margin-top:12px; white-space:pre-line;"><?= nl2br(e($product['description'])) ?></p>
    <?php endif; ?>
</div>

<div class="admin-stats-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: var(--gap);">
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#e8f8ee; color:#16a34a;"><?= icon('cart', 18) ?></span>
        <span class="admin-stat-value"><?= (int) $salesStats['qty'] ?></span>
        <span class="admin-stat-label">Unités vendues</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#eef2ff; color:#4f46e5;"><?= icon('bar-chart', 18) ?></span>
        <span class="admin-stat-value"><?= format_price((int) round((float) $salesStats['revenue'])) ?></span>
        <span class="admin-stat-label">Chiffre d'affaires généré</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fef3c7; color:#92400e;"><?= icon('menu', 18) ?></span>
        <span class="admin-stat-value"><?= (int) $salesStats['order_count'] ?></span>
        <span class="admin-stat-label">Commande(s) distincte(s)</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fdf2f8; color:#db2777;"><?= icon('star-filled', 18) ?></span>
        <span class="admin-stat-value"><?= $reviewStats['review_count'] > 0 ? number_format((float) $reviewStats['avg_rating'], 1) . '/5' : '—' ?></span>
        <span class="admin-stat-label"><?= (int) $reviewStats['review_count'] ?> avis</span>
    </div>
</div>

<div class="admin-dashboard-grid">
    <div class="card">
        <div class="admin-toolbar">
            <h2>Ventes récentes (<?= count($recentSales) ?>)</h2>
        </div>
        <?php if (!$recentSales): ?>
            <p class="empty-state">Aucune vente pour ce produit.</p>
        <?php else: ?>
            <div class="admin-activity-list">
                <?php foreach ($recentSales as $s): ?>
                    <div class="admin-activity-item">
                        <span class="admin-activity-icon"><?= icon('cart', 15) ?></span>
                        <div>
                            <div class="admin-activity-text"><a href="/market/vendeur/commande-detail.php?id=<?= (int) $s['order_id'] ?>" class="link-muted">Commande #<?= (int) $s['order_id'] ?></a> — <?= (int) $s['quantity'] ?> x <?= format_price((int) round((float) $s['unit_price'])) ?></div>
                            <div class="admin-activity-time"><span class="tag <?= vendor_item_status_tag_class($s['fulfillment_status']) ?>"><?= e(vendor_item_status_label($s['fulfillment_status'])) ?></span> · <?= e(date('d/m/Y', strtotime((string) $s['order_created_at']))) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="admin-toolbar">
            <h2>Avis récents (<?= count($recentReviews) ?>)</h2>
        </div>
        <?php if (!$recentReviews): ?>
            <p class="empty-state">Aucun avis pour le moment.</p>
        <?php else: ?>
            <div class="admin-activity-list">
                <?php foreach ($recentReviews as $r): ?>
                    <div class="admin-activity-item">
                        <span class="admin-activity-icon"><?= icon('star-filled', 15) ?></span>
                        <div>
                            <div class="admin-activity-text"><a href="/market/vendeur/avis-detail.php?id=<?= (int) $r['id'] ?>" class="link-muted"><?= e($r['name']) ?></a> <?= render_stars((float) $r['rating']) ?></div>
                            <div class="admin-activity-time"><?= $r['comment'] ? e(mb_strimwidth($r['comment'], 0, 80, '…')) . ' · ' : '' ?><?= e(date('d/m/Y', strtotime((string) $r['created_at']))) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
