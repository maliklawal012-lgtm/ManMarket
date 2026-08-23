<?php
declare(strict_types=1);

$pageTitle = 'Statut du paiement';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/wallet_bootstrap.php';

$orderId = (int) ($_GET['order'] ?? 0);
$currentUser = current_user();
$order = $orderId > 0 ? wallet_order_repo()->findById($orderId) : null;
$ownedByUser = $order && $currentUser && (int) $order['customer_user_id'] === (int) $currentUser['id'];
$ownedByGuestSession = $order && in_array($orderId, $_SESSION['own_order_ids'] ?? [], true);
$payment = ($order && ($ownedByUser || $ownedByGuestSession)) ? wallet_payment_repo()->findByOrderId($orderId) : null;

if ($payment) {
    $realStatus = wallet_payment_service()->verifyAndSync($payment['provider_reference']);
    $payment['status'] = $realStatus;

    // Filet de securite : si le webhook Genius Pay n'est pas encore arrive mais que
    // la verification serveur->serveur confirme deja le paiement, on regle quand
    // meme la commande ici. settleOrder() est idempotent (voir OrderSettlementService).
    if ($realStatus === 'completed') {
        wallet_settlement_service()->settleOrder($orderId);
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <h1>Statut du paiement</h1>
    </div>
</section>

<section class="container auth-page">
    <div class="auth-page-stack">
        <div class="card auth-card">
            <?php if (!$payment): ?>
                <p class="empty-state">Aucun paiement trouvé pour cette commande.</p>
                <a href="/market/index" class="btn btn-primary">Retour à l'accueil</a>
            <?php elseif ($payment['status'] === 'completed'): ?>
                <div class="alert alert-success">
                    <?= icon('check-circle', 18) ?>
                    <span>Paiement confirmé ! Votre commande est en cours de préparation.</span>
                </div>
                <a href="/market/commandes" class="btn btn-primary">Voir mes commandes</a>
            <?php elseif (in_array($payment['status'], ['pending', 'processing'], true)): ?>
                <div class="alert alert-info">
                    <?= icon('clock', 18) ?>
                    <span>Votre paiement est en cours de vérification. Cela peut prendre quelques instants.</span>
                </div>
                <a href="/market/paiement-retour?order=<?= $orderId ?>" class="btn btn-outline-primary">Actualiser le statut</a>
                <a href="/market/commandes" class="btn btn-primary">Voir mes commandes</a>
            <?php else: ?>
                <div class="alert alert-error">
                    <?= icon('x', 18) ?>
                    <span><?= $payment['status'] === 'expired' ? 'Le lien de paiement a expiré.' : 'Le paiement a échoué ou a été annulé.' ?></span>
                </div>
                <a href="/market/commandes" class="btn btn-primary">Voir mes commandes</a>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
