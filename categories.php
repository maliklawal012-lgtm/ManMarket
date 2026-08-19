<?php
declare(strict_types=1);

$pageTitle = 'Catégories';
require_once __DIR__ . '/includes/header.php';

$categories = get_db()->query('
    SELECT c.*, COUNT(p.id) AS product_count
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    GROUP BY c.id
    ORDER BY c.sort_order
')->fetchAll();
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <h1>Toutes les catégories</h1>
        <p>Parcourez l'ensemble des rayons du marché de Man.</p>
    </div>
</section>

<section class="container shops-page">

    <div class="categories-grid-page">
        <?php foreach ($categories as $cat): ?>
            <a href="/market/categorie?slug=<?= e($cat['slug']) ?>" class="card category-card-lg">
                <span class="category-icon" style="background:<?= e($cat['color']) ?>1a; color:<?= e($cat['color']) ?>">
                    <?= icon($cat['icon'], 28) ?>
                </span>
                <h3><?= e($cat['name']) ?></h3>
                <span class="category-card-count"><?= (int) $cat['product_count'] ?> produit(s)</span>
            </a>
        <?php endforeach; ?>
    </div>

</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
