<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$adminUser = require_admin();
$currentAdminPage = basename($_SERVER['SCRIPT_NAME']);
$currentAdminKey = $currentAdminPage === 'utilisateurs.php' && ($_GET['type'] ?? '') === 'vendeur'
    ? 'utilisateurs.php:vendeur'
    : $currentAdminPage;

$adminNavGroups = [
    [
        'label' => null,
        'items' => [
            ['key' => 'index.php', 'href' => '/market/admin/index.php', 'icon' => 'menu', 'label' => 'Tableau de bord'],
        ],
    ],
    [
        'label' => 'Utilisateurs & boutiques',
        'items' => [
            ['key' => 'utilisateurs.php', 'href' => '/market/admin/utilisateurs.php', 'icon' => 'user', 'label' => 'Utilisateurs'],
            ['key' => 'utilisateurs.php:vendeur', 'href' => '/market/admin/utilisateurs.php?type=vendeur', 'icon' => 'store', 'label' => 'Commerçants'],
            ['key' => 'boutiques.php', 'href' => '/market/admin/boutiques.php', 'icon' => 'building-2', 'label' => 'Boutiques'],
        ],
    ],
    [
        'label' => 'Catalogue',
        'items' => [
            ['key' => 'produits.php', 'href' => '/market/admin/produits.php', 'icon' => 'shopping-basket', 'label' => 'Produits'],
            ['key' => 'categories.php', 'href' => '/market/admin/categories.php', 'icon' => 'menu', 'label' => 'Catégories'],
            ['key' => 'promotions.php', 'href' => '/market/admin/promotions.php', 'icon' => 'zap', 'label' => 'Promotions'],
            ['key' => 'publicites.php', 'href' => '/market/admin/publicites.php', 'icon' => 'send', 'label' => 'Publicités'],
            ['key' => 'actualites.php', 'href' => '/market/admin/actualites.php', 'icon' => 'calendar', 'label' => 'Actualités & Événements'],
        ],
    ],
    [
        'label' => 'Ventes',
        'items' => [
            ['key' => 'commandes-actives.php', 'href' => '/market/admin/commandes-actives.php', 'icon' => 'cart', 'label' => 'Commandes'],
            ['key' => 'commandes.php', 'href' => '/market/admin/commandes.php', 'icon' => 'cart', 'label' => 'Commandes (archive)'],
            ['key' => 'livraisons.php', 'href' => '/market/admin/livraisons.php', 'icon' => 'truck', 'label' => 'Livraisons (archive)'],
            ['key' => 'localites.php', 'href' => '/market/admin/localites.php', 'icon' => 'building-2', 'label' => 'Lieux de livraison'],
            ['key' => 'abonnements.php', 'href' => '/market/admin/abonnements.php', 'icon' => 'clock', 'label' => 'Abonnements boutiques'],
            ['key' => 'finances.php', 'href' => '/market/admin/finances.php', 'icon' => 'bar-chart', 'label' => 'Finances & Wallets'],
            ['key' => 'commissions.php', 'href' => '/market/admin/commissions.php', 'icon' => 'shield', 'label' => 'Commissions'],
            ['key' => 'retraits.php', 'href' => '/market/admin/retraits.php', 'icon' => 'shield', 'label' => 'Retraits'],
        ],
    ],
    [
        'label' => 'Support',
        'items' => [
            ['key' => 'messages.php', 'href' => '/market/admin/messages.php', 'icon' => 'send', 'label' => 'Messages'],
            ['key' => 'avis.php', 'href' => '/market/admin/avis.php', 'icon' => 'star-filled', 'label' => 'Avis & Commentaires'],
            ['key' => 'reclamations.php', 'href' => '/market/admin/reclamations.php', 'icon' => 'headset', 'label' => 'Réclamations'],
        ],
    ],
    [
        'label' => 'Plateforme',
        'items' => [
            ['key' => 'statistiques.php', 'href' => '/market/admin/statistiques.php', 'icon' => 'bar-chart', 'label' => 'Rapports & Statistiques'],
            ['key' => 'activite.php', 'href' => '/market/admin/activite.php', 'icon' => 'clock', 'label' => "Journal d'activité"],
            ['key' => 'parametres.php', 'href' => '/market/admin/parametres.php', 'icon' => 'settings', 'label' => 'Paramètres'],
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? e($pageTitle) . ' | Admin ManMarket' : 'Administration ManMarket' ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 24 24%22><rect width=%2224%22 height=%2224%22 rx=%226%22 fill=%22%2316a34a%22/><text x=%2212%22 y=%2217%22 font-size=%2214%22 fill=%22white%22 text-anchor=%22middle%22 font-family=%22Arial%22>M</text></svg>">
<link rel="stylesheet" href="/market/assets/css/style.css">
</head>
<body class="admin-body">

<div class="admin-shell">
    <aside class="admin-sidebar" id="admin-sidebar">
        <a href="/market/admin/index.php" class="logo">
            <span class="logo-mark">M</span>
            <span class="logo-text light">ManMarket<small>Administration</small></span>
        </a>

        <nav class="admin-nav">
            <?php foreach ($adminNavGroups as $group): ?>
                <?php if ($group['label']): ?><span class="admin-nav-label"><?= e($group['label']) ?></span><?php endif; ?>
                <?php foreach ($group['items'] as $item): ?>
                    <?php if ($item['href']): ?>
                        <a href="<?= e($item['href']) ?>" class="<?= $currentAdminKey === $item['key'] ? 'active' : '' ?>">
                            <?= icon($item['icon'], 17) ?> <?= e($item['label']) ?>
                        </a>
                    <?php else: ?>
                        <span class="admin-nav-disabled">
                            <?= icon($item['icon'], 17) ?> <?= e($item['label']) ?>
                            <em>Bientôt</em>
                        </span>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>

        <div class="admin-sidebar-footer">
            <a href="/market/index.php" class="link-muted-light"><?= icon('chevron-right', 14) ?> Voir le site</a>
            <a href="/market/deconnexion.php" class="link-muted-light">Déconnexion</a>
        </div>
    </aside>

    <div class="admin-main">
        <header class="admin-topbar">
            <button type="button" class="admin-mobile-toggle" id="admin-mobile-toggle" aria-label="Ouvrir le menu" aria-expanded="false">
                <?= icon('menu', 20) ?>
            </button>
            <h1><?= isset($pageTitle) ? e($pageTitle) : 'Administration' ?></h1>
            <div class="admin-user">
                <?php if (!empty($adminUser['avatar'])): ?>
                    <img src="/market/<?= e($adminUser['avatar']) ?>" alt="" class="admin-user-avatar admin-user-avatar-img">
                <?php else: ?>
                    <span class="admin-user-avatar"><?= e(mb_strtoupper(mb_substr($adminUser['name'], 0, 1))) ?></span>
                <?php endif; ?>
                <span class="admin-user-info">
                    <strong><?= e($adminUser['name']) ?></strong>
                    <small>Administrateur</small>
                </span>
            </div>
        </header>

        <main class="admin-content">
