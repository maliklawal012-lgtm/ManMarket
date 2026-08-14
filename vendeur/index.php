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
$db = get_db();

if ($vendorShop && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_open'])) {
    $stmt = $db->prepare('UPDATE shops SET is_open = IF(is_open = 1, 0, 1) WHERE id = :id');
    $stmt->execute(['id' => (int) $vendorShop['id']]);
    header('Location: /market/vendeur/index.php');
    exit;
}

$pageTitle = 'Tableau de bord';
require_once __DIR__ . '/../includes/vendor_header.php';

$shopId = (int) $vendorShop['id'];

$stmt = $db->prepare('SELECT COUNT(*) FROM products WHERE shop_id = :id');
$stmt->execute(['id' => $shopId]);
$productCount = (int) $stmt->fetchColumn();

$stmt = $db->prepare('
    SELECT COUNT(r.id) AS review_count, COALESCE(AVG(r.rating), 0) AS avg_rating
    FROM reviews r
    JOIN products p ON p.id = r.product_id
    WHERE p.shop_id = :id
');
$stmt->execute(['id' => $shopId]);
$reviewStats = $stmt->fetch();
$reviewCount = (int) $reviewStats['review_count'];
$avgRating = (float) $reviewStats['avg_rating'];

$stmt = $db->prepare('
    SELECT r.*, p.name AS product_name, p.slug AS product_slug
    FROM reviews r
    JOIN products p ON p.id = r.product_id
    WHERE p.shop_id = :id
    ORDER BY r.created_at DESC
    LIMIT 5
');
$stmt->execute(['id' => $shopId]);
$recentReviews = $stmt->fetchAll();

$stmt = $db->prepare('SELECT * FROM products WHERE shop_id = :id ORDER BY created_at DESC LIMIT 5');
$stmt->execute(['id' => $shopId]);
$recentProducts = $stmt->fetchAll();

$vendorEntity = wallet_vendor_repo()->findByUserId((int) $vendorUser['id']);
$availableBalance = 0;
if ($vendorEntity) {
    $vendorId = (int) $vendorEntity['id'];
    $wallet = wallet_service()->getOrCreateWallet($vendorId);
    $openReserved = wallet_withdrawal_repo()->openReservedAmountForVendor($vendorId);
    $availableBalance = max(0, (int) round((float) $wallet['available_balance']) - $openReserved);
}

$stmt = $db->prepare('SELECT id, name, slug, stock FROM products WHERE shop_id = :id AND stock <= 5 ORDER BY stock ASC, name ASC');
$stmt->execute(['id' => $shopId]);
$lowStockProducts = $stmt->fetchAll();
?>

<div class="admin-stats-grid">
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#e8f8ee; color:#16a34a;"><?= icon('cart', 18) ?></span>
        <span class="admin-stat-value"><?= format_price($availableBalance) ?></span>
        <span class="admin-stat-label">Solde disponible</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fff7ed; color:#d97706;"><?= icon('shopping-basket', 18) ?></span>
        <span class="admin-stat-value"><?= $productCount ?></span>
        <span class="admin-stat-label">Produit(s) en ligne</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fdf2f8; color:#db2777;"><?= icon('star-filled', 18) ?></span>
        <span class="admin-stat-value"><?= $reviewCount > 0 ? number_format($avgRating, 1) : '—' ?></span>
        <span class="admin-stat-label"><?= $reviewCount > 0 ? $reviewCount . ' avis reçu' . ($reviewCount > 1 ? 's' : '') : "Pas encore d'avis" ?></span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-card-header">
            <span class="admin-stat-icon" style="background:#e8f8ee; color:#16a34a;"><?= icon('store', 18) ?></span>
            <span class="tag <?= $vendorShop['is_open'] ? 'tag-open' : 'tag-closed' ?>"><?= $vendorShop['is_open'] ? 'Ouverte' : 'Fermée' ?></span>
        </span>
        <span class="admin-stat-label">Statut de la boutique</span>
        <form method="post" action="/market/vendeur/index.php" style="margin-top:4px;">
            <?= csrf_field() ?>
            <input type="hidden" name="toggle_open" value="1">
            <button type="submit" class="btn btn-outline-primary btn-sm"><?= $vendorShop['is_open'] ? 'Fermer la boutique' : 'Ouvrir la boutique' ?></button>
        </form>
    </div>
</div>

<div class="card">
    <div class="admin-toolbar">
        <h2>Actions rapides</h2>
    </div>
    <div class="admin-table-actions" style="flex-wrap:wrap; gap:10px;">
        <a href="/market/vendeur/produits.php?action=new" class="btn btn-primary btn-sm"><?= icon('plus', 14) ?> Ajouter un produit</a>
        <a href="/market/vendeur/produits.php" class="btn btn-outline-primary btn-sm"><?= icon('shopping-basket', 14) ?> Gérer mes produits</a>
        <a href="/market/vendeur/commandes.php" class="btn btn-outline-primary btn-sm"><?= icon('cart', 14) ?> Voir mes commandes</a>
        <a href="/market/vendeur/statistiques.php" class="btn btn-outline-primary btn-sm"><?= icon('bar-chart', 14) ?> Voir mes statistiques</a>
        <a href="/market/vendeur/retraits.php" class="btn btn-outline-primary btn-sm"><?= icon('shield', 14) ?> Demander un retrait</a>
        <a href="/market/boutique.php?slug=<?= urlencode((string) $vendorShop['slug']) ?>" class="btn btn-outline-primary btn-sm"><?= icon('chevron-right', 14) ?> Voir ma boutique</a>
    </div>
</div>

<?php if ($lowStockProducts): ?>
    <div class="card" style="margin-bottom: var(--gap);">
        <div class="admin-toolbar">
            <h2><?= icon('shopping-basket', 18) ?> Stock faible (<?= count($lowStockProducts) ?>)</h2>
            <a href="/market/vendeur/produits.php" class="link-more"><?= icon('chevron-right', 14) ?> Gérer mes produits</a>
        </div>
        <div class="admin-activity-list">
            <?php foreach ($lowStockProducts as $p): ?>
                <div class="admin-activity-item">
                    <span class="admin-activity-icon" style="background:<?= (int) $p['stock'] === 0 ? '#fee2e2' : '#fef3c7' ?>; color:<?= (int) $p['stock'] === 0 ? '#b91c1c' : '#92400e' ?>;"><?= icon('shopping-basket', 15) ?></span>
                    <div>
                        <div class="admin-activity-text"><?= e($p['name']) ?></div>
                        <div class="admin-activity-time"><?= (int) $p['stock'] === 0 ? 'Rupture de stock' : (int) $p['stock'] . ' unité(s) restante(s)' ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="admin-dashboard-grid">
    <div class="card">
        <div class="admin-toolbar">
            <h2>Derniers produits ajoutés</h2>
            <a href="/market/vendeur/produits.php" class="link-more"><?= icon('chevron-right', 14) ?> Tout voir</a>
        </div>
        <?php if (!$recentProducts): ?>
            <p class="empty-state">Vous n'avez encore ajouté aucun produit. <a href="/market/vendeur/produits.php?action=new">Ajouter mon premier produit</a></p>
        <?php else: ?>
            <div class="admin-activity-list">
                <?php foreach ($recentProducts as $p): ?>
                    <div class="admin-activity-item">
                        <div class="product-thumb admin-table-thumb"><?= product_thumb_html($p, 24) ?></div>
                        <div>
                            <div class="admin-activity-text"><?= e($p['name']) ?></div>
                            <div class="admin-activity-time"><?= format_price((int) $p['price']) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="admin-toolbar">
            <h2>Avis récents</h2>
            <a href="/market/vendeur/avis.php" class="link-more"><?= icon('chevron-right', 14) ?> Tout voir</a>
        </div>
        <?php if (!$recentReviews): ?>
            <p class="empty-state">Aucun avis pour le moment.</p>
        <?php else: ?>
            <div class="admin-activity-list">
                <?php foreach ($recentReviews as $r): ?>
                    <div class="admin-activity-item">
                        <span class="admin-activity-icon"><?= icon('star-filled', 15) ?></span>
                        <div>
                            <div class="admin-activity-text"><?= e($r['name']) ?> — <?= e($r['product_name']) ?></div>
                            <div class="admin-activity-time"><?= render_stars((float) $r['rating']) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
