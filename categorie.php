<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

$slug = isset($_GET['slug']) ? (string) $_GET['slug'] : '';
$category = null;

if ($slug !== '') {
    $stmt = get_db()->prepare('SELECT * FROM categories WHERE slug = :slug LIMIT 1');
    $stmt->execute(['slug' => $slug]);
    $category = $stmt->fetch() ?: null;
}

$pageTitle = $category ? $category['name'] : 'Catégorie introuvable';
if (!$category) {
    http_response_code(404);
}
require_once __DIR__ . '/includes/header.php';

if (!$category):
    ?>
    <section class="container not-found">
        <h1>Catégorie introuvable</h1>
        <p>Cette catégorie n'existe pas ou n'est plus disponible.</p>
        <a href="/market/index" class="btn btn-primary">Retour à l'accueil</a>
    </section>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
endif;

$activeShops = active_subscription_shops_subquery();
$stmt = get_db()->prepare("
    SELECT p.*, s.name AS shop_name, s.slug AS shop_slug, s.is_open AS shop_is_open
    FROM products p
    JOIN shops s ON s.id = p.shop_id
    WHERE p.category_id = :id AND p.shop_id IN $activeShops
    ORDER BY p.sort_order
");
$stmt->execute(['id' => $category['id']]);
$products = $stmt->fetchAll();
?>

<section class="page-banner" style="background: linear-gradient(120deg, <?= e($category['color']) ?> 0%, #0e2a1c 140%)">
    <div class="container page-banner-inner">
        <span class="category-banner-icon"><?= icon($category['icon'], 26) ?></span>
        <h1><?= e($category['name']) ?></h1>
        <p><?= count($products) ?> produit(s) disponible(s) dans cette catégorie à Man.</p>
    </div>
</section>

<section class="container category-chips">
    <?php foreach ($navCategories as $cat): ?>
        <a href="/market/categorie?slug=<?= e($cat['slug']) ?>" class="chip <?= $cat['slug'] === $category['slug'] ? 'chip-active' : '' ?>">
            <?= e($cat['name']) ?>
        </a>
    <?php endforeach; ?>
</section>

<section class="container shops-page">

    <div class="filters-bar card">
        <div class="filters-search">
            <?= icon('search', 16) ?>
            <input type="search" id="offer-search" placeholder="Rechercher un produit dans <?= e($category['name']) ?>...">
        </div>

        <div class="filter-sort">
            <label for="offer-sort">Trier par</label>
            <select id="offer-sort">
                <option value="default">Pertinence</option>
                <option value="discount">Meilleures réductions</option>
                <option value="price-asc">Prix croissant</option>
                <option value="price-desc">Prix décroissant</option>
                <option value="rating">Mieux notés</option>
            </select>
        </div>
    </div>

    <p class="results-count"><span id="offer-results-count"><?= count($products) ?></span> produit(s) trouvé(s)</p>

    <?php if ($products): ?>
        <div class="products-grid reveal" id="offers-grid">
            <?php foreach ($products as $p): $pp = get_product_price($p); ?>
                <article class="product-card product-card-static"
                    data-name="<?= e(mb_strtolower($p['name'])) ?>"
                    data-price="<?= $pp['price'] ?>"
                    data-discount="<?= (int) ($pp['discount_percent'] ?? 0) ?>"
                    data-rating="<?= number_format((float) $p['rating'], 1) ?>">

                    <button type="button" class="fav-btn" data-fav-id="product-<?= (int) $p['id'] ?>" aria-label="Ajouter aux favoris">
                        <?= icon('heart', 16) ?>
                    </button>
                    <?php if ($pp['discount_percent']): ?><span class="badge-discount">-<?= $pp['discount_percent'] ?>%</span><?php endif; ?>
                    <a href="/market/produit?slug=<?= e($p['slug']) ?>" class="product-card-link">
                        <div class="product-thumb"><?= product_thumb_html($p, 34) ?></div>
                        <h3><?= e($p['name']) ?></h3>
                    </a>
                    <a href="/market/boutique?slug=<?= e($p['shop_slug']) ?>" class="product-shop-link">
                        <?= icon('store', 12) ?><?= e($p['shop_name']) ?>
                    </a>
                    <div class="price-row">
                        <span class="price"><?= format_price($pp['price']) ?></span>
                        <?php if ($pp['original_price']): ?><span class="price-old"><?= format_price($pp['original_price']) ?></span><?php endif; ?>
                    </div>
                    <?= product_rating_html($p) ?>
                    <?= add_to_cart_button_html($p) ?>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="empty-state" id="offers-empty" hidden>Aucun produit ne correspond à votre recherche.</p>
    <?php else: ?>
        <p class="empty-state">Aucun produit n'est encore disponible dans cette catégorie.</p>
    <?php endif; ?>

</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
