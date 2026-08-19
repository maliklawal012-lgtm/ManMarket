<?php
declare(strict_types=1);

$pageTitle = 'Mon panier';
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
        'icon' => $p['icon'],
        'image' => $p['image'] ? '/market/' . $p['image'] : null,
        'shopName' => $p['shop_name'],
        'shopSlug' => $p['shop_slug'],
        'shopOpen' => (bool) $p['shop_is_open'],
        'stock' => $p['shop_is_open'] ? (int) $p['stock'] : 0,
    ];
}

if ($productsMap) {
    foreach (get_db()->query('SELECT product_id, size, stock FROM product_sizes')->fetchAll() as $row) {
        $key = 'product-' . $row['product_id'];
        if (isset($productsMap[$key])) {
            $productsMap[$key]['sizeStocks'][$row['size']] = (int) $row['stock'];
        }
    }
}
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <h1>Mon panier</h1>
        <p>Vérifiez vos articles avant de passer commande.</p>
    </div>
</section>

<section class="container cart-page">

    <script>window.MM_PRODUCTS = <?= json_encode($productsMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>

    <div class="cart-layout">
        <div class="card cart-items-card">
            <div class="card-header">
                <h2>Articles</h2>
            </div>
            <div id="cart-items"></div>
            <p class="empty-state" id="cart-empty" hidden>Votre panier est vide. <a href="/market/offres.php">Voir les offres</a></p>
        </div>

        <div class="card cart-summary-card" id="cart-summary" hidden>
            <div class="card-header">
                <h2>Résumé</h2>
            </div>
            <div class="cart-summary-row">
                <span>Sous-total</span>
                <strong id="cart-subtotal">0 FCFA</strong>
            </div>
            <p class="cart-summary-note">Les frais de livraison seront calculés à l'étape suivante, selon votre ville et votre quartier.</p>
            <a href="#" id="cart-checkout-btn" class="btn btn-primary btn-block">Passer la commande <?= icon('chevron-right', 14) ?></a>
        </div>
    </div>

</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
