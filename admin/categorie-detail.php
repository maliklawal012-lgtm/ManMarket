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
$categoryId = (int) ($_GET['id'] ?? 0);
$deleteError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    try {
        $stmt = $db->prepare('DELETE FROM categories WHERE id = :id');
        $stmt->execute(['id' => $categoryId]);
        header('Location: /market/admin/categories');
        exit;
    } catch (PDOException $e) {
        $deleteError = 'Impossible de supprimer cette catégorie : des produits y sont encore associés.';
    }
}

$stmt = $db->prepare('SELECT * FROM categories WHERE id = :id');
$stmt->execute(['id' => $categoryId]);
$category = $stmt->fetch() ?: null;

if (!$category) {
    header('Location: /market/admin/categories');
    exit;
}

$pageTitle = $category['name'];
require_once __DIR__ . '/../includes/admin_header.php';

$stmt = $db->prepare('SELECT COUNT(*) FROM products WHERE category_id = :id');
$stmt->execute(['id' => $categoryId]);
$productCount = (int) $stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT COALESCE(SUM(oi.quantity), 0) AS qty, COALESCE(SUM(oi.subtotal), 0) AS revenue, COUNT(DISTINCT oi.order_id) AS order_count
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    WHERE p.category_id = :id AND oi.payment_status != 'failed'
");
$stmt->execute(['id' => $categoryId]);
$salesStats = $stmt->fetch();

$stmt = $db->prepare('
    SELECT COUNT(r.id) AS review_count, COALESCE(AVG(r.rating), 0) AS avg_rating
    FROM reviews r JOIN products p ON p.id = r.product_id
    WHERE p.category_id = :id
');
$stmt->execute(['id' => $categoryId]);
$reviewStats = $stmt->fetch();

$stmt = $db->prepare('
    SELECT p.*, s.name AS shop_name
    FROM products p
    JOIN shops s ON s.id = p.shop_id
    WHERE p.category_id = :id
    ORDER BY p.name
');
$stmt->execute(['id' => $categoryId]);
$products = $stmt->fetchAll();
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/admin/categories" class="link-more"><?= icon('chevron-right', 14) ?> Retour aux catégories</a>
</div>

<?php if ($deleteError): ?>
    <div class="alert alert-error"><?= icon('x', 18) ?><span><?= e($deleteError) ?></span></div>
<?php endif; ?>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>
            <span class="admin-stat-icon" style="width:28px; height:28px; display:inline-flex; vertical-align:middle; margin-right:8px; background:<?= e($category['color']) ?>1a; color:<?= e($category['color']) ?>;"><?= icon($category['icon'], 15) ?></span>
            <?= e($category['name']) ?>
        </h2>
        <div class="admin-table-actions">
            <a href="/market/admin/categories?action=edit&id=<?= $categoryId ?>" class="btn btn-outline-primary btn-sm">Modifier</a>
            <form method="post" action="/market/admin/categorie-detail?id=<?= $categoryId ?>" onsubmit="return confirm('Supprimer cette catégorie ?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="btn btn-outline-primary btn-sm">Supprimer</button>
            </form>
        </div>
    </div>
    <p class="char-count">Ordre d'affichage : <?= (int) $category['sort_order'] ?></p>
</div>

<div class="admin-stats-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: var(--gap);">
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fff7ed; color:#d97706;"><?= icon('shopping-basket', 18) ?></span>
        <span class="admin-stat-value"><?= $productCount ?></span>
        <span class="admin-stat-label">Produit(s) dans cette catégorie</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#e8f8ee; color:#16a34a;"><?= icon('cart', 18) ?></span>
        <span class="admin-stat-value"><?= format_price((int) round((float) $salesStats['revenue'])) ?></span>
        <span class="admin-stat-label">Chiffre d'affaires généré</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#eef2ff; color:#4f46e5;"><?= icon('bar-chart', 18) ?></span>
        <span class="admin-stat-value"><?= (int) $salesStats['qty'] ?></span>
        <span class="admin-stat-label">Unités vendues</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fdf2f8; color:#db2777;"><?= icon('star-filled', 18) ?></span>
        <span class="admin-stat-value"><?= $reviewStats['review_count'] > 0 ? number_format((float) $reviewStats['avg_rating'], 1) . '/5' : '—' ?></span>
        <span class="admin-stat-label"><?= (int) $reviewStats['review_count'] ?> avis</span>
    </div>
</div>

<div class="card">
    <div class="admin-toolbar">
        <h2>Produits de cette catégorie (<?= count($products) ?>)</h2>
        <a href="/market/admin/produits" class="link-more">Gérer les produits <?= icon('chevron-right', 14) ?></a>
    </div>
    <?php if (!$products): ?>
        <p class="empty-state">Aucun produit dans cette catégorie pour le moment.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th></th>
                        <th>Produit</th>
                        <th>Boutique</th>
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
                            <td><a href="/market/admin/boutique-detail?id=<?= (int) $p['shop_id'] ?>" class="link-muted"><?= e($p['shop_name']) ?></a></td>
                            <td><?= format_price((int) $p['price']) ?></td>
                            <td><?= (int) $p['stock'] === 0 ? '<span class="tag tag-closed">Rupture</span>' : (int) $p['stock'] ?></td>
                            <td><a href="/market/admin/produit-detail?id=<?= (int) $p['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
