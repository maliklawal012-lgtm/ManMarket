<?php
declare(strict_types=1);

$pageTitle = 'Actualités';
require_once __DIR__ . '/includes/header.php';

$news = get_db()->query('SELECT * FROM news ORDER BY sort_order')->fetchAll();
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <h1>Actualités &amp; Événements à Man</h1>
        <p>Foires, animations locales et nouvelles de la ville, à ne pas manquer.</p>
    </div>
</section>

<section class="container shops-page">

    <div class="filters-bar card">
        <div class="filters-search">
            <?= icon('search', 16) ?>
            <input type="search" id="news-search" placeholder="Rechercher une actualité...">
        </div>
    </div>

    <p class="results-count"><span id="news-results-count"><?= count($news) ?></span> actualité(s)</p>

    <div class="news-grid-page" id="news-grid">
        <?php foreach ($news as $n): ?>
            <article class="news-card-lg" data-title="<?= e(mb_strtolower($n['title'])) ?>" data-excerpt="<?= e(mb_strtolower($n['excerpt'])) ?>">
                <div class="news-date"><strong><?= e($n['event_day']) ?></strong><span><?= e($n['event_month']) ?></span></div>
                <div class="news-thumb-lg"><?= icon($n['icon'], 30) ?></div>
                <h3><?= e($n['title']) ?></h3>
                <p><?= e($n['excerpt']) ?></p>
            </article>
        <?php endforeach; ?>
    </div>

    <p class="empty-state" id="news-empty" hidden>Aucune actualité ne correspond à votre recherche.</p>

</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
