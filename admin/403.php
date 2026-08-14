<?php
declare(strict_types=1);

$pageTitle = 'Accès refusé';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="container not-found">
    <h1>Accès refusé</h1>
    <p>Vous n'avez pas les droits nécessaires pour accéder à l'espace d'administration.</p>
    <a href="/market/index.php" class="btn btn-primary">Retour à l'accueil</a>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
