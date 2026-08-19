<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/geniuspay.php';
require_once __DIR__ . '/../includes/csrf.php';

$vendorUser = require_vendor();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}
$vendorShop = current_vendor_shop((int) $vendorUser['id']);

if (!$vendorShop) {
    header('Location: /market/vendeur/index');
    exit;
}

$db = get_db();
$shopId = (int) $vendorShop['id'];
$payErrors = [];

$onlinePaymentMethods = [
    'wave' => ['label' => 'Wave', 'logo' => 'assets/images/payment-logos/wave.svg'],
    'orange_money' => ['label' => 'Orange Money', 'logo' => 'assets/images/payment-logos/orange.svg'],
    'mtn_money' => ['label' => 'MTN Money', 'logo' => 'assets/images/payment-logos/mtn.svg'],
    'moov_money' => ['label' => 'Moov Money', 'logo' => null],
    'card' => ['label' => 'Carte bancaire', 'logo' => 'assets/images/payment-logos/mastercard.svg'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay_online') {
    $planId = (int) ($_POST['plan_id'] ?? 0);
    $paymentMethod = trim((string) ($_POST['payment_method'] ?? ''));

    $stmt = $db->prepare('SELECT * FROM subscription_plans WHERE id = :id AND is_active = 1');
    $stmt->execute(['id' => $planId]);
    $plan = $stmt->fetch();

    if (!$plan) {
        $payErrors['plan_id'] = 'Veuillez choisir un plan valide.';
    }
    if (!isset($onlinePaymentMethods[$paymentMethod])) {
        $payErrors['payment_method'] = 'Veuillez choisir un moyen de paiement.';
    }

    if (!$payErrors) {
        $baseUrl = site_base_url();
        $paymentResult = geniuspay_create_payment([
            'amount' => (int) $plan['price'],
            'payment_method' => $paymentMethod,
            'description' => 'Abonnement ' . $plan['name'] . ' - ' . $vendorShop['name'],
            'customer' => [
                'name' => $vendorUser['name'],
                'email' => $vendorUser['email'],
                'phone' => $vendorUser['phone'],
            ],
            'success_url' => $baseUrl . '/vendeur/abonnement-retour.php',
            'error_url' => $baseUrl . '/vendeur/abonnement-retour.php?result=error',
            'metadata' => ['shop_id' => $shopId, 'plan_id' => $plan['id']],
        ]);

        if ($paymentResult['ok'] && !empty($paymentResult['body']['data']['reference']) && !empty($paymentResult['body']['data']['payment_url'])) {
            $paymentData = $paymentResult['body']['data'];
            $stmt = $db->prepare('
                INSERT INTO subscription_payments (shop_id, plan_id, reference, amount, currency, status, environment, payment_url)
                VALUES (:shop_id, :plan_id, :reference, :amount, :currency, :status, :environment, :payment_url)
            ');
            $stmt->execute([
                'shop_id' => $shopId,
                'plan_id' => $plan['id'],
                'reference' => $paymentData['reference'],
                'amount' => (int) $plan['price'],
                'currency' => $paymentData['currency'] ?? 'XOF',
                'status' => $paymentData['status'] ?? 'pending',
                'environment' => $paymentData['environment'] ?? GENIUSPAY_MODE,
                'payment_url' => $paymentData['payment_url'],
            ]);

            header('Location: ' . $paymentData['payment_url']);
            exit;
        }

        $payErrors['payment_method'] = $paymentResult['error'] ?? 'Le paiement en ligne est momentanément indisponible. Réessayez ou contactez ManMarket.';
    }
}

$pageTitle = 'Abonnement';
require_once __DIR__ . '/../includes/vendor_header.php';

$current = get_shop_latest_subscription($shopId);
$today = date('Y-m-d');
$isActive = $current && $current['ends_at'] >= $today;

$stmt = $db->prepare('SELECT * FROM shop_subscriptions WHERE shop_id = :id ORDER BY starts_at DESC');
$stmt->execute(['id' => $shopId]);
$history = $stmt->fetchAll();

$stmt = $db->prepare('
    SELECT sp.*, pl.name AS plan_name
    FROM subscription_payments sp
    JOIN subscription_plans pl ON pl.id = sp.plan_id
    WHERE sp.shop_id = :id
    ORDER BY sp.id DESC
    LIMIT 10
');
$stmt->execute(['id' => $shopId]);
$paymentHistory = $stmt->fetchAll();

$plans = $db->query('SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY sort_order, duration_months')->fetchAll();

$whatsapp = get_setting('site_whatsapp');
$phone = get_setting('site_phone');
?>

<div class="card" style="max-width:640px;">
    <div class="admin-toolbar">
        <h2>Statut de mon abonnement</h2>
    </div>

    <?php if (!$current): ?>
        <div class="alert alert-error"><?= icon('x', 18) ?><span>Votre boutique n'a pas encore d'abonnement actif. Elle n'est donc pas visible sur le site public.</span></div>
    <?php elseif ($isActive): ?>
        <div class="alert alert-success"><?= icon('check-circle', 18) ?><span>Abonnement actif — <?= e($current['plan_name']) ?>, jusqu'au <?= e(date('d/m/Y', strtotime((string) $current['ends_at']))) ?>.</span></div>
    <?php else: ?>
        <div class="alert alert-error"><?= icon('x', 18) ?><span>Votre abonnement a expiré le <?= e(date('d/m/Y', strtotime((string) $current['ends_at']))) ?>. Votre boutique n'est plus visible sur le site public tant qu'il n'est pas renouvelé.</span></div>
    <?php endif; ?>
</div>

<div class="card" style="max-width:640px;">
    <div class="admin-toolbar">
        <h2><?= $isActive ? 'Renouveler mon abonnement' : "S'abonner / Renouveler" ?></h2>
    </div>

    <?php if (!$plans): ?>
        <p class="empty-state">Aucun plan disponible pour le moment. Contactez l'équipe ManMarket.</p>
    <?php else: ?>
        <form method="post" action="/market/vendeur/abonnements" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="pay_online">

            <div class="form-field <?= isset($payErrors['plan_id']) ? 'has-error' : '' ?>">
                <label>Choisissez un plan</label>
                <div class="payment-choice-options">
                    <?php foreach ($plans as $plan): ?>
                        <label class="payment-choice-option">
                            <input type="radio" name="plan_id" value="<?= (int) $plan['id'] ?>" required>
                            <span><?= e($plan['name']) ?> (<?= (int) $plan['duration_months'] ?> mois) — <strong><?= format_price((int) $plan['price']) ?></strong></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php if (isset($payErrors['plan_id'])): ?><span class="field-error"><?= e($payErrors['plan_id']) ?></span><?php endif; ?>
            </div>

            <div class="form-field <?= isset($payErrors['payment_method']) ? 'has-error' : '' ?>">
                <label>Moyen de paiement</label>
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
                <?php if (isset($payErrors['payment_method'])): ?><span class="field-error"><?= e($payErrors['payment_method']) ?></span><?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary">Payer maintenant <?= icon('chevron-right', 16) ?></button>
        </form>
    <?php endif; ?>

    <p class="char-count" style="margin-top:14px;">
        Vous préférez payer autrement (espèces, dépôt direct...) ? Contactez l'équipe ManMarket
        (<?= e($phone) ?><?php if ($whatsapp): ?>, WhatsApp <?= e($whatsapp) ?><?php endif; ?>).
    </p>
</div>

<?php if ($paymentHistory): ?>
    <div class="card" style="max-width:640px;">
        <div class="admin-toolbar">
            <h2>Paiements en ligne</h2>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Plan</th>
                        <th>Montant</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($paymentHistory as $p): ?>
                        <tr>
                            <td><?= e(date('d/m/Y H:i', strtotime((string) $p['created_at']))) ?></td>
                            <td><?= e($p['plan_name']) ?></td>
                            <td><?= format_price((int) $p['amount']) ?></td>
                            <td><span class="tag <?= payment_status_tag_class($p['status']) ?>"><?= e(payment_status_label($p['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="card" style="max-width:640px;">
    <div class="admin-toolbar">
        <h2>Historique des abonnements</h2>
    </div>

    <?php if (!$history): ?>
        <p class="empty-state">Aucun abonnement enregistré pour le moment.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Plan</th>
                        <th>Montant payé</th>
                        <th>Du</th>
                        <th>Au</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $sub): ?>
                        <tr>
                            <td><?= e($sub['plan_name']) ?></td>
                            <td><?= format_price((int) $sub['price_paid']) ?></td>
                            <td><?= e(date('d/m/Y', strtotime((string) $sub['starts_at']))) ?></td>
                            <td><?= e(date('d/m/Y', strtotime((string) $sub['ends_at']))) ?></td>
                            <td><a href="/market/vendeur/abonnement-detail?id=<?= (int) $sub['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
