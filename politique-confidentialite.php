<?php
declare(strict_types=1);

$pageTitle = 'Politique de confidentialité';
require_once __DIR__ . '/includes/header.php';
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <h1>Politique de confidentialité</h1>
        <p>Dernière mise à jour : <?= e(date('d/m/Y', strtotime(PRIVACY_POLICY_VERSION))) ?></p>
    </div>
</section>

<section class="container auth-page">
    <div class="card auth-card" style="max-width:800px; margin:0 auto;">
        <h2>1. Qui collecte vos données ?</h2>
        <p><?= e(get_setting('site_name') ?: 'ManMarket') ?> est une place de marché en ligne qui met en relation des vendeurs de la ville de Man et leurs clients. Cette politique explique quelles données personnelles nous collectons lorsque vous créez un compte, passez commande ou utilisez le site, et comment elles sont utilisées.</p>

        <h2>2. Quelles données sont collectées ?</h2>
        <ul>
            <li>Identité : nom, adresse email, numéro de téléphone.</li>
            <li>Commandes : produits achetés, adresse de livraison, historique de commandes.</li>
            <li>Paiement : lorsque vous payez en ligne, les données de paiement (carte, Mobile Money) sont traitées directement par notre prestataire Genius Pay — nous ne stockons jamais votre numéro de carte ou vos identifiants de paiement.</li>
            <li>Connexion : adresse IP, journaux de connexion, à des fins de sécurité du compte.</li>
        </ul>

        <h2>3. Pourquoi ces données sont-elles utilisées ?</h2>
        <ul>
            <li>Créer et gérer votre compte client ou vendeur.</li>
            <li>Traiter vos commandes et organiser la livraison.</li>
            <li>Vous contacter au sujet d'une commande, d'un litige ou d'une demande de support.</li>
            <li>Assurer la sécurité du site (lutte contre la fraude, les abus et les accès non autorisés).</li>
            <li>Respecter nos obligations légales et comptables.</li>
        </ul>

        <h2>4. Partage des données</h2>
        <p>Vos données ne sont jamais vendues. Elles sont partagées uniquement avec : le vendeur concerné par votre commande (nom, contact, adresse de livraison), notre prestataire de paiement Genius Pay pour le traitement des paiements en ligne, et notre service de livraison pour l'acheminement de vos commandes.</p>

        <h2>5. Combien de temps vos données sont-elles conservées ?</h2>
        <p>Vos données sont conservées tant que votre compte est actif. En cas de suppression de compte, les données liées aux commandes passées peuvent être conservées le temps requis par nos obligations comptables et légales.</p>

        <h2>6. Vos droits</h2>
        <p>Vous pouvez à tout moment demander l'accès, la correction ou la suppression de vos données personnelles en nous contactant via la page <a href="/market/contact.php">Contact</a>.</p>

        <h2>7. Contact</h2>
        <p>Pour toute question relative à cette politique ou à vos données personnelles, contactez-nous via la page <a href="/market/contact.php">Contact</a>.</p>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
