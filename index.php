<?php
declare(strict_types=1);

$pageTitle = 'Accueil';
require_once __DIR__ . '/includes/header.php';

$db = get_db();

$activeShops = active_subscription_shops_subquery();

$categories = $db->query('SELECT * FROM categories ORDER BY sort_order')->fetchAll();
$offers = $db->query("
    SELECT p.*, s.is_open AS shop_is_open
    FROM products p
    JOIN shops s ON s.id = p.shop_id
    WHERE p.is_featured = 1 AND p.shop_id IN $activeShops
    ORDER BY p.sort_order
")->fetchAll();
$newProducts = $db->query("
    SELECT p.*, s.name AS shop_name, s.slug AS shop_slug, s.is_open AS shop_is_open
    FROM products p
    JOIN shops s ON s.id = p.shop_id
    WHERE p.shop_id IN $activeShops
    ORDER BY p.created_at DESC, p.id DESC
    LIMIT 12
")->fetchAll();
$shops = $db->query("SELECT * FROM shops WHERE id IN $activeShops ORDER BY sort_order")->fetchAll();
$shops = attach_live_shop_ratings($shops);
$news = $db->query('SELECT * FROM news ORDER BY sort_order')->fetchAll();
$ads = get_active_advertisements();

$categoryIcons = [
    'alimentation' => 'shopping-basket',
    'mode-beaute' => 'shirt',
    'electronique' => 'smartphone',
    'maison-bureau' => 'sofa',
    'sante-pharmacie' => 'cross',
];

// Normalisation des coordonnees pour le placeholder de carte
$lats = array_column($shops, 'lat');
$lngs = array_column($shops, 'lng');
$latMin = $lats ? min($lats) - 0.006 : 0;
$latMax = $lats ? max($lats) + 0.006 : 1;
$lngMin = $lngs ? min($lngs) - 0.006 : 0;
$lngMax = $lngs ? max($lngs) + 0.006 : 1;

function map_position(float $lat, float $lng, float $latMin, float $latMax, float $lngMin, float $lngMax): array
{
    $x = $lngMax > $lngMin ? (($lng - $lngMin) / ($lngMax - $lngMin)) * 100 : 50;
    $y = $latMax > $latMin ? 100 - ((($lat - $latMin) / ($latMax - $latMin)) * 100) : 50;

    return [max(6, min(94, $x)), max(6, min(94, $y))];
}
?>

<section class="home-grid container">

    <div class="col col-hero">
        <div class="card hero-card">
            <?php $heroImage = get_setting('hero_image'); ?>
            <div class="hero-media" <?= $heroImage ? 'style="background-image: linear-gradient(180deg, rgba(10,30,18,.35) 0%, rgba(8,20,13,.88) 100%), url(\'/market/' . e($heroImage) . '\'); background-size: cover; background-position: center;"' : '' ?>>
                <div class="hero-overlay"></div>
                <div class="hero-content">
                    <h1>Le plus grand marché en ligne de <span class="text-accent">la ville de Man</span></h1>
                    <p>Achetez local, soutenez nos commerçants, faites-vous livrer partout à Man.</p>
                    <div class="hero-actions">
                        <a href="/market/offres.php" class="btn btn-primary">Acheter maintenant</a>
                        <a href="#categories" class="btn btn-outline">Découvrir</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="card trust-badges">
            <div class="trust-item">
                <span class="trust-icon"><?= icon('check-circle', 18) ?></span>
                <div><strong>Produits locaux</strong><span>100% de chez nous</span></div>
            </div>
            <div class="trust-item">
                <span class="trust-icon"><?= icon('truck', 18) ?></span>
                <div><strong>Livraison rapide</strong><span>Partout à Man</span></div>
            </div>
            <div class="trust-item">
                <span class="trust-icon"><?= icon('shield', 18) ?></span>
                <div><strong>Paiement sécurisé</strong><span>Mobile Money, CB</span></div>
            </div>
            <div class="trust-item">
                <span class="trust-icon"><?= icon('headset', 18) ?></span>
                <div><strong>Support 24/7</strong><span>Nous sommes là</span></div>
            </div>
        </div>

        <div class="card categories-card" id="categories">
            <div class="card-header">
                <h2>Catégories populaires</h2>
                <a href="/market/categories.php" class="link-more">Voir toutes <?= icon('chevron-right', 14) ?></a>
            </div>
            <div class="categories-grid reveal">
                <?php foreach ($categories as $cat): ?>
                    <a class="category-item" href="/market/categorie.php?slug=<?= e($cat['slug']) ?>">
                        <span class="category-icon" style="background:<?= e($cat['color']) ?>1a; color:<?= e($cat['color']) ?>">
                            <?= icon($cat['icon'], 22) ?>
                        </span>
                        <span><?= e($cat['name']) ?></span>
                    </a>
                <?php endforeach; ?>
                <a class="category-item category-more" href="/market/categories.php">
                    <span class="category-icon"><?= icon('chevron-right', 22) ?></span>
                    <span>Plus de catégories</span>
                </a>
            </div>
        </div>
    </div>

    <div class="col col-main">
        <div class="card">
            <div class="card-header">
                <h2>Meilleures offres du moment</h2>
                <a href="/market/offres.php" class="link-more">Voir toutes les offres <?= icon('chevron-right', 14) ?></a>
            </div>
            <div class="scroll-row reveal">
                <?php foreach ($offers as $p): $pp = get_product_price($p); ?>
                    <article class="product-card">
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
                        <?= add_to_cart_button_html($p) ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($newProducts): ?>
            <div class="card">
                <div class="card-header">
                    <h2>Nouveaux produits</h2>
                    <a href="/market/offres.php" class="link-more">Voir tout <?= icon('chevron-right', 14) ?></a>
                </div>
                <div class="scroll-row reveal">
                    <?php foreach ($newProducts as $p): $pp = get_product_price($p); ?>
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
                            <button type="button" class="btn btn-primary btn-sm btn-block add-cart-btn" data-id="product-<?= (int) $p['id'] ?>" data-name="<?= e($p['name']) ?>">
                                Ajouter au panier
                            </button>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($ads): ?>
            <div class="card ad-banner-card">
                <span class="ad-banner-label">Publicité</span>
                <div class="ad-carousel" id="ad-carousel">
                    <?php foreach ($ads as $i => $ad): ?>
                        <?php if ($ad['link_url']): ?>
                            <a href="<?= e($ad['link_url']) ?>" class="ad-carousel-slide<?= $i === 0 ? ' is-active' : '' ?>"<?= preg_match('#^https?://#', (string) $ad['link_url']) ? ' target="_blank" rel="noopener sponsored"' : '' ?>>
                                <img src="/market/<?= e($ad['image']) ?>" alt="<?= e($ad['title']) ?>" loading="lazy">
                            </a>
                        <?php else: ?>
                            <span class="ad-carousel-slide<?= $i === 0 ? ' is-active' : '' ?>">
                                <img src="/market/<?= e($ad['image']) ?>" alt="<?= e($ad['title']) ?>" loading="lazy">
                            </span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (count($ads) > 1): ?>
                        <div class="ad-carousel-dots">
                            <?php foreach ($ads as $i => $ad): ?>
                                <button type="button" class="ad-carousel-dot<?= $i === 0 ? ' is-active' : '' ?>" data-index="<?= $i ?>" aria-label="Publicité <?= $i + 1 ?>"></button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2>Boutiques populaires</h2>
                <a href="/market/boutiques.php" class="link-more">Voir toutes <?= icon('chevron-right', 14) ?></a>
            </div>
            <div class="scroll-row reveal">
                <?php foreach ($shops as $s): ?>
                    <a class="shop-card" href="/market/boutique.php?slug=<?= e($s['slug']) ?>">
                        <div class="shop-logo" style="background:<?= e($s['color']) ?>"><?= shop_logo_html($s) ?></div>
                        <h3><?= e($s['name']) ?></h3>
                        <span class="shop-location"><?= icon('map-pin', 13) ?><?= e($s['neighborhood']) ?></span>
                        <?php if ((int) $s['review_count'] > 0): ?>
                            <div class="rating-row"><?= icon('star-filled', 13) ?><strong><?= number_format((float) $s['rating'], 1) ?></strong><span>(<?= (int) $s['review_count'] ?>)</span></div>
                        <?php else: ?>
                            <div class="rating-row rating-row-empty">Pas encore d'avis</div>
                        <?php endif; ?>
                        <?php if ($s['fast_delivery']): ?><span class="tag tag-green">Livraison rapide</span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card banner-delivery">
            <div class="banner-text">
                <h2>Livraison express à Man</h2>
                <p>Commandez aujourd'hui, recevez aujourd'hui !</p>
                <a href="/market/services.php" class="btn btn-white">En savoir plus</a>
            </div>
            <div class="banner-illustration"><?= icon('zap', 40) ?></div>
        </div>
    </div>

    <div class="col col-side">
        <div class="card map-card">
            <div class="card-header">
                <h2>Carte des boutiques</h2>
            </div>
            <div class="map-placeholder">
                <?php foreach ($shops as $s):
                    if ($s['lat'] === null || $s['lng'] === null) { continue; }
                    [$x, $y] = map_position((float) $s['lat'], (float) $s['lng'], $latMin, $latMax, $lngMin, $lngMax);
                ?>
                    <a class="map-pin-item" href="/market/boutique.php?slug=<?= e($s['slug']) ?>" style="left:<?= $x ?>%; top:<?= $y ?>%" title="<?= e($s['name']) ?>"><?= icon('map-pin', 20) ?></a>
                <?php endforeach; ?>
            </div>
            <ul class="shop-list">
                <?php foreach ($shops as $s): ?>
                    <li>
                        <a class="shop-list-link" href="/market/boutique.php?slug=<?= e($s['slug']) ?>">
                            <div class="shop-list-logo" style="background:<?= e($s['color']) ?>"><?= shop_logo_html($s) ?></div>
                            <div class="shop-list-info">
                                <strong><?= e($s['name']) ?></strong>
                                <span><?= e($s['neighborhood']) ?></span>
                                <?php if ((int) $s['review_count'] > 0): ?>
                                    <span class="rating-row small"><?= icon('star-filled', 12) ?><?= number_format((float) $s['rating'], 1) ?></span>
                                <?php else: ?>
                                    <span class="rating-row small rating-row-empty">Pas encore d'avis</span>
                                <?php endif; ?>
                            </div>
                            <span class="tag <?= $s['is_open'] ? 'tag-open' : 'tag-closed' ?>"><?= $s['is_open'] ? 'Ouvert' : 'Fermé' ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <a href="/market/boutiques.php" class="btn btn-outline btn-block">Voir plus de boutiques</a>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Actualités &amp; Événements à Man</h2>
                <a href="/market/actualites.php" class="link-more">Voir toutes <?= icon('chevron-right', 14) ?></a>
            </div>
            <div class="news-grid">
                <?php foreach ($news as $n): ?>
                    <article class="news-card">
                        <div class="news-date"><strong><?= e($n['event_day']) ?></strong><span><?= e($n['event_month']) ?></span></div>
                        <div class="news-thumb"><?= icon($n['icon'], 26) ?></div>
                        <h4><?= e($n['title']) ?></h4>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
