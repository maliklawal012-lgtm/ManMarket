<?php
declare(strict_types=1);

$pageTitle = 'Offres';
require_once __DIR__ . '/includes/header.php';

$db = get_db();
$activeShops = active_subscription_shops_subquery();
$categories = $db->query('SELECT * FROM categories ORDER BY sort_order')->fetchAll();
$products = $db->query("
    SELECT p.*, s.name AS shop_name, s.slug AS shop_slug, s.is_open AS shop_is_open, c.slug AS category_slug
    FROM products p
    JOIN shops s ON s.id = p.shop_id
    JOIN categories c ON c.id = p.category_id
    WHERE p.shop_id IN $activeShops
    ORDER BY p.sort_order
")->fetchAll();
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <h1>Toutes les offres du moment</h1>
        <p>Les meilleures réductions chez les vendeurs de Man, mises à jour chaque jour.</p>
    </div>
</section>

<section class="container shops-page">

    <div class="filters-bar card">
        <div class="filters-search">
            <?= icon('search', 16) ?>
            <input type="search" id="offer-search" placeholder="Rechercher un produit...">
        </div>

        <div class="filter-sort">
            <label for="offer-category">Catégorie</label>
            <select id="offer-category">
                <option value="">Toutes</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= e($cat['slug']) ?>"><?= e($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
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

    <div class="products-grid reveal" id="offers-grid">
        <?php foreach ($products as $p): $pp = get_product_price($p); ?>
            <article class="product-card product-card-static"
                data-name="<?= e(mb_strtolower($p['name'])) ?>"
                data-category="<?= e($p['category_slug']) ?>"
                data-price="<?= $pp['price'] ?>"
                data-discount="<?= (int) ($pp['discount_percent'] ?? 0) ?>"
                data-rating="<?= number_format((float) $p['rating'], 1) ?>">

                <button type="button" class="fav-btn" data-fav-id="product-<?= (int) $p['id'] ?>" aria-label="Ajouter aux favoris">
                    <?= icon('heart', 16) ?>
                </button>
                <?php if ($pp['discount_percent']): ?><span class="badge-discount">-<?= $pp['discount_percent'] ?>%</span><?php endif; ?>
                <a href="/market/produit.php?slug=<?= e($p['slug']) ?>" class="product-card-link">
                    <div class="product-thumb"><?= product_thumb_html($p, 34) ?></div>
                    <h3><?= e($p['name']) ?></h3>
                </a>
                <a href="/market/boutique.php?slug=<?= e($p['shop_slug']) ?>" class="product-shop-link">
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

    <p class="empty-state" id="offers-empty" hidden>Aucune offre ne correspond à votre recherche.</p>

</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
