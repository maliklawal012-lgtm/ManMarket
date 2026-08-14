<?php
declare(strict_types=1);

$pageTitle = 'Mes favoris';
require_once __DIR__ . '/includes/header.php';

$activeShops = active_subscription_shops_subquery();
$products = get_db()->query("
    SELECT p.*, s.name AS shop_name, s.slug AS shop_slug, s.is_open AS shop_is_open
    FROM products p
    JOIN shops s ON s.id = p.shop_id
    WHERE p.shop_id IN $activeShops
    ORDER BY p.sort_order
")->fetchAll();

$productsMap = [];
foreach ($products as $p) {
    $pp = get_product_price($p);
    $productsMap['product-' . $p['id']] = [
        'name' => $p['name'],
        'price' => $pp['price'],
        'originalPrice' => $pp['original_price'],
        'discount' => $pp['discount_percent'],
        'icon' => $p['icon'],
        'image' => $p['image'] ? '/market/' . $p['image'] : null,
        'rating' => (float) $p['rating'],
        'reviewCount' => (int) $p['review_count'],
        'shopName' => $p['shop_name'],
        'shopSlug' => $p['shop_slug'],
        'stock' => (int) $p['stock'],
        'shopOpen' => (bool) $p['shop_is_open'],
    ];
}
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <h1>Mes favoris</h1>
        <p>Retrouvez ici les produits que vous avez aimés.</p>
    </div>
</section>

<section class="container shops-page">

    <script>window.MM_PRODUCTS = <?= json_encode($productsMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>

    <div class="products-grid" id="favorites-grid"></div>
    <p class="empty-state" id="favorites-empty" hidden>Vous n'avez aucun favori pour le moment. <a href="/market/offres.php">Découvrir les offres</a></p>

</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
