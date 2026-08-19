<?php
declare(strict_types=1);

$pageTitle = 'Accès refusé';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="container not-found">
    <h1>Accès refusé</h1>
    <p>Cet espace est réservé aux comptes vendeurs. <a href="/market/inscription?type=vendeur">Faire une demande de compte vendeur</a></p>
    <a href="/market/compte" class="btn btn-primary">Retour à mon compte</a>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
