<?php
declare(strict_types=1);

$pageTitle = 'Finaliser ma commande';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/wallet_bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$loggedInUser = current_user();
$errors = [];
$paymentInitError = null;
$onlinePaymentEnabled = get_setting('online_payment_enabled', '1') === '1';
// Si le paiement en ligne est desactive par l'admin (ex : incident Genius
// Pay), un client normalement restreint au paiement en ligne (2 non-retraits)
// ne doit pas se retrouver bloque sans aucune option valide.
$paymentRestricted = $onlinePaymentEnabled && $loggedInUser && (int) ($loggedInUser['payment_restricted'] ?? 0) === 1;

$onlinePaymentMethods = [
    'wave' => ['label' => 'Wave', 'logo' => 'assets/images/payment-logos/wave.svg'],
    'orange_money' => ['label' => 'Orange Money', 'logo' => 'assets/images/payment-logos/orange.svg'],
    'mtn_money' => ['label' => 'MTN Money', 'logo' => 'assets/images/payment-logos/mtn.svg'],
    'moov_money' => ['label' => 'Moov Money', 'logo' => null],
    'card' => ['label' => 'Carte bancaire', 'logo' => 'assets/images/payment-logos/mastercard.svg'],
];

$old = [
    'name' => $loggedInUser['name'] ?? '',
    'email' => $loggedInUser['email'] ?? '',
    'phone' => $loggedInUser['phone'] ?? '',
    'items' => trim((string) ($_GET['items'] ?? '')),
    'delivery_city' => 0,
    'delivery_neighborhood' => 0,
    'payment_choice' => 'cod',
    'payment_method' => '',
];
$deliveryFee = 0;
$onlinePaymentFeeRate = (float) get_setting('online_payment_fee_rate', '0.00');
$onlinePaymentFee = 0;

$deliveryCities = get_db()->query('SELECT * FROM locations WHERE parent_id IS NULL AND is_active = 1 ORDER BY sort_order, name')->fetchAll();
$deliveryChildrenByParent = [];
$deliveryChildrenRows = get_db()->query('SELECT * FROM locations WHERE parent_id IS NOT NULL AND is_active = 1 ORDER BY sort_order, name')->fetchAll();
foreach ($deliveryChildrenRows as $row) {
    $deliveryChildrenByParent[(int) $row['parent_id']][] = $row;
}

