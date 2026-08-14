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

$pageTitle = 'Promotions';
require_once __DIR__ . '/../includes/vendor_header.php';

$db = get_db();
$shopId = (int) $vendorShop['id'];

$stmt = $db->prepare('SELECT * FROM products WHERE shop_id = :shop_id ORDER BY name');
$stmt->execute(['shop_id' => $shopId]);
$products = $stmt->fetchAll();

$promotionIdsByName = array_column(get_active_promotions(), 'id', 'name');

$discountedProducts = [];
foreach ($products as $p) {
    $priceInfo = get_product_price($p);
    if ($priceInfo['discount_percent'] > 0 && $priceInfo['promotion_name']) {
        $priceInfo['promotion_id'] = $promotionIdsByName[$priceInfo['promotion_name']] ?? null;
        $discountedProducts[] = array_merge($p, $priceInfo);
    }
}
?>

<div class="card">
    <div class="admin-toolbar">
        <h2>Promotions actives sur vos produits (<?= count($discountedProducts) ?>)</h2>
    </div>

    <p class="char-count">Les promotions sont créées et gérées par l'équipe ManMarket (remise générale ou par catégorie). Cette page vous montre lesquelles s'appliquent actuellement à vos produits.</p>

    <?php if (!$discountedProducts): ?>
        <p class="empty-state">Aucune promotion en cours ne s'applique à vos produits pour le moment.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th></th>
                        <th>Produit</th>
                        <th>Promotion</th>
                        <th>Remise</th>
                        <th>Prix affiché</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($discountedProducts as $p): ?>
                        <tr>
                            <td><div class="product-thumb admin-table-thumb"><?= product_thumb_html($p, 20) ?></div></td>
                            <td><?= e($p['name']) ?></td>
                            <td><?= e($p['promotion_name']) ?></td>
                            <td><span class="tag tag-green">-<?= (int) $p['discount_percent'] ?>%</span></td>
                            <td>
                                <?= format_price((int) $p['price']) ?>
                                <?php if ($p['original_price']): ?><br><span class="price-old"><?= format_price((int) $p['original_price']) ?></span><?php endif; ?>
                            </td>
                            <td><?php if ($p['promotion_id']): ?><a href="/market/vendeur/promotion-detail.php?id=<?= (int) $p['promotion_id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
