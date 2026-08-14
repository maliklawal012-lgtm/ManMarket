<?php
declare(strict_types=1);

$pageTitle = 'Page introuvable';
http_response_code(404);
require_once __DIR__ . '/includes/header.php';
?>

<section class="container not-found">
    <h1>Page introuvable</h1>
    <p>Cette page n'existe pas ou a été déplacée.</p>
    <a href="/market/index.php" class="btn btn-primary">Retour à l'accueil</a>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
