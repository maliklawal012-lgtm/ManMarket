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
$categoryId = (int) ($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM categories WHERE id = :id');
$stmt->execute(['id' => $categoryId]);
$category = $stmt->fetch() ?: null;

if (!$category) {
    header('Location: /market/vendeur/produits.php');
    exit;
}

$pageTitle = $category['name'];
require_once __DIR__ . '/../includes/vendor_header.php';

$stmt = $db->prepare('SELECT COUNT(*) FROM products WHERE category_id = :cat AND shop_id = :shop');
$stmt->execute(['cat' => $categoryId, 'shop' => $shopId]);
$productCount = (int) $stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT COALESCE(SUM(oi.quantity), 0) AS qty, COALESCE(SUM(oi.subtotal), 0) AS revenue
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    WHERE p.category_id = :cat AND p.shop_id = :shop AND oi.payment_status != 'failed'
");
$stmt->execute(['cat' => $categoryId, 'shop' => $shopId]);
$salesStats = $stmt->fetch();

$stmt = $db->prepare('
    SELECT COUNT(r.id) AS review_count, COALESCE(AVG(r.rating), 0) AS avg_rating
    FROM reviews r JOIN products p ON p.id = r.product_id
    WHERE p.category_id = :cat AND p.shop_id = :shop
');
$stmt->execute(['cat' => $categoryId, 'shop' => $shopId]);
$reviewStats = $stmt->fetch();

$stmt = $db->prepare('
    SELECT * FROM products
    WHERE category_id = :cat AND shop_id = :shop
    ORDER BY name
');
$stmt->execute(['cat' => $categoryId, 'shop' => $shopId]);
$products = $stmt->fetchAll();
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/vendeur/produits.php" class="link-more"><?= icon('chevron-right', 14) ?> Retour à mes produits</a>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>
            <span class="admin-stat-icon" style="width:28px; height:28px; display:inline-flex; vertical-align:middle; margin-right:8px; background:<?= e($category['color']) ?>1a; color:<?= e($category['color']) ?>;"><?= icon($category['icon'], 15) ?></span>
            <?= e($category['name']) ?>
        </h2>
        <a href="/market/vendeur/produits.php?action=new" class="btn btn-primary btn-sm"><?= icon('plus', 14) ?> Ajouter un produit</a>
    </div>
    <p class="char-count">Vos produits dans cette catégorie, sur votre boutique.</p>
</div>

<div class="admin-stats-grid" style="grid-template-columns: repeat(3, 1fr); margin-bottom: var(--gap);">
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fff7ed; color:#d97706;"><?= icon('shopping-basket', 18) ?></span>
        <span class="admin-stat-value"><?= $productCount ?></span>
        <span class="admin-stat-label">Vos produit(s) dans cette catégorie</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#e8f8ee; color:#16a34a;"><?= icon('cart', 18) ?></span>
        <span class="admin-stat-value"><?= format_price((int) round((float) $salesStats['revenue'])) ?></span>
        <span class="admin-stat-label">Chiffre d'affaires généré</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fdf2f8; color:#db2777;"><?= icon('star-filled', 18) ?></span>
        <span class="admin-stat-value"><?= $reviewStats['review_count'] > 0 ? number_format((float) $reviewStats['avg_rating'], 1) . '/5' : '—' ?></span>
        <span class="admin-stat-label"><?= (int) $reviewStats['review_count'] ?> avis</span>
    </div>
</div>

<div class="card">
    <div class="admin-toolbar">
        <h2>Vos produits (<?= count($products) ?>)</h2>
    </div>
    <?php if (!$products): ?>
        <p class="empty-state">Vous n'avez aucun produit dans cette catégorie. <a href="/market/vendeur/produits.php?action=new">Ajouter un produit</a></p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th></th>
                        <th>Produit</th>
                        <th>Prix</th>
                        <th>Stock</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                        <tr>
                            <td><div class="product-thumb admin-table-thumb"><?= product_thumb_html($p, 20) ?></div></td>
                            <td><?= e($p['name']) ?></td>
                            <td><?= format_price((int) $p['price']) ?></td>
                            <td><?= (int) $p['stock'] === 0 ? '<span class="tag tag-closed">Rupture</span>' : (int) $p['stock'] ?></td>
                            <td><a href="/market/vendeur/produit-detail.php?id=<?= (int) $p['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
