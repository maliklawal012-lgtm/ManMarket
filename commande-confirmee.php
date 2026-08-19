<?php
declare(strict_types=1);

$pageTitle = 'Commande confirmée';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/wallet_bootstrap.php';

$orderId = (int) ($_GET['order'] ?? 0);
$paymentError = isset($_GET['payment_error']);

$order = null;
$items = [];
if ($orderId > 0) {
    $order = wallet_order_repo()->findById($orderId);

    if ($order) {
        $items = wallet_order_item_repo()->findByOrderId($orderId);
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <h1>Merci pour votre commande !</h1>
    </div>
</section>

<section class="container auth-page">
    <div class="auth-page-stack">
        <div class="card auth-card">
            <?php if (!$order): ?>
                <p class="empty-state">Commande introuvable.</p>
                <a href="/market/index" class="btn btn-primary">Retour à l'accueil</a>
            <?php else: ?>
                <div class="alert alert-success">
                    <?= icon('check-circle', 18) ?>
                    <span>Votre commande a bien été enregistrée. Elle sera traitée très rapidement.</span>
                </div>

                <?php if ($paymentError): ?>
                    <div class="alert alert-error">
                        <?= icon('x', 18) ?>
                        <span>Le paiement en ligne n'a pas pu être initié. Vous serez contacté(e) pour un paiement à la livraison, ou vous pouvez réessayer depuis "Suivre ma commande".</span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($order['delivery_location'])): ?>
                    <p class="order-card-message"><?= icon('map-pin', 14) ?> <strong>Lieu de livraison :</strong> <?= e($order['delivery_location']) ?></p>
                <?php endif; ?>

                <?php if ($items): ?>
                    <div class="order-items-list">
                        <?php foreach ($items as $item): ?>
                            <div class="order-items-row">
                                <span><span class="qty"><?= (int) $item['quantity'] ?> x</span><?= e($item['product_name']) ?><?= $item['size'] ? ' — Taille : ' . e($item['size']) : '' ?></span>
                                <span><?= format_price((int) $item['unit_price'] * (int) $item['quantity']) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <div class="order-items-row">
                            <span>Sous-total</span>
                            <span><?= format_price((int) round((float) $order['subtotal_amount'])) ?></span>
                        </div>
                        <div class="order-items-row">
                            <span>Frais de livraison</span>
                            <span><?= (float) $order['delivery_fee_amount'] > 0 ? format_price((int) round((float) $order['delivery_fee_amount'])) : 'Gratuit' ?></span>
                        </div>
                        <?php if ((float) $order['online_payment_fee_amount'] > 0): ?>
                            <div class="order-items-row">
                                <span>Frais de paiement en ligne</span>
                                <span><?= format_price((int) round((float) $order['online_payment_fee_amount'])) ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="order-items-total">
                            <span>Total</span>
                            <span><?= format_price((int) round((float) $order['total_amount'])) ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <script type="application/json" id="clear-cart-ids"><?= json_encode(array_map(fn ($i) => 'product-' . (int) $i['product_id'], $items), JSON_UNESCAPED_SLASHES) ?></script>

                <a href="/market/commandes" class="btn btn-primary" style="margin-top:16px;">Suivre ma commande</a>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