function commander_load_summary(string $itemsJson): array
{
    $rawItems = json_decode($itemsJson, true);
    if (!is_array($rawItems)) {
        return [];
    }

    $db = get_db();
    $productStmt = $db->prepare('
        SELECT p.*, s.name AS shop_name, s.is_open AS shop_is_open
        FROM products p JOIN shops s ON s.id = p.shop_id
        WHERE p.id = :id
    ');
    $sizeStmt = $db->prepare('SELECT stock FROM product_sizes WHERE product_id = :product_id AND size = :size');

    $summary = [];
    foreach (array_slice($rawItems, 0, 50) as $rawItem) {
        $productId = (int) ($rawItem['id'] ?? 0);
        $qty = (int) ($rawItem['qty'] ?? 0);
        $size = isset($rawItem['size']) ? (string) $rawItem['size'] : null;
        if ($productId <= 0 || $qty <= 0 || $qty > 99) {
            continue;
        }
        $productStmt->execute(['id' => $productId]);
        $product = $productStmt->fetch();
        if (!$product || !$product['shop_is_open'] || !get_shop_active_subscription((int) $product['shop_id'])) {
            continue;
        }

        $availableStock = (int) $product['stock'];
        if ($product['size_type'] !== 'none') {
            if ($size === null) {
                continue;
            }
            $sizeStmt->execute(['product_id' => $productId, 'size' => $size]);
            $sizeStock = $sizeStmt->fetchColumn();
            if ($sizeStock === false) {
                continue;
            }
            $availableStock = (int) $sizeStock;
        } else {
            $size = null;
        }

        $priceInfo = get_product_price($product);
        $summary[] = [
            'product' => $product,
            'qty' => min($qty, max(0, $availableStock)),
            'unit_price' => $priceInfo['price'],
            'size' => $size,
        ];
    }

    return array_filter($summary, fn ($row) => $row['qty'] > 0);
}

$summary = commander_load_summary($old['items']);
$orderTotal = 0;
foreach ($summary as $row) {
    $orderTotal += $row['unit_price'] * $row['qty'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['name'] = trim((string) ($_POST['name'] ?? ''));
    $old['email'] = trim((string) ($_POST['email'] ?? ''));
    $old['phone'] = trim((string) ($_POST['phone'] ?? ''));
    $old['items'] = trim((string) ($_POST['items'] ?? ''));
    $old['delivery_city'] = (int) ($_POST['delivery_city'] ?? 0);
    $old['delivery_neighborhood'] = (int) ($_POST['delivery_neighborhood'] ?? 0);
    $old['payment_choice'] = $onlinePaymentEnabled && ($_POST['payment_choice'] ?? 'cod') === 'online' ? 'online' : 'cod';
    $old['payment_method'] = trim((string) ($_POST['payment_method'] ?? ''));

    if ($paymentRestricted) {
        // Client restreint (2 non-retraits) : jamais confiance dans le choix
        // soumis, meme si le radio "a la livraison" a ete manipule/renvoye.
        $old['payment_choice'] = 'online';
    }

    $summary = commander_load_summary($old['items']);
    $orderTotal = 0;
    foreach ($summary as $row) {
        $orderTotal += $row['unit_price'] * $row['qty'];
    }

    if (!$summary) {
        $errors['items'] = 'Votre panier est vide ou les produits ne sont plus disponibles.';
    }
    if ($old['name'] === '') {
        $errors['name'] = 'Veuillez indiquer votre nom.';
    }
    if ($old['email'] === '' || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Veuillez indiquer une adresse email valide.';
    }
    if ($old['phone'] === '' || !validate_phone_number($old['phone'])) {
        $errors['phone'] = 'Veuillez indiquer un numéro de téléphone ivoirien valide (10 chiffres, ex : 07 00 00 00 00).';
    }

    $deliveryLocationText = null;
    $chosenCity = null;
    foreach ($deliveryCities as $c) {
        if ((int) $c['id'] === $old['delivery_city']) {
            $chosenCity = $c;
            break;
        }
    }
    if (!$chosenCity) {
        $errors['delivery_city'] = 'Veuillez choisir votre ville de livraison.';
    } else {
        $deliveryFee = (int) $chosenCity['delivery_fee'];
        $availableNeighborhoods = $deliveryChildrenByParent[(int) $chosenCity['id']] ?? [];
        $deliveryLocationText = $chosenCity['name'];
        if ($availableNeighborhoods) {
            $chosenNeighborhood = null;
            foreach ($availableNeighborhoods as $n) {
                if ((int) $n['id'] === $old['delivery_neighborhood']) {
                    $chosenNeighborhood = $n;
                    break;
                }
            }
            if (!$chosenNeighborhood) {
                $errors['delivery_neighborhood'] = 'Veuillez choisir votre quartier de livraison.';
            } else {
                $deliveryLocationText .= ' - ' . $chosenNeighborhood['name'];
                $deliveryFee = (int) $chosenNeighborhood['delivery_fee'];
            }
        }
    }

    $onlinePaymentFee = $old['payment_choice'] === 'online'
        ? (int) round(($orderTotal + $deliveryFee) * $onlinePaymentFeeRate / 100)
        : 0;

    if ($old['payment_choice'] === 'online') {
        if (!isset($onlinePaymentMethods[$old['payment_method']])) {
            $errors['payment_method'] = 'Veuillez choisir un moyen de paiement.';
        } elseif ($orderTotal + $deliveryFee + $onlinePaymentFee < 200) {
            $errors['payment_method'] = 'Le paiement en ligne nécessite un montant minimum de 200 FCFA. Choisissez "Paiement à la livraison" ou ajoutez un article.';
        }
    }

    if (!$errors) {
        $rawCartItems = json_decode($old['items'], true);
        $rawCartItems = is_array($rawCartItems) ? $rawCartItems : [];

        try {
            $orderResult = wallet_order_service()->createFromCart(
                $rawCartItems,
                [
                    'user_id' => $loggedInUser['id'] ?? null,
                    'name' => $old['name'],
                    'email' => $old['email'],
                    'phone' => $old['phone'] !== '' ? $old['phone'] : null,
                ],
                $deliveryLocationText,
                $old['payment_choice'],
                $deliveryFee
            );
        } catch (InvalidArgumentException $e) {
            $errors['items'] = 'Aucun des articles de votre panier n\'est actuellement disponible à la vente. Cela peut arriver si une boutique est en cours de configuration.';
            $errors['items_contact'] = true;
        }

        if (!$errors) {
            $orderId = $orderResult->orderId;
            $orderTotal = $orderResult->totalAmount;

            if ($old['payment_choice'] === 'online' && $orderTotal >= 200) {
                $baseUrl = site_base_url();
                $paymentResult = wallet_payment_service()->createForOrder($orderId, $orderTotal, [
                    'payment_method' => $old['payment_method'],
                    'description' => 'Commande #' . $orderId . ' - ManMarket',
                    'customer' => [
                        'name' => $old['name'],
                        'email' => $old['email'],
                        'phone' => $old['phone'] !== '' ? $old['phone'] : null,
                    ],
                    'success_url' => $baseUrl . '/paiement-retour.php?order=' . $orderId,
                    'error_url' => $baseUrl . '/paiement-retour.php?order=' . $orderId . '&result=error',
                    'metadata' => ['order_id' => $orderId],
                ]);

                if ($paymentResult->ok && $paymentResult->paymentUrl) {
                    header('Location: ' . $paymentResult->paymentUrl);
                    exit;
                }

                $paymentInitError = $paymentResult->error ?? 'Le paiement en ligne est momentanément indisponible.';
            }

            header('Location: /market/commande-confirmee.php?order=' . $orderId . ($paymentInitError ? '&payment_error=1' : ''));
            exit;
        }
    }
}

$grandTotal = $orderTotal + $deliveryFee + $onlinePaymentFee;

require_once __DIR__ . '/includes/header.php';
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <h1>Finaliser ma commande</h1>
        <p>Vérifiez votre commande, choisissez votre livraison et votre paiement.</p>
    </div>
</section>

<section class="container contact-page">

    <div class="card contact-form-card">
        <?php if (isset($errors['items'])): ?>
            <div class="alert alert-error">
                <?= icon('x', 18) ?>
                <span>
                    <?= e($errors['items']) ?>
                    <?php if (!empty($errors['items_contact'])): ?>
                        <a href="/market/panier.php">Modifier mon panier</a>
                        <?php $supportPhone = get_setting('site_phone'); $supportWhatsapp = get_setting('site_whatsapp'); ?>
                        <?php if ($supportPhone || $supportWhatsapp): ?>
                            ou contactez le support
                            <?php if ($supportPhone): ?>au <?= e($supportPhone) ?><?php endif; ?>
                            <?php if ($supportWhatsapp): ?><?= $supportPhone ? ' (' : '' ?>WhatsApp : <?= e($supportWhatsapp) ?><?= $supportPhone ? ')' : '' ?><?php endif; ?>.
                        <?php endif; ?>
                    <?php endif; ?>
                </span>
            </div>
        <?php endif; ?>

        <?php if (!$summary): ?>
            <p class="empty-state">Votre panier est vide. <a href="/market/offres.php">Découvrir les offres</a></p>
        <?php else: ?>
            <div class="card-header">
                <h2>Récapitulatif</h2>
            </div>
            <div class="order-items-list" style="margin-bottom: 20px;">
                <?php foreach ($summary as $row): $p = $row['product']; ?>
                    <div class="order-items-row">
                        <div class="order-item-left">
                            <div class="product-thumb"><?= product_thumb_html($p, 18) ?></div>
                            <span><span class="qty"><?= (int) $row['qty'] ?> x</span><?= e($p['name']) ?><?= $row['size'] ? ' — Taille : ' . e($row['size']) : '' ?> <span class="char-count">(<?= e($p['shop_name']) ?>)</span></span>
                        </div>
                        <span><?= format_price($row['unit_price'] * $row['qty']) ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="order-items-row">
                    <span>Sous-total</span>
                    <span id="order-subtotal-value"><?= format_price($orderTotal) ?></span>
                </div>
                <div class="order-items-row">
                    <span>Frais de livraison</span>
                    <span id="delivery-fee-value"><?= $deliveryFee > 0 ? format_price($deliveryFee) : 'Gratuit' ?></span>
                </div>
                <div class="order-items-row <?= $old['payment_choice'] === 'online' ? '' : 'is-hidden' ?>" id="online-fee-row">
                    <span>Frais de paiement en ligne</span>
                    <span id="online-fee-value"><?= $onlinePaymentFee > 0 ? format_price($onlinePaymentFee) : 'Gratuit' ?></span>
                </div>
                <div class="order-items-total">
                    <span>Total</span>
                    <span id="order-grand-total-value"><?= format_price($grandTotal) ?></span>
                </div>
            </div>

            <form method="post" action="/market/commander.php" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="items" value="<?= e($old['items']) ?>">

                <div class="form-row">
                    <div class="form-field <?= isset($errors['name']) ? 'has-error' : '' ?>">
                        <label for="name">Nom complet *</label>
                        <input type="text" id="name" name="name" value="<?= e($old['name']) ?>" required>
                        <?php if (isset($errors['name'])): ?><span class="field-error"><?= e($errors['name']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-field <?= isset($errors['email']) ? 'has-error' : '' ?>">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" value="<?= e($old['email']) ?>" required>
                        <?php if (isset($errors['email'])): ?><span class="field-error"><?= e($errors['email']) ?></span><?php endif; ?>
                    </div>
                </div>

                <div class="form-field <?= isset($errors['phone']) ? 'has-error' : '' ?>">
                    <label for="phone">Téléphone *</label>
                    <input type="tel" id="phone" name="phone" value="<?= e($old['phone']) ?>" placeholder="07 00 00 00 00" required>
                    <?php if (isset($errors['phone'])): ?><span class="field-error"><?= e($errors['phone']) ?></span><?php endif; ?>
                </div>

                <div class="form-row" id="delivery-location-fields" data-neighborhoods="<?= e(json_encode($deliveryChildrenByParent, JSON_UNESCAPED_UNICODE)) ?>" data-subtotal="<?= (int) $orderTotal ?>" data-online-fee-rate="<?= e((string) $onlinePaymentFeeRate) ?>">
                    <div class="form-field <?= isset($errors['delivery_city']) ? 'has-error' : '' ?>">
                        <label for="delivery_city">Ville de livraison *</label>
                        <select id="delivery_city" name="delivery_city">
                            <option value="">Choisir une ville...</option>
                            <?php foreach ($deliveryCities as $c): ?>
                                <option value="<?= (int) $c['id'] ?>" data-fee="<?= (int) $c['delivery_fee'] ?>" <?= $old['delivery_city'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['delivery_city'])): ?><span class="field-error"><?= e($errors['delivery_city']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-field <?= isset($errors['delivery_neighborhood']) ? 'has-error' : '' ?> <?= ($deliveryChildrenByParent[$old['delivery_city']] ?? null) ? '' : 'is-hidden' ?>" id="delivery-neighborhood-wrap">
                        <label for="delivery_neighborhood">Quartier *</label>
                        <select id="delivery_neighborhood" name="delivery_neighborhood">
                            <option value="">Choisir un quartier...</option>
                            <?php foreach (($deliveryChildrenByParent[$old['delivery_city']] ?? []) as $n): ?>
                                <option value="<?= (int) $n['id'] ?>" <?= $old['delivery_neighborhood'] === (int) $n['id'] ? 'selected' : '' ?>><?= e($n['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['delivery_neighborhood'])): ?><span class="field-error"><?= e($errors['delivery_neighborhood']) ?></span><?php endif; ?>
                    </div>
                </div>

                <div class="form-field">
                    <label>Mode de paiement</label>
                    <?php if ($paymentRestricted): ?>
                        <div class="alert alert-error">
                            <?= icon('x', 18) ?>
                            <span>Suite à des commandes non retirées, le paiement en ligne est requis pour cette commande.</span>
                        </div>
                    <?php endif; ?>
                    <div class="payment-choice-options">
                        <?php if (!$paymentRestricted): ?>
                            <label class="payment-choice-option">
                                <input type="radio" name="payment_choice" value="cod" <?= $old['payment_choice'] !== 'online' ? 'checked' : '' ?>>
                                <span><?= icon('truck', 16) ?> Paiement à la livraison</span>
                            </label>
                        <?php endif; ?>
                        <?php if ($onlinePaymentEnabled): ?>
                            <label class="payment-choice-option">
                                <input type="radio" name="payment_choice" value="online" <?= $old['payment_choice'] === 'online' ? 'checked' : '' ?>>
                                <span><?= icon('cart', 16) ?> Payer en ligne maintenant (Wave, Orange Money, MTN, carte...)</span>
                            </label>
                        <?php endif; ?>
                    </div>
                    <?php if (!$onlinePaymentEnabled): ?>
                        <span class="char-count">Le paiement en ligne est momentanément indisponible. Seul le paiement à la livraison est proposé.</span>
                    <?php endif; ?>
                </div>

                <div class="form-field <?= isset($errors['payment_method']) ? 'has-error' : '' ?> <?= $old['payment_choice'] === 'online' ? '' : 'is-hidden' ?>" id="payment-method-field">
                    <label>Choisissez votre moyen de paiement</label>
                    <div class="payment-choice-options payment-choice-options-grid">
                        <?php foreach ($onlinePaymentMethods as $methodKey => $method): ?>
                            <label class="payment-choice-option">
                                <input type="radio" name="payment_method" value="<?= e($methodKey) ?>" <?= $old['payment_method'] === $methodKey ? 'checked' : '' ?>>
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
                    <?php if (isset($errors['payment_method'])): ?><span class="field-error"><?= e($errors['payment_method']) ?></span><?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Confirmer ma commande <?= icon('chevron-right', 16) ?></button>
            </form>
        <?php endif; ?>
    </div>

</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
