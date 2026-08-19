<?php
declare(strict_types=1);

$pageTitle = 'Mes commandes';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/wallet_bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$db = get_db();

$onlinePaymentMethods = [
    'wave' => ['label' => 'Wave', 'logo' => 'assets/images/payment-logos/wave.svg'],
    'orange_money' => ['label' => 'Orange Money', 'logo' => 'assets/images/payment-logos/orange.svg'],
    'mtn_money' => ['label' => 'MTN Money', 'logo' => 'assets/images/payment-logos/mtn.svg'],
    'moov_money' => ['label' => 'Moov Money', 'logo' => null],
    'card' => ['label' => 'Carte bancaire', 'logo' => 'assets/images/payment-logos/mastercard.svg'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['order_id']) && $_POST['action'] === 'delete') {
    wallet_order_repo()->delete((int) $_POST['order_id'], (int) $user['id']);
    header('Location: /market/commandes');
    exit;
}

$retryError = null;
$retryOrderId = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['order_id']) && $_POST['action'] === 'retry_payment') {
    $retryOrderId = (int) $_POST['order_id'];
    $retryMethod = trim((string) ($_POST['payment_method'] ?? ''));

    $retryOrder = wallet_order_repo()->findById($retryOrderId);
    if ($retryOrder && (int) $retryOrder['customer_user_id'] !== (int) $user['id']) {
        $retryOrder = null;
    }

    $existingPayment = $retryOrder ? wallet_payment_repo()->findByOrderId($retryOrderId) : null;

    if (!$retryOrder || !$existingPayment || !in_array($existingPayment['status'], ['failed', 'cancelled', 'expired'], true)) {
        $retryError = "Cette commande n'est pas éligible à un nouvel essai de paiement.";
    } elseif (!isset($onlinePaymentMethods[$retryMethod])) {
        $retryError = 'Veuillez choisir un moyen de paiement.';
    } else {
        $retryTotal = (int) round((float) $retryOrder['total_amount']);

        if ($retryTotal < 200) {
            $retryError = 'Le montant de cette commande est inférieur au minimum requis pour le paiement en ligne (200 FCFA).';
        } else {
            $baseUrl = site_base_url();
            $paymentResult = wallet_payment_service()->createForOrder($retryOrderId, $retryTotal, [
                'payment_method' => $retryMethod,
                'description' => 'Commande #' . $retryOrderId . ' - ManMarket',
                'customer' => [
                    'name' => $retryOrder['customer_name'],
                    'email' => $retryOrder['customer_email'],
                    'phone' => $retryOrder['customer_phone'],
                ],
                'success_url' => $baseUrl . '/paiement-retour.php?order=' . $retryOrderId,
                'error_url' => $baseUrl . '/paiement-retour.php?order=' . $retryOrderId . '&result=error',
                'metadata' => ['order_id' => $retryOrderId],
            ]);

            if ($paymentResult->ok && $paymentResult->paymentUrl) {
                header('Location: ' . $paymentResult->paymentUrl);
                exit;
            }

            $retryError = $paymentResult->error ?? 'Le paiement en ligne est momentanément indisponible.';
        }
    }
}

$orders = wallet_order_repo()->findByCustomerUserId((int) $user['id']);

