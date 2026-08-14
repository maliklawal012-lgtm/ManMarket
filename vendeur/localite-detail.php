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
$locationId = (int) ($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM locations WHERE id = :id');
$stmt->execute(['id' => $locationId]);
$location = $stmt->fetch() ?: null;

if (!$location) {
    header('Location: /market/vendeur/commandes.php');
    exit;
}

$isCity = $location['parent_id'] === null;
$pageTitle = $location['name'];
require_once __DIR__ . '/../includes/vendor_header.php';

$parentCity = null;
if (!$isCity) {
    $stmt = $db->prepare('SELECT * FROM locations WHERE id = :id');
    $stmt->execute(['id' => (int) $location['parent_id']]);
    $parentCity = $stmt->fetch() ?: null;
}

$neighborhoods = [];
if ($isCity) {
    $stmt = $db->prepare('SELECT * FROM locations WHERE parent_id = :id ORDER BY sort_order, name');
    $stmt->execute(['id' => $locationId]);
    $neighborhoods = $stmt->fetchAll();
}

// Correspondance texte : delivery_location vaut "Ville" ou "Ville - Quartier" (texte libre saisi a la commande).
if ($isCity) {
    $matchExact = $location['name'];
    $matchPrefix = $location['name'] . ' - %';
} else {
    $matchExact = $parentCity['name'] . ' - ' . $location['name'];
    $matchPrefix = null;
}

$whereClause = $isCity
    ? '(o.delivery_location = :exact OR o.delivery_location LIKE :prefix)'
    : 'o.delivery_location = :exact';
$params = $isCity ? ['exact' => $matchExact, 'prefix' => $matchPrefix] : ['exact' => $matchExact];
$params['shop_id'] = $shopId;

$stmt = $db->prepare("
    SELECT COUNT(DISTINCT o.id) AS cnt, COALESCE(SUM(oi.subtotal), 0) AS revenue
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id AND oi.shop_id = :shop_id
    WHERE $whereClause
");
$stmt->execute($params);
$orderStats = $stmt->fetch();

$stmt = $db->prepare("
    SELECT DISTINCT o.*
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id AND oi.shop_id = :shop_id
    WHERE $whereClause
    ORDER BY o.created_at DESC LIMIT 10
");
$stmt->execute($params);
$recentOrders = $stmt->fetchAll();
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/vendeur/commandes.php" class="link-more"><?= icon('chevron-right', 14) ?> Retour aux commandes</a>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2><?= e($location['name']) ?></h2>
    </div>
    <p class="char-count">
        <?php if ($isCity): ?>
            Ville — <?= count($neighborhoods) ?> quartier(s) enregistré(s).
        <?php else: ?>
            Quartier de <a href="/market/vendeur/localite-detail.php?id=<?= (int) $parentCity['id'] ?>" class="link-muted"><?= e($parentCity['name']) ?></a>.
        <?php endif; ?>
    </p>
</div>

<div class="admin-stats-grid" style="grid-template-columns: repeat(2, 1fr); margin-bottom: var(--gap);">
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#eef2ff; color:#4f46e5;"><?= icon('cart', 18) ?></span>
        <span class="admin-stat-value"><?= (int) $orderStats['cnt'] ?></span>
        <span class="admin-stat-label">Vos commande(s) livrée(s) ici</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#e8f8ee; color:#16a34a;"><?= icon('bar-chart', 18) ?></span>
        <span class="admin-stat-value"><?= format_price((int) round((float) $orderStats['revenue'])) ?></span>
        <span class="admin-stat-label">Votre chiffre d'affaires généré ici</span>
    </div>
</div>

<?php if ($isCity && $neighborhoods): ?>
    <div class="card" style="margin-bottom: var(--gap);">
        <div class="admin-toolbar">
            <h2>Quartiers (<?= count($neighborhoods) ?>)</h2>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead><tr><th>Quartier</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($neighborhoods as $n): ?>
                        <tr>
                            <td><?= e($n['name']) ?></td>
                            <td><a href="/market/vendeur/localite-detail.php?id=<?= (int) $n['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="admin-toolbar">
        <h2>Vos commandes récentes ici (<?= count($recentOrders) ?>)</h2>
    </div>
    <?php if (!$recentOrders): ?>
        <p class="empty-state">Aucune de vos commandes n'a encore été livrée à cette localité.</p>
    <?php else: ?>
        <div class="admin-activity-list">
            <?php foreach ($recentOrders as $o): ?>
                <div class="admin-activity-item">
                    <span class="admin-activity-icon"><?= icon('cart', 15) ?></span>
                    <div>
                        <div class="admin-activity-text"><a href="/market/vendeur/commande-detail.php?id=<?= (int) $o['id'] ?>" class="link-muted"><?= e($o['customer_name']) ?></a> <span class="tag <?= order_status_tag_class($o['fulfillment_status']) ?>"><?= e(order_status_label($o['fulfillment_status'])) ?></span></div>
                        <div class="admin-activity-time"><?= e(date('d/m/Y H:i', strtotime((string) $o['created_at']))) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
