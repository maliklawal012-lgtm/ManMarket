<?php
declare(strict_types=1);

$pageTitle = 'Boutiques';
require_once __DIR__ . '/includes/header.php';

$activeShops = active_subscription_shops_subquery();
$shops = get_db()->query("SELECT * FROM shops WHERE id IN $activeShops ORDER BY sort_order")->fetchAll();
$shops = attach_live_shop_ratings($shops);
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <h1>Boutiques partenaires à Man</h1>
        <p>Découvrez les commerçants locaux qui font la richesse du marché de Man.</p>
    </div>
</section>

<section class="container shops-page">

    <div class="filters-bar card">
        <div class="filters-search">
            <?= icon('search', 16) ?>
            <input type="search" id="shop-search" placeholder="Rechercher une boutique, un quartier...">
        </div>

        <label class="filter-toggle">
            <input type="checkbox" id="shop-open-only">
            <span>Ouvertes uniquement</span>
        </label>

        <div class="filter-sort">
            <label for="shop-sort">Trier par</label>
            <select id="shop-sort">
                <option value="default">Pertinence</option>
                <option value="rating">Mieux notées</option>
                <option value="name">Nom A-Z</option>
            </select>
        </div>
    </div>

    <p class="results-count"><span id="shop-results-count"><?= count($shops) ?></span> boutique(s) trouvée(s)</p>

    <div class="shops-grid" id="shops-grid">
        <?php foreach ($shops as $s): ?>
            <article class="shop-card-lg"
                data-name="<?= e(mb_strtolower($s['name'])) ?>"
                data-neighborhood="<?= e(mb_strtolower($s['neighborhood'])) ?>"
                data-rating="<?= number_format((float) $s['rating'], 1) ?>"
                data-open="<?= (int) $s['is_open'] ?>">

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

    <p class="empty-state" id="shops-empty" hidden>Aucune boutique ne correspond à votre recherche.</p>

</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
