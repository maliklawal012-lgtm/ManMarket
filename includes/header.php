<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

try {
    $navCategories = get_db()->query('SELECT name, slug FROM categories ORDER BY sort_order')->fetchAll();
} catch (Throwable $e) {
    $navCategories = [];
}

$loggedUser = current_user();

$currentPage = basename($_SERVER['SCRIPT_NAME']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? e($pageTitle) . ' | ManMarket' : 'ManMarket — Le marché en ligne de la ville de Man' ?></title>
<meta name="description" content="ManMarket, le plus grand marché en ligne de la ville de Man. Achetez local, soutenez les vendeurs, livraison partout à Man.">
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 24 24%22><rect width=%2224%22 height=%2224%22 rx=%226%22 fill=%22%2316a34a%22/><text x=%2212%22 y=%2217%22 font-size=%2214%22 fill=%22white%22 text-anchor=%22middle%22 font-family=%22Arial%22>M</text></svg>">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
</head>
<body>

<header class="site-header">
    <div class="topbar">
        <div class="container topbar-inner">
            <a href="/market/index" class="logo">
                <?php $siteLogo = get_setting('site_logo'); ?>
                <?php if ($siteLogo): ?>
                    <img src="/market/<?= e($siteLogo) ?>" alt="<?= e(get_setting('site_name') ?: 'ManMarket') ?>" class="logo-mark logo-mark-img">
                <?php else: ?>
                    <span class="logo-mark">M</span>
                <?php endif; ?>
                <span class="logo-text">ManMarket<small>Man, Côte d'Ivoire</small></span>
            </a>

            <div class="category-select">
                <button type="button" class="category-select-btn" data-dropdown-toggle="cat-dropdown">
                    <?= icon('menu', 16) ?>
                    <span>Toutes les catégories</span>
                    <?= icon('chevron-down', 14) ?>
                </button>
                <div class="dropdown-panel" id="cat-dropdown">
                    <?php foreach ($navCategories as $cat): ?>
                        <a href="/market/categorie?slug=<?= e($cat['slug']) ?>"><?= e($cat['name']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="search-wrapper">
                <form class="search-form" action="/market/recherche" method="get" role="search" autocomplete="off">
                    <input type="search" name="q" placeholder="Rechercher un produit, une boutique..." aria-label="Rechercher">
                    <button type="submit" aria-label="Lancer la recherche"><?= icon('search', 18) ?></button>
                </form>
                <div class="recent-searches-panel" id="recent-searches-panel"></div>
            </div>

            <div class="topbar-actions">
                <a href="/market/favoris" class="icon-btn" id="favorites-link" aria-label="Favoris">
                    <?= icon('heart', 20) ?>
                    <span class="badge" id="favorites-count" hidden>0</span>
                </a>
                <?php if ($loggedUser): ?>
                    <?php if ($loggedUser['is_admin']): ?>
                        <a href="/market/admin/index" class="link-muted topbar-hide-mobile">Admin</a>
                    <?php endif; ?>
                    <a href="/market/compte" class="link-muted">Bonjour, <?= e(explode(' ', $loggedUser['name'])[0]) ?></a>
                    <a href="/market/deconnexion" class="btn btn-outline-primary btn-sm topbar-hide-mobile">Se déconnecter</a>
                <?php else: ?>
                    <a href="/market/connexion" class="link-muted">Se connecter</a>
                    <a href="/market/inscription" class="btn btn-primary btn-sm">S'inscrire</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <nav class="mainnav">
        <div class="container mainnav-inner">
            <button type="button" class="mobile-menu-toggle" id="mobile-menu-toggle" aria-label="Ouvrir le menu" aria-expanded="false">
                <?= icon('menu', 20) ?>
            </button>

            <ul class="mainnav-links" id="mainnav-links">
                <li><a href="/market/index" class="<?= $currentPage === 'index.php' ? 'active' : '' ?>">Accueil</a></li>
                <li><a href="/market/boutiques" class="<?= $currentPage === 'boutiques.php' ? 'active' : '' ?>">Boutiques</a></li>
                <li><a href="/market/offres" class="<?= $currentPage === 'offres.php' ? 'active' : '' ?>">Offres</a></li>
                <li><a href="/market/services" class="<?= $currentPage === 'services.php' ? 'active' : '' ?>">Services</a></li>
                <li><a href="/market/vendeur/demande-boutique" class="<?= $currentPage === 'demande-boutique.php' ? 'active' : '' ?>">Devenir propriétaire de boutique</a></li>
                <li><a href="/market/commandes" class="<?= $currentPage === 'commandes.php' ? 'active' : '' ?>">Suivre ma commande</a></li>
                <li><a href="/market/actualites" class="<?= $currentPage === 'actualites.php' ? 'active' : '' ?>">Actualités</a></li>
                <li><a href="/market/contact" class="<?= $currentPage === 'contact.php' ? 'active' : '' ?>">Contact</a></li>
            </ul>

            <div class="mainnav-icons">
                <a href="/market/favoris" class="icon-btn" aria-label="Favoris"><?= icon('heart', 18) ?></a>
                <a href="/market/panier" class="icon-btn" aria-label="Panier">
                    <?= icon('cart', 18) ?>
                    <span class="badge" id="cart-count" hidden>0</span>
                </a>
                <a href="/market/compte" class="icon-btn" aria-label="Mon compte"><?= icon('user', 18) ?></a>
            </div>
        </div>
    </nav>
</header>

<main>
