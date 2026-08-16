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
    $old['payment_choice'] = ($_POST['payment_choice'] ?? 'cod') === 'online' ? 'online' : 'cod';
    $old['payment_method'] = trim((string) ($_POST['payment_method'] ?? ''));

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

    if ($old['payment_choice'] === 'online') {
        if (!isset($onlinePaymentMethods[$old['payment_method']])) {
            $errors['payment_method'] = 'Veuillez choisir un moyen de paiement.';
        } elseif ($orderTotal + $deliveryFee < 200) {
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
            $errors['items'] = 'Votre panier est vide ou aucun des produits sélectionnés n\'est actuellement disponible à la vente (boutique sans vendeur assigné). Merci de contacter le support.';
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

$grandTotal = $orderTotal + $deliveryFee;

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
                <span><?= e($errors['items']) ?></span>
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

                <div class="form-field">
                    <label for="phone">Téléphone (optionnel)</label>
                    <input type="tel" id="phone" name="phone" value="<?= e($old['phone']) ?>">
                </div>

                <div class="form-row" id="delivery-location-fields" data-neighborhoods="<?= e(json_encode($deliveryChildrenByParent, JSON_UNESCAPED_UNICODE)) ?>" data-subtotal="<?= (int) $orderTotal ?>">
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
                    <div class="payment-choice-options">
                        <label class="payment-choice-option">
                            <input type="radio" name="payment_choice" value="cod" <?= $old['payment_choice'] !== 'online' ? 'checked' : '' ?>>
                            <span><?= icon('truck', 16) ?> Paiement à la livraison</span>
                        </label>
                        <label class="payment-choice-option">
                            <input type="radio" name="payment_choice" value="online" <?= $old['payment_choice'] === 'online' ? 'checked' : '' ?>>
                            <span><?= icon('cart', 16) ?> Payer en ligne maintenant (Wave, Orange Money, MTN, carte...)</span>
                        </label>
                    </div>
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
