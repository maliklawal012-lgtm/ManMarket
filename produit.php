<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$slug = isset($_GET['slug']) ? (string) $_GET['slug'] : '';
$product = null;

if ($slug !== '') {
    $stmt = get_db()->prepare('
        SELECT p.*, s.name AS shop_name, s.slug AS shop_slug, s.is_open AS shop_is_open, s.whatsapp AS shop_whatsapp, c.name AS category_name, c.slug AS category_slug
        FROM products p
        JOIN shops s ON s.id = p.shop_id
        JOIN categories c ON c.id = p.category_id
        WHERE p.slug = :slug
        LIMIT 1
    ');
    $stmt->execute(['slug' => $slug]);
    $product = $stmt->fetch() ?: null;
    if ($product && !get_shop_active_subscription((int) $product['shop_id'])) {
        $product = null;
    }
}

$pageTitle = $product ? $product['name'] : 'Produit introuvable';
if (!$product) {
    http_response_code(404);
}

$loggedInUser = current_user();
$reviewErrors = [];

if ($product && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review') {
    if (!$loggedInUser) {
        header('Location: /market/connexion.php?redirect=' . urlencode('/market/produit.php?slug=' . $slug));
        exit;
    }

    $rating = (int) ($_POST['rating'] ?? 0);
    $comment = trim((string) ($_POST['comment'] ?? ''));

    if ($rating < 1 || $rating > 5) {
        $reviewErrors['rating'] = 'Veuillez choisir une note de 1 à 5.';
    }

    if (!$reviewErrors) {
        $stmt = get_db()->prepare('
            INSERT INTO reviews (product_id, user_id, name, rating, comment)
            VALUES (:product_id, :user_id, :name, :rating, :comment)
            ON DUPLICATE KEY UPDATE rating = :rating2, comment = :comment2, updated_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute([
            'product_id' => $product['id'],
            'user_id' => $loggedInUser['id'],
            'name' => $loggedInUser['name'],
            'rating' => $rating,
            'comment' => $comment !== '' ? $comment : null,
            'rating2' => $rating,
            'comment2' => $comment !== '' ? $comment : null,
        ]);
        recompute_product_rating((int) $product['id']);

        header('Location: /market/produit.php?slug=' . $slug . '#avis');
        exit;
    }
}

require_once __DIR__ . '/includes/header.php';

if (!$product):
    ?>
    <section class="container not-found">
        <h1>Produit introuvable</h1>
        <p>Ce produit n'existe pas ou n'est plus disponible.</p>
        <a href="/market/offres.php" class="btn btn-primary">Voir les offres</a>
    </section>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
endif;

$pp = get_product_price($product);

$reviews = get_db()->prepare("
    SELECT r.*, EXISTS(
        SELECT 1 FROM legacy_order_items oi
        JOIN contact_messages o ON o.id = oi.order_id
        WHERE oi.product_id = r.product_id AND o.user_id = r.user_id AND o.status = 'delivered'
    ) AS is_verified_purchase
    FROM reviews r
    WHERE r.product_id = :id
    ORDER BY r.created_at DESC
");
$reviews->execute(['id' => $product['id']]);
$reviews = $reviews->fetchAll();

$myReview = null;
if ($loggedInUser) {
    foreach ($reviews as $r) {
        if ((int) ($r['user_id'] ?? 0) === (int) $loggedInUser['id']) {
            $myReview = $r;
            break;
        }
    }
}

$activeShops = active_subscription_shops_subquery();
$relatedStmt = get_db()->prepare("
    SELECT p.*, s.name AS shop_name, s.slug AS shop_slug, s.is_open AS shop_is_open
    FROM products p
    JOIN shops s ON s.id = p.shop_id
    WHERE p.category_id = :category_id AND p.id != :id AND p.shop_id IN $activeShops
    ORDER BY p.rating DESC, p.created_at DESC
    LIMIT 8
");
$relatedStmt->execute(['category_id' => $product['category_id'], 'id' => $product['id']]);
$relatedProducts = $relatedStmt->fetchAll();
?>

<section class="container">
    <nav class="breadcrumb-light">
        <a href="/market/index.php">Accueil</a> <?= icon('chevron-right', 12) ?>
        <a href="/market/categorie.php?slug=<?= e($product['category_slug']) ?>"><?= e($product['category_name']) ?></a> <?= icon('chevron-right', 12) ?>
        <span><?= e($product['name']) ?></span>
    </nav>
</section>

<section class="container product-detail">
    <div class="card product-detail-card">
        <div class="product-detail-thumb"><?= product_thumb_html($product, 64) ?></div>

        <div class="product-detail-info">
            <?php if ($pp['discount_percent']): ?><span class="badge-discount product-detail-badge">-<?= $pp['discount_percent'] ?>%</span><?php endif; ?>
            <h1><?= e($product['name']) ?></h1>
            <a href="/market/boutique.php?slug=<?= e($product['shop_slug']) ?>" class="product-shop-link"><?= icon('store', 14) ?><?= e($product['shop_name']) ?></a>

            <?= product_rating_html($product) ?>

            <div class="price-row product-detail-price">
                <span class="price"><?= format_price($pp['price']) ?></span>
                <?php if ($pp['original_price']): ?><span class="price-old"><?= format_price($pp['original_price']) ?></span><?php endif; ?>
            </div>
            <?php if ($pp['promotion_name']): ?><p class="promo-note"><?= icon('zap', 14) ?> Promotion : <?= e($pp['promotion_name']) ?></p><?php endif; ?>

            <?= stock_badge_html($product) ?>

            <div class="product-detail-actions">
                <?php if (!shop_is_open_value($product)): ?>
                    <button type="button" class="btn btn-outline-primary" disabled>
                        <?= icon('cart', 16) ?> Boutique fermée
                    </button>
                <?php elseif ((int) $product['stock'] > 0): ?>
                    <button type="button" class="btn btn-primary add-cart-btn" data-id="product-<?= (int) $product['id'] ?>" data-name="<?= e($product['name']) ?>">
                        <?= icon('cart', 16) ?> Ajouter au panier
                    </button>
                <?php else: ?>
                    <button type="button" class="btn btn-outline-primary" disabled>
                        <?= icon('cart', 16) ?> Rupture de stock
                    </button>
                <?php endif; ?>
                <button type="button" class="fav-btn product-detail-fav" data-fav-id="product-<?= (int) $product['id'] ?>" aria-label="Ajouter aux favoris">
                    <?= icon('heart', 18) ?>
                </button>
                <?php if ($product['shop_whatsapp'] && shop_is_open_value($product)): ?>
                    <a href="https://wa.me/<?= e(preg_replace('/\D/', '', (string) $product['shop_whatsapp'])) ?>?text=<?= urlencode('Bonjour ' . $product['shop_name'] . ', je suis intéressé(e) par : ' . $product['name'] . '. Est-ce disponible ?') ?>" class="btn btn-outline btn-sm" target="_blank" rel="noopener"><?= icon('send', 15) ?> WhatsApp</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($product['description']): ?>
        <div class="card">
            <div class="admin-toolbar">
                <h2>Description</h2>
            </div>
            <p class="product-description"><?= nl2br(e($product['description'])) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($relatedProducts): ?>
        <div class="card">
            <div class="card-header">
                <h2>Produits similaires</h2>
                <a href="/market/categorie.php?slug=<?= e($product['category_slug']) ?>" class="link-more">Voir la catégorie <?= icon('chevron-right', 14) ?></a>
            </div>
            <div class="scroll-row">
                <?php foreach ($relatedProducts as $p): $pp = get_product_price($p); ?>
                    <article class="product-card">
                        <button type="button" class="fav-btn" data-fav-id="product-<?= (int) $p['id'] ?>" aria-label="Ajouter aux favoris">
                            <?= icon('heart', 16) ?>
                        </button>
                        <?php if ($pp['discount_percent']): ?><span class="badge-discount">-<?= $pp['discount_percent'] ?>%</span><?php endif; ?>
                        <a href="/market/produit.php?slug=<?= e($p['slug']) ?>" class="product-card-link">
                            <div class="product-thumb"><?= product_thumb_html($p, 34) ?></div>
                            <h3><?= e($p['name']) ?></h3>
                        </a>
                        <span class="product-shop-link"><?= e($p['shop_name']) ?></span>
                        <div class="price-row">
                            <span class="price"><?= format_price($pp['price']) ?></span>
                            <?php if ($pp['original_price']): ?><span class="price-old"><?= format_price($pp['original_price']) ?></span><?php endif; ?>
                        </div>
                        <?= product_rating_html($p) ?>
                        <?= add_to_cart_button_html($p) ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card" id="avis">
        <div class="admin-toolbar">
            <h2>Avis clients (<?= count($reviews) ?>)</h2>
        </div>

        <?php if ($loggedInUser): ?>
            <form method="post" action="/market/produit.php?slug=<?= e($slug) ?>#avis" class="review-form" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="review">

                <div class="form-field <?= isset($reviewErrors['rating']) ? 'has-error' : '' ?>">
                    <label for="rating"><?= $myReview ? 'Modifier ma note' : 'Ma note' ?> *</label>
                    <select id="rating" name="rating" required>
                        <option value="">Choisir...</option>
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                            <option value="<?= $i ?>" <?= (int) ($myReview['rating'] ?? 0) === $i ? 'selected' : '' ?>><?= $i ?> étoile<?= $i > 1 ? 's' : '' ?></option>
                        <?php endfor; ?>
                    </select>
                    <?php if (isset($reviewErrors['rating'])): ?><span class="field-error"><?= e($reviewErrors['rating']) ?></span><?php endif; ?>
                </div>

                <div class="form-field">
                    <label for="comment">Commentaire (optionnel)</label>
                    <textarea id="comment" name="comment" rows="3"><?= e((string) ($myReview['comment'] ?? '')) ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary btn-sm"><?= $myReview ? 'Mettre à jour mon avis' : 'Publier mon avis' ?></button>
            </form>
        <?php else: ?>
            <p class="empty-state">
                <a href="/market/connexion.php?redirect=<?= urlencode('/market/produit.php?slug=' . $slug) ?>">Connectez-vous</a>
                pour laisser un avis sur ce produit.
            </p>
        <?php endif; ?>

        <?php if ($reviews): ?>
            <div class="review-list">
                <?php foreach ($reviews as $r): ?>
                    <div class="review-item">
                        <div class="review-item-header">
                            <span style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                <strong><?= e($r['name']) ?></strong>
                                <?php if ($r['is_verified_purchase']): ?><span class="tag tag-green"><?= icon('check-circle', 12) ?> Achat vérifié</span><?php endif; ?>
                            </span>
                            <span class="review-item-date"><?= e(date('d/m/Y', strtotime((string) $r['created_at']))) ?><?= $r['updated_at'] ? ' (modifié)' : '' ?></span>
                        </div>
                        <div class="rating-row"><?= render_stars((float) $r['rating']) ?></div>
                        <?php if ($r['comment']): ?><p class="review-item-comment"><?= nl2br(e($r['comment'])) ?></p><?php endif; ?>
                        <?php if ($r['vendor_reply']): ?>
                            <div class="vendor-reply-box">
                                <strong><?= icon('store', 14) ?> Réponse de <?= e($product['shop_name']) ?></strong>
                                <span class="char-count"><?= e(date('d/m/Y', strtotime((string) $r['vendor_reply_at']))) ?></span>
                                <p><?= nl2br(e($r['vendor_reply'])) ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php elseif (!$loggedInUser): ?>
            <p class="empty-state">Aucun avis pour le moment.</p>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
