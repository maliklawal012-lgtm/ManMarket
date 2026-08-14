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
$promotionId = (int) ($_GET['id'] ?? 0);

$stmt = $db->prepare('
    SELECT p.*, c.name AS category_name
    FROM promotions p
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE p.id = :id
');
$stmt->execute(['id' => $promotionId]);
$promotion = $stmt->fetch() ?: null;

if (!$promotion) {
    header('Location: /market/vendeur/promotions.php');
    exit;
}

$pageTitle = $promotion['name'];
require_once __DIR__ . '/../includes/vendor_header.php';

$today = date('Y-m-d');
$isLive = (bool) $promotion['is_active'] && $today >= $promotion['starts_at'] && $today <= $promotion['ends_at'];
$isFuture = $today < $promotion['starts_at'];

if (!$promotion['is_active']) {
    $statusLabel = 'Désactivée';
    $statusClass = 'tag-closed';
} elseif ($isLive) {
    $statusLabel = 'Active maintenant';
    $statusClass = 'tag-green';
} elseif ($isFuture) {
    $statusLabel = 'À venir';
    $statusClass = 'tag-pending';
} else {
    $statusLabel = 'Expirée';
    $statusClass = 'tag-closed';
}

if ($promotion['scope'] === 'category') {
    $stmt = $db->prepare('SELECT * FROM products WHERE shop_id = :shop AND category_id = :cat ORDER BY name');
    $stmt->execute(['shop' => $shopId, 'cat' => (int) $promotion['category_id']]);
} else {
    $stmt = $db->prepare('SELECT * FROM products WHERE shop_id = :shop ORDER BY name');
    $stmt->execute(['shop' => $shopId]);
}
$yourProducts = $stmt->fetchAll();
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/vendeur/promotions.php" class="link-more"><?= icon('chevron-right', 14) ?> Retour aux promotions</a>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2><?= e($promotion['name']) ?></h2>
        <span class="tag <?= $statusClass ?>"><?= e($statusLabel) ?></span>
    </div>
    <ul class="account-info-list">
        <li><span class="account-info-label"><?= icon('zap', 16) ?> Remise</span><span>-<?= (int) $promotion['discount_percent'] ?>%</span></li>
        <li><span class="account-info-label"><?= icon('menu', 16) ?> Portée</span><span>
            <?php if ($promotion['scope'] === 'category'): ?>
                <a href="/market/vendeur/categorie-detail.php?id=<?= (int) $promotion['category_id'] ?>" class="link-muted"><?= e($promotion['category_name']) ?></a>
            <?php else: ?>
                Tout le site
            <?php endif; ?>
        </span></li>
        <li><span class="account-info-label"><?= icon('clock', 16) ?> Période</span><span><?= e(date('d/m/Y', strtotime((string) $promotion['starts_at']))) ?> → <?= e(date('d/m/Y', strtotime((string) $promotion['ends_at']))) ?></span></li>
    </ul>
    <p class="char-count" style="margin-top:8px;">Cette promotion est créée et gérée par l'équipe ManMarket. Si un autre programme offre une remise supérieure sur les mêmes produits, c'est celui-ci qui s'applique — les remises ne se cumulent pas.</p>
</div>

<div class="card">
    <div class="admin-toolbar">
        <h2>Vos produits concernés (<?= count($yourProducts) ?>)</h2>
    </div>
    <?php if (!$yourProducts): ?>
        <p class="empty-state">Aucun de vos produits n'est dans la portée de cette promotion.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th></th>
                        <th>Produit</th>
                        <th>Prix normal</th>
                        <th>Prix avec remise appliquée</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($yourProducts as $prod):
                        $pricing = get_product_price($prod);
                        $thisPromoWins = $pricing['discount_percent'] > 0 && $pricing['promotion_name'] === $promotion['name'];
                    ?>
                        <tr>
                            <td><div class="product-thumb admin-table-thumb"><?= product_thumb_html($prod, 20) ?></div></td>
                            <td><?= e($prod['name']) ?></td>
                            <td><?= format_price((int) $prod['price']) ?></td>
                            <td>
                                <?php if ($thisPromoWins): ?>
                                    <?= format_price($pricing['price']) ?> <span class="tag tag-green">Cette promo s'applique</span>
                                <?php elseif ($pricing['discount_percent'] > 0): ?>
                                    <?= format_price($pricing['price']) ?> <span class="char-count">(une autre promo prévaut)</span>
                                <?php else: ?>
                                    <span class="char-count">—</span>
                                <?php endif; ?>
                            </td>
                            <td><a href="/market/vendeur/produit-detail.php?id=<?= (int) $prod['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
