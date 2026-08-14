<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/geniuspay.php';

$vendorUser = require_vendor();
$vendorShop = current_vendor_shop((int) $vendorUser['id']);

if (!$vendorShop) {
    header('Location: /market/vendeur/index.php');
    exit;
}

$db = get_db();
$shopId = (int) $vendorShop['id'];

$stmt = $db->prepare('SELECT * FROM subscription_payments WHERE shop_id = :shop_id ORDER BY id DESC LIMIT 1');
$stmt->execute(['shop_id' => $shopId]);
$payment = $stmt->fetch() ?: null;

if ($payment) {
    $check = geniuspay_get_payment($payment['reference']);
    if ($check['ok'] && isset($check['body']['data']['status'])) {
        $realStatus = (string) $check['body']['data']['status'];
        if ($realStatus !== $payment['status']) {
            $stmt = $db->prepare('UPDATE subscription_payments SET status = :status, payment_method = :method, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute([
                'status' => $realStatus,
                'method' => $check['body']['data']['payment_method'] ?? $payment['payment_method'],
                'id' => $payment['id'],
            ]);
            $payment['status'] = $realStatus;
        }
    }

    if ($payment['status'] === 'completed' && !$payment['applied']) {
        $stmt = $db->prepare('SELECT * FROM subscription_plans WHERE id = :id');
        $stmt->execute(['id' => $payment['plan_id']]);
        $plan = $stmt->fetch();

        if ($plan) {
            apply_subscription_payment($shopId, $plan, (int) $payment['amount']);
            $db->prepare('UPDATE subscription_payments SET applied = 1 WHERE id = :id')->execute(['id' => $payment['id']]);
        }
    }
}

$pageTitle = 'Statut du paiement';
require_once __DIR__ . '/../includes/vendor_header.php';
?>

<div class="card" style="max-width:640px;">
    <div class="admin-toolbar">
        <h2>Statut du paiement</h2>
    </div>

    <?php if (!$payment): ?>
        <p class="empty-state">Aucun paiement d'abonnement trouvé.</p>
        <a href="/market/vendeur/abonnements.php" class="btn btn-primary">Retour à mon abonnement</a>
    <?php elseif ($payment['status'] === 'completed'): ?>
        <div class="alert alert-success">
            <?= icon('check-circle', 18) ?>
            <span>Paiement confirmé ! Votre abonnement a été mis à jour.</span>
        </div>
        <a href="/market/vendeur/abonnements.php" class="btn btn-primary">Voir mon abonnement</a>
    <?php elseif (in_array($payment['status'], ['pending', 'processing'], true)): ?>
        <div class="alert alert-info">
            <?= icon('clock', 18) ?>
            <span>Votre paiement est en cours de vérification. Cela peut prendre quelques instants.</span>
        </div>
        <a href="/market/vendeur/abonnement-retour.php" class="btn btn-outline-primary">Actualiser le statut</a>
        <a href="/market/vendeur/abonnements.php" class="btn btn-primary">Voir mon abonnement</a>
    <?php else: ?>
        <div class="alert alert-error">
            <?= icon('x', 18) ?>
            <span><?= $payment['status'] === 'expired' ? 'Le lien de paiement a expiré.' : 'Le paiement a échoué ou a été annulé.' ?> Vous pouvez réessayer depuis la page abonnement.</span>
        </div>
        <a href="/market/vendeur/abonnements.php" class="btn btn-primary">Réessayer</a>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
