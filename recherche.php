<?php
declare(strict_types=1);

$pageTitle = 'Recherche';
require_once __DIR__ . '/includes/header.php';

$q = trim((string) ($_GET['q'] ?? ''));
$products = [];
$shops = [];

if ($q !== '') {
    $like = '%' . $q . '%';
    $activeShops = active_subscription_shops_subquery();

    $stmt = get_db()->prepare("
        SELECT p.*, s.name AS shop_name, s.slug AS shop_slug, s.is_open AS shop_is_open
        FROM products p
        JOIN shops s ON s.id = p.shop_id
        WHERE p.name LIKE :q AND p.shop_id IN $activeShops
        ORDER BY p.sort_order
    ");
    $stmt->execute(['q' => $like]);
    $products = $stmt->fetchAll();

    $stmt = get_db()->prepare("
        SELECT * FROM shops
        WHERE (name LIKE :q1 OR neighborhood LIKE :q2) AND id IN $activeShops
        ORDER BY sort_order
    ");
    $stmt->execute(['q1' => $like, 'q2' => $like]);
    $shops = attach_live_shop_ratings($stmt->fetchAll());
}

$totalResults = count($products) + count($shops);
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <h1>Recherche</h1>
        <p><?= $q !== '' ? 'Résultats pour « ' . e($q) . ' »' : 'Recherchez un produit ou une boutique.' ?></p>
    </div>
</section>

<section class="container shops-page">

    <form class="filters-bar card" method="get" action="/market/recherche.php">
        <div class="filters-search">
            <?= icon('search', 16) ?>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Rechercher un produit, une boutique...">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Rechercher</button>
    </form>

    <?php if ($q === ''): ?>

        <p class="empty-state">Saisissez un mot-clé pour lancer votre recherche.</p>

    <?php elseif ($totalResults === 0): ?>

        <p class="empty-state">Aucun résultat pour « <?= e($q) ?> ». Essayez un autre mot-clé.</p>

    <?php else: ?>

        <p class="results-count"><?= $totalResults ?> résultat(s) trouvé(s)</p>

        <?php if ($products): ?>
            <div class="card-header">
                <h2>Produits (<?= count($products) ?>)</h2>
            </div>
            <div class="products-grid reveal">
                <?php foreach ($products as $p): $pp = get_product_price($p); ?>
                    <article class="product-card product-card-static">
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
        <?php endif; ?>

        <?php if ($shops): ?>
            <div class="card-header">
                <h2>Boutiques (<?= count($shops) ?>)</h2>
            </div>
            <div class="shops-grid reveal">
                <?php foreach ($shops as $s): ?>
                    <article class="shop-card-lg">
                        <div class="shop-card-lg-top">
                            <div class="shop-logo shop-logo-lg" style="background:<?= e($s['color']) ?>"><?= shop_logo_html($s) ?></div>
                            <span class="tag <?= $s['is_open'] ? 'tag-open' : 'tag-closed' ?>"><?= $s['is_open'] ? 'Ouvert' : 'Fermé' ?></span>
                        </div>

                        <h3><?= e($s['name']) ?></h3>
                        <span class="shop-location"><?= icon('map-pin', 14) ?><?= e($s['neighborhood']) ?></span>

                        <?= shop_rating_html($s) ?>

                        <?php if ($s['fast_delivery']): ?><span class="tag tag-green">Livraison rapide</span><?php endif; ?>

                        <a href="/market/boutique.php?slug=<?= e($s['slug']) ?>" class="btn btn-outline-primary btn-sm btn-block">
                            Voir la boutique <?= icon('chevron-right', 14) ?>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>

</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
