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
$promotionId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $db->prepare('DELETE FROM promotions WHERE id = :id')->execute(['id' => $promotionId]);
    header('Location: /market/admin/promotions.php');
    exit;
}

$stmt = $db->prepare('
    SELECT p.*, c.name AS category_name, c.icon AS category_icon, c.color AS category_color
    FROM promotions p
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE p.id = :id
');
$stmt->execute(['id' => $promotionId]);
$promotion = $stmt->fetch() ?: null;

if (!$promotion) {
    header('Location: /market/admin/promotions.php');
    exit;
}

$pageTitle = $promotion['name'];
require_once __DIR__ . '/../includes/admin_header.php';

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
    $stmt = $db->prepare('SELECT * FROM products WHERE category_id = :cat ORDER BY name');
    $stmt->execute(['cat' => (int) $promotion['category_id']]);
    $affectedProducts = $stmt->fetchAll();
} else {
    $stmt = $db->query('SELECT * FROM products ORDER BY name LIMIT 20');
    $affectedProducts = $stmt->fetchAll();
    $totalProductCount = (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn();
}
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/admin/promotions.php" class="link-more"><?= icon('chevron-right', 14) ?> Retour aux promotions</a>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2><?= e($promotion['name']) ?></h2>
        <div class="admin-table-actions">
            <span class="tag <?= $statusClass ?>"><?= e($statusLabel) ?></span>
            <a href="/market/admin/promotions.php?action=edit&id=<?= $promotionId ?>" class="btn btn-outline-primary btn-sm">Modifier</a>
            <form method="post" action="/market/admin/promotion-detail.php?id=<?= $promotionId ?>" onsubmit="return confirm('Supprimer cette promotion ?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="btn btn-outline-primary btn-sm">Supprimer</button>
            </form>
        </div>
    </div>
    <ul class="account-info-list">
        <li><span class="account-info-label"><?= icon('zap', 16) ?> Remise</span><span>-<?= (int) $promotion['discount_percent'] ?>%</span></li>
        <li><span class="account-info-label"><?= icon('menu', 16) ?> Portée</span><span>
            <?php if ($promotion['scope'] === 'category'): ?>
                <a href="/market/admin/categorie-detail.php?id=<?= (int) $promotion['category_id'] ?>" class="link-muted"><?= e($promotion['category_name']) ?></a>
            <?php else: ?>
                Tout le site
            <?php endif; ?>
        </span></li>
        <li><span class="account-info-label"><?= icon('clock', 16) ?> Période</span><span><?= e(date('d/m/Y', strtotime((string) $promotion['starts_at']))) ?> → <?= e(date('d/m/Y', strtotime((string) $promotion['ends_at']))) ?></span></li>
        <li><span class="account-info-label"><?= icon('clock', 16) ?> Créée le</span><span><?= e(date('d/m/Y à H:i', strtotime((string) $promotion['created_at']))) ?></span></li>
    </ul>
    <p class="char-count" style="margin-top:8px;">
        La remise affichée sur le site correspond toujours à la meilleure promotion active pour un produit (celle-ci ou une autre portant un pourcentage plus élevé sur la même portée) — elles ne se cumulent pas. Le prix utilisé au moment du paiement reste le prix de base du produit : cette remise est purement d'affichage.
    </p>
</div>

<div class="card">
    <div class="admin-toolbar">
        <h2>Produits concernés <?= $promotion['scope'] === 'category' ? '(' . count($affectedProducts) . ')' : '' ?></h2>
        <a href="/market/admin/produits.php" class="link-more">Gérer les produits <?= icon('chevron-right', 14) ?></a>
    </div>
    <?php if ($promotion['scope'] === 'all'): ?>
        <p class="char-count" style="margin-bottom:12px;">Portée « Tout le site » — <?= $totalProductCount ?> produit(s) au total. Aperçu des 20 premiers :</p>
    <?php endif; ?>
    <?php if (!$affectedProducts): ?>
        <p class="empty-state">Aucun produit concerné pour le moment.</p>
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
                    <?php foreach ($affectedProducts as $prod):
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
                            <td><a href="/market/admin/produit-detail.php?id=<?= (int) $prod['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