$itemsStmt = $db->prepare('
    SELECT oi.*, p.image AS product_image, p.icon AS product_icon
    FROM order_items oi
    LEFT JOIN products p ON p.id = oi.product_id
    WHERE oi.order_id = :order_id
    ORDER BY oi.id
');

require_once __DIR__ . '/includes/header.php';
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <h1>Mes commandes</h1>
        <p>Suivez ici l'avancement de vos commandes.</p>
    </div>
</section>

<section class="container auth-page">
    <div class="auth-page-stack auth-page-stack-wide">

        <?php if (!$orders): ?>
            <div class="card auth-card">
                <p class="empty-state">Vous n'avez encore passé aucune commande. <a href="/market/offres">Découvrir les offres</a></p>
            </div>
        <?php else: ?>
            <?php foreach ($orders as $order): ?>
                <?php
                $itemsStmt->execute(['order_id' => $order['id']]);
                $items = $itemsStmt->fetchAll();
                $payment = wallet_payment_repo()->findByOrderId((int) $order['id']);
                ?>
                <div class="card order-card">
                    <div class="order-card-header">
                        <span class="order-card-date-group">
                            <span class="order-card-date"><?= icon('clock', 14) ?> <?= e(date('d/m/Y à H:i', strtotime((string) $order['created_at']))) ?></span>
                            <span class="tag <?= order_status_tag_class($order['fulfillment_status']) ?>"><?= e(order_status_label($order['fulfillment_status'])) ?></span>
                            <?php if ($payment): ?>
                                <span class="tag <?= payment_status_tag_class($payment['status']) ?>"><?= icon('cart', 12) ?> <?= e(payment_status_label($payment['status'])) ?></span>
                            <?php endif; ?>
                        </span>
                        <form method="post" action="/market/commandes" class="order-delete-form" onsubmit="return confirm('Supprimer cette commande de votre historique ?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <button type="submit" class="order-delete-btn" aria-label="Supprimer cette commande"><?= icon('x', 16) ?></button>
                        </form>
                    </div>

                    <?= order_tracker_html($order['fulfillment_status']) ?>

                    <?php if (!empty($order['delivery_location'])): ?>
                        <p class="order-card-message"><?= icon('map-pin', 14) ?> <strong>Lieu de livraison :</strong> <?= e($order['delivery_location']) ?></p>
                    <?php endif; ?>

                    <?php if ($items): ?>
                        <div class="order-items-list">
                            <?php foreach ($items as $item): ?>
                                <div class="order-items-row">
                                    <div class="order-item-left">
                                        <div class="product-thumb"><?= product_thumb_html(['name' => $item['product_name'], 'image' => $item['product_image'], 'icon' => $item['product_icon'] ?: 'shopping-basket'], 18) ?></div>
                                        <span>
                                            <span class="qty"><?= (int) $item['quantity'] ?> x</span><?= e($item['product_name']) ?><?= $item['size'] ? ' — Taille : ' . e($item['size']) : '' ?>
                                            <?php if ($item['fulfillment_status'] !== 'pending'): ?>
                                                <span class="tag <?= vendor_item_status_tag_class($item['fulfillment_status']) ?>" style="margin-left:6px;"><?= e(vendor_item_status_label($item['fulfillment_status'])) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
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

                    <?php if ($payment && in_array($payment['status'], ['failed', 'cancelled', 'expired'], true)): ?>
                        <div class="retry-payment-box">
                            <?php if ($retryOrderId === (int) $order['id'] && $retryError): ?>
                                <div class="alert alert-error"><?= icon('x', 16) ?><span><?= e($retryError) ?></span></div>
                            <?php endif; ?>
                            <form method="post" action="/market/commandes#order-<?= (int) $order['id'] ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="retry_payment">
                                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                <p class="char-count">Le paiement précédent n'a pas abouti. Réessayez avec un moyen de paiement :</p>
                                <div class="payment-choice-options payment-choice-options-grid">
                                    <?php foreach ($onlinePaymentMethods as $methodKey => $method): ?>
                                        <label class="payment-choice-option">
                                            <input type="radio" name="payment_method" value="<?= e($methodKey) ?>" required>
                                            <span>
                                                <?php if ($method['logo']): ?>
                                                    <img src="/market/<?= e($method['logo']) ?>" alt="" class="payment-method-logo">
                                                <?php else: ?>
                                                    <?= icon('cart', 16) ?>
                                                <?php endif; ?>
                                                <?= e($method['label']) ?>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm" style="margin-top:10px;">Réessayer le paiement</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
