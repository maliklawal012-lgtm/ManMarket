<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$vendorUser = require_vendor();
$vendorShop = current_vendor_shop((int) $vendorUser['id']);

if (!$vendorShop) {
    require_once __DIR__ . '/header.php';
    ?>
    <section class="container not-found">
        <h1>Boutique en attente d'assignation</h1>
        <p>Votre compte vendeur est actif, mais aucune boutique ne vous a encore été assignée. Notre équipe vous contactera prochainement pour finaliser l'ouverture de votre boutique sur ManMarket.</p>
        <a href="/market/compte.php" class="btn btn-primary">Retour à mon compte</a>
    </section>
    <?php
    require_once __DIR__ . '/footer.php';
    exit;
}

$currentVendorPage = basename($_SERVER['SCRIPT_NAME']);

$stmt = get_db()->prepare("SELECT COUNT(DISTINCT order_id) FROM legacy_order_items WHERE shop_id = :shop_id AND vendor_status = 'pending'");
$stmt->execute(['shop_id' => (int) $vendorShop['id']]);
$pendingVendorOrders = (int) $stmt->fetchColumn();

$vendorNavItems = [
    ['key' => 'index.php', 'href' => '/market/vendeur/index.php', 'icon' => 'menu', 'label' => 'Tableau de bord'],
    ['key' => 'boutique.php', 'href' => '/market/vendeur/boutique.php', 'icon' => 'store', 'label' => 'Ma boutique'],
    ['key' => 'produits.php', 'href' => '/market/vendeur/produits.php', 'icon' => 'shopping-basket', 'label' => 'Produits'],
    ['key' => 'commandes.php', 'href' => '/market/vendeur/commandes.php', 'icon' => 'cart', 'label' => 'Commandes', 'badge' => $pendingVendorOrders],
    ['key' => 'clients.php', 'href' => '/market/vendeur/clients.php', 'icon' => 'user', 'label' => 'Clients'],
    ['key' => 'statistiques.php', 'href' => '/market/vendeur/statistiques.php', 'icon' => 'bar-chart', 'label' => 'Statistiques'],
    ['key' => 'promotions.php', 'href' => '/market/vendeur/promotions.php', 'icon' => 'zap', 'label' => 'Promotions'],
    ['key' => 'avis.php', 'href' => '/market/vendeur/avis.php', 'icon' => 'star-filled', 'label' => 'Avis & Notes'],
    ['key' => 'messages.php', 'href' => '/market/vendeur/messages.php', 'icon' => 'send', 'label' => 'Messages'],
    ['key' => 'livraisons.php', 'href' => '/market/vendeur/livraisons.php', 'icon' => 'truck', 'label' => 'Livraisons'],
    ['key' => 'finances.php', 'href' => '/market/vendeur/finances.php', 'icon' => 'check-circle', 'label' => 'Finances'],
    ['key' => 'commissions.php', 'href' => '/market/vendeur/commissions.php', 'icon' => 'shield', 'label' => 'Commissions'],
    ['key' => 'retraits.php', 'href' => '/market/vendeur/retraits.php', 'icon' => 'shield', 'label' => 'Retraits'],
    ['key' => 'abonnements.php', 'href' => '/market/vendeur/abonnements.php', 'icon' => 'clock', 'label' => 'Abonnement'],
    ['key' => 'parametres.php', 'href' => '/market/vendeur/parametres.php', 'icon' => 'settings', 'label' => 'Paramètres'],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? e($pageTitle) . ' | Espace vendeur ManMarket' : 'Espace vendeur ManMarket' ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 24 24%22><rect width=%2224%22 height=%2224%22 rx=%226%22 fill=%22%2316a34a%22/><text x=%2212%22 y=%2217%22 font-size=%2214%22 fill=%22white%22 text-anchor=%22middle%22 font-family=%22Arial%22>M</text></svg>">
<link rel="stylesheet" href="/market/assets/css/style.css">
</head>
<body class="admin-body">

<div class="admin-shell">
    <aside class="admin-sidebar" id="admin-sidebar">
        <a href="/market/vendeur/index.php" class="logo">
            <span class="logo-mark">M</span>
            <span class="logo-text light">Ma Boutique<small><?= e($vendorShop['name']) ?></small></span>
        </a>

        <nav class="admin-nav">
            <?php foreach ($vendorNavItems as $item): ?>
                <?php if ($item['href']): ?>
                    <a href="<?= e($item['href']) ?>" class="<?= $currentVendorPage === $item['key'] ? 'active' : '' ?>">
                        <?= icon($item['icon'], 17) ?> <?= e($item['label']) ?>
                        <?php if (!empty($item['badge'])): ?><span class="admin-nav-badge"><?= (int) $item['badge'] ?></span><?php endif; ?>
                    </a>
                <?php else: ?>
                    <span class="admin-nav-disabled">
                        <?= icon($item['icon'], 17) ?> <?= e($item['label']) ?>
                        <em>Bientôt</em>
                    </span>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <div class="admin-sidebar-footer">
            <a href="/market/boutique.php?slug=<?= urlencode((string) $vendorShop['slug']) ?>" class="link-muted-light"><?= icon('chevron-right', 14) ?> Voir ma boutique</a>
            <a href="/market/compte.php" class="link-muted-light">Mon compte</a>
            <a href="/market/deconnexion.php" class="link-muted-light">Déconnexion</a>
        </div>
    </aside>

    <div class="admin-main">
        <header class="admin-topbar">
            <button type="button" class="admin-mobile-toggle" id="admin-mobile-toggle" aria-label="Ouvrir le menu" aria-expanded="false">
                <?= icon('menu', 20) ?>
            </button>
            <h1><?= isset($pageTitle) ? e($pageTitle) : 'Espace vendeur' ?></h1>
            <div class="admin-user">
                <?php if (!empty($vendorUser['avatar'])): ?>
                    <img src="/market/<?= e($vendorUser['avatar']) ?>" alt="" class="admin-user-avatar admin-user-avatar-img">
                <?php else: ?>
                    <span class="admin-user-avatar"><?= e(mb_strtoupper(mb_substr($vendorUser['name'], 0, 1))) ?></span>
                <?php endif; ?>
                <span class="admin-user-info">
                    <strong><?= e($vendorUser['name']) ?></strong>
                    <small>Vendeur</small>
                </span>
            </div>
        </header>

        <main class="admin-content">
            <?php
            $currentVendorPage = $currentVendorPage ?? basename($_SERVER['SCRIPT_NAME']);
            if ($currentVendorPage !== 'abonnements.php') {
                $vendorSub = get_shop_latest_subscription((int) $vendorShop['id']);
                $todayDate = date('Y-m-d');
                $daysLeft = $vendorSub ? (int) floor((strtotime((string) $vendorSub['ends_at']) - strtotime($todayDate)) / 86400) : null;

                if (!$vendorSub || $vendorSub['ends_at'] < $todayDate): ?>
                    <div class="alert alert-error">
                        <?= icon('x', 18) ?>
                        <span>Votre boutique n'a pas d'abonnement actif : elle n'est plus visible sur le site public. <a href="/market/vendeur/abonnements.php" style="color:inherit; text-decoration:underline;">Voir comment renouveler</a></span>
                    </div>
                <?php elseif ($daysLeft !== null && $daysLeft <= 7): ?>
                    <div class="alert alert-warning">
                        <?= icon('clock', 18) ?>
                        <span>Votre abonnement expire dans <?= $daysLeft ?> jour<?= $daysLeft > 1 ? 's' : '' ?> (le <?= e(date('d/m/Y', strtotime((string) $vendorSub['ends_at']))) ?>). <a href="/market/vendeur/abonnements.php" style="color:inherit; text-decoration:underline;">Renouveler mon abonnement</a></span>
                    </div>
                <?php endif; ?>
            <?php } ?>
