<?php
declare(strict_types=1);

$pageTitle = 'Services';
require_once __DIR__ . '/includes/header.php';

$services = [
    ['icon' => 'truck', 'title' => 'Livraison express', 'text' => 'Recevez vos commandes le jour même, partout dans la ville de Man.'],
    ['icon' => 'shield', 'title' => 'Paiement sécurisé', 'text' => 'Mobile Money, carte bancaire ou paiement à la livraison, en toute confiance.'],
    ['icon' => 'headset', 'title' => 'Support 24/7', 'text' => 'Une équipe disponible à tout moment pour répondre à vos questions.'],
    ['icon' => 'check-circle', 'title' => 'Produits locaux garantis', 'text' => 'Des vendeurs vérifiés de Man et des produits de qualité.'],
    ['icon' => 'refresh', 'title' => 'Retours faciles', 'text' => 'Un produit ne convient pas ? Retournez-le simplement auprès du vendeur.'],
    ['icon' => 'store', 'title' => 'Devenir vendeur', 'text' => 'Ouvrez votre boutique sur ManMarket et vendez à des milliers de clients.'],
];

$steps = [
    ['icon' => 'search', 'title' => 'Parcourez le catalogue', 'text' => 'Explorez les boutiques et catégories de produits disponibles à Man.'],
    ['icon' => 'cart', 'title' => 'Commandez en ligne', 'text' => 'Ajoutez vos articles au panier et validez votre commande en quelques clics.'],
    ['icon' => 'truck', 'title' => 'Recevez à domicile', 'text' => 'Un livreur vous apporte votre commande, où que vous soyez à Man.'],
];

$faq = [
    ['q' => 'Comment passer une commande sur ManMarket ?', 'a' => 'Parcourez les boutiques ou les catégories, ajoutez les produits souhaités à votre panier, puis validez votre commande en renseignant votre adresse et votre moyen de paiement.'],
    ['q' => 'Quels sont les moyens de paiement acceptés ?', 'a' => 'Vous pouvez payer par Mobile Money, par carte bancaire, ou directement en espèces à la livraison, selon la boutique.'],
    ['q' => 'Quels sont les délais de livraison ?', 'a' => 'La plupart des boutiques proposent une livraison le jour même à Man. Le délai exact est indiqué sur la fiche de chaque boutique.'],
    ['q' => 'Comment devenir vendeur partenaire ?', 'a' => 'Cliquez sur "Devenir vendeur" ci-dessus et créez votre compte vendeur pour commencer à vendre vos produits sur ManMarket.'],
    ['q' => 'Comment contacter le support client ?', 'a' => 'Notre équipe est disponible 24/7 via la page Contact ou par téléphone au +225 07 00 00 00 00.'],
];
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <h1>Nos services à Man</h1>
        <p>Tout ce qu'il faut savoir pour acheter, vous faire livrer et vendre en toute simplicité.</p>
    </div>
</section>

<section class="container services-page">

    <div class="services-grid">
        <?php foreach ($services as $s): ?>
            <div class="card service-card">
                <span class="service-icon"><?= icon($s['icon'], 24) ?></span>
                <h3><?= e($s['title']) ?></h3>
                <p><?= e($s['text']) ?></p>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card steps-card">
        <div class="card-header">
            <h2>Comment ça marche</h2>
        </div>
        <div class="steps-grid">
            <?php foreach ($steps as $i => $step): ?>
                <div class="step-item">
                    <span class="step-number"><?= $i + 1 ?></span>
                    <span class="step-icon"><?= icon($step['icon'], 22) ?></span>
                    <h3><?= e($step['title']) ?></h3>
                    <p><?= e($step['text']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card banner-delivery merchant-banner">
        <div class="banner-text">
            <h2>Vous êtes vendeur à Man ?</h2>
            <p>Rejoignez ManMarket et vendez vos produits à des milliers de clients de la ville.</p>
            <a href="/market/inscription?type=vendeur" class="btn btn-white">Devenir vendeur</a>
        </div>
        <div class="banner-illustration"><?= icon('store', 40) ?></div>
    </div>

    <div class="card faq-card">
        <div class="card-header">
            <h2>Questions fréquentes</h2>
        </div>
        <div class="faq-list" id="faq-list">
            <?php foreach ($faq as $item): ?>
                <div class="faq-item">
                    <button type="button" class="faq-question">
                        <span><?= e($item['q']) ?></span>
                        <?= icon('plus', 16) ?>
                    </button>
                    <div class="faq-answer">
                        <p><?= e($item['a']) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
