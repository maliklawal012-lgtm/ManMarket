<?php
declare(strict_types=1);

$pageTitle = 'Compte suspendu';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="container not-found">
    <h1>Compte vendeur suspendu</h1>
    <p>Votre compte vendeur a été suspendu par l'équipe ManMarket.<?php if (!empty($suspendedReason)): ?> Motif : <?= e($suspendedReason) ?><?php endif; ?></p>
    <p>Contactez l'équipe ManMarket si vous pensez qu'il s'agit d'une erreur.</p>
    <a href="/market/compte" class="btn btn-primary">Retour à mon compte</a>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
