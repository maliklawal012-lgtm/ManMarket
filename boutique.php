<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$slug = isset($_GET['slug']) ? (string) $_GET['slug'] : '';
$shop = null;

if ($slug !== '') {
    $stmt = get_db()->prepare('SELECT * FROM shops WHERE slug = :slug LIMIT 1');
    $stmt->execute(['slug' => $slug]);
    $shop = $stmt->fetch() ?: null;
    if ($shop && ($shop['approval_status'] !== 'approved' || !get_shop_active_subscription((int) $shop['id']))) {
        $shop = null;
    }
    if ($shop) {
        [$shop] = attach_live_shop_ratings([$shop]);
    }
}

$pageTitle = $shop ? $shop['name'] : 'Boutique introuvable';
if (!$shop) {
    http_response_code(404);
}
require_once __DIR__ . '/includes/header.php';

if (!$shop):
    ?>
    <section class="container not-found">
        <h1>Boutique introuvable</h1>
        <p>Cette boutique n'existe pas ou n'est plus disponible.</p>
        <a href="/market/boutiques.php" class="btn btn-primary">Retour aux boutiques</a>
    </section>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
endif;

$stmt = get_db()->prepare('SELECT COUNT(*) FROM products WHERE shop_id = :id');
$stmt->execute(['id' => $shop['id']]);
$pagination = paginate((int) $stmt->fetchColumn(), 12);

$stmt = get_db()->prepare('SELECT * FROM products WHERE shop_id = :id ORDER BY sort_order LIMIT :limit OFFSET :offset');
$stmt->bindValue('id', $shop['id'], PDO::PARAM_INT);
$stmt->bindValue('limit', $pagination['per_page'], PDO::PARAM_INT);
$stmt->bindValue('offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll();
?>

<section class="shop-hero" style="background: linear-gradient(120deg, <?= e($shop['color']) ?> 0%, #0e2a1c 130%)">
    <div class="container shop-hero-inner">
        <nav class="breadcrumb">
            <a href="/market/index.php">Accueil</a> <?= icon('chevron-right', 12) ?>
            <a href="/market/boutiques.php">Boutiques</a> <?= icon('chevron-right', 12) ?>
            <span><?= e($shop['name']) ?></span>
        </nav>

        <div class="shop-hero-main">
            <div class="shop-logo shop-logo-xl"><?= shop_logo_html($shop) ?></div>
            <div class="shop-hero-info">
                <div class="shop-hero-title">
                    <h1><?= e($shop['name']) ?></h1>
                    <span class="tag <?= $shop['is_open'] ? 'tag-open' : 'tag-closed' ?>"><?= $shop['is_open'] ? 'Ouvert' : 'Fermé' ?></span>
                </div>
                <span class="shop-location"><?= icon('map-pin', 15) ?><?= e($shop['neighborhood']) ?></span>
                <?php if ($shop['phone']): ?><span class="shop-location"><?= icon('phone', 15) ?><?= e($shop['phone']) ?></span><?php endif; ?>
                <?= shop_rating_html($shop) ?>
                <?php if ($shop['fast_delivery']): ?><span class="tag tag-green">Livraison rapide</span><?php endif; ?>
                <div class="shop-contact-actions">
                    <a href="/market/contact.php?shop_id=<?= (int) $shop['id'] ?>&subject=<?= urlencode('Question générale') ?>&message=<?= urlencode('Bonjour, j\'ai une question à propos de la boutique ' . $shop['name'] . ' : ') ?>" class="btn btn-outline btn-sm"><?= icon('send', 15) ?> Contacter cette boutique</a>
                    <?php if ($shop['whatsapp']): ?>
                        <a href="https://wa.me/<?= e(preg_replace('/\D/', '', (string) $shop['whatsapp'])) ?>?text=<?= urlencode('Bonjour ' . $shop['name'] . ', ') ?>" class="btn btn-outline btn-sm" target="_blank" rel="noopener"><?= icon('send', 15) ?> WhatsApp</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="container shop-detail">
    <div class="card-header">
        <h2>Produits de <?= e($shop['name']) ?></h2>
        <span class="results-count"><?= $pagination['total_items'] ?> produit(s)</span>
    </div>

    <?php if ($products): ?>
        <div class="products-grid reveal">
            <?php foreach ($products as $p): $p['shop_is_open'] = $shop['is_open']; $pp = get_product_price($p); ?>
                <article class="product-card product-card-static">
                    <button type="button" class="fav-btn" data-fav-id="product-<?= (int) $p['id'] ?>" aria-label="Ajouter aux favoris">
                        <?= icon('heart', 16) ?>
                    </button>
                    <?php if ($pp['discount_percent']): ?><span class="badge-discount">-<?= $pp['discount_percent'] ?>%</span><?php endif; ?>
                    <a href="/market/produit.php?slug=<?= e($p['slug']) ?>" class="product-card-link">
                        <div class="product-thumb"><?= product_thumb_html($p, 34) ?></div>
                        <h3><?= e($p['name']) ?></h3>
                    </a>
                    <div class="price-row">
                        <span class="price"><?= format_price($pp['price']) ?></span>
                        <?php if ($pp['original_price']): ?><span class="price-old"><?= format_price($pp['original_price']) ?></span><?php endif; ?>
                    </div>
                    <?= product_rating_html($p) ?>
                    <?= stock_badge_html($p) ?>
                    <?= add_to_cart_button_html($p) ?>
                </article>
            <?php endforeach; ?>
        </div>
        <?= pagination_html($pagination['page'], $pagination['total_pages'], '/market/boutique.php', ['slug' => $slug]) ?>
    <?php elseif ($pagination['total_items'] === 0): ?>
        <p class="empty-state">Cette boutique n'a pas encore de produits en ligne.</p>
    <?php else: ?>
        <p class="empty-state">Aucun produit sur cette page.</p>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
