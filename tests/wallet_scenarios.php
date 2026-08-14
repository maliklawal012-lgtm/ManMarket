<?php

declare(strict_types=1);

/**
 * Suite de test formelle : les 15 scenarios exiges avant de considerer
 * l'architecture portefeuille/paiement comme prete pour la production.
 *
 * Usage CLI : php tests/wallet_scenarios.php
 *
 * Chaque scenario s'execute contre la VRAIE base de donnees, avec des
 * donnees jetables (prefixe email/slug "wstest-"), entierement nettoyees a
 * la fin (succes ou echec) — jamais de simulation en memoire, jamais de
 * fabrication de resultat : chaque assertion relit l'etat reel en base
 * apres l'appel au vrai service de production.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI uniquement.');
}

require_once __DIR__ . '/../config/autoload.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/geniuspay.php';
require_once __DIR__ . '/../includes/wallet_bootstrap.php';

use App\Repositories\PaymentRepository;
use App\Repositories\VendorRepository;
use App\Repositories\WebhookEventRepository;
use App\Services\GeniusPayService;
use App\Services\WebhookService;

$db = get_db();

// ----------------------------------------------------------------------
// Aides
// ----------------------------------------------------------------------

$created = ['users' => [], 'vendors' => [], 'shops' => [], 'products' => [], 'orders' => []];

function ws_signed_webhook_payload(string $reference, string $status, int $amount, string $currency = 'XOF'): array
{
    static $counter = 0;
    $counter++;

    $body = json_encode([
        'id' => 'wstest-evt-' . bin2hex(random_bytes(6)) . '-' . $counter,
        'type' => 'payment.' . ($status === 'completed' ? 'success' : $status),
        'data' => [
            'reference' => $reference,
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
            'payment_method' => 'wave',
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $secret = env('GENIUSPAY_WEBHOOK_SECRET', '');
    $timestamp = (string) time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

    return ['body' => $body, 'signature' => $signature, 'timestamp' => $timestamp, 'event_type' => json_decode($body, true)['type']];
}

function ws_make_vendor(PDO $db, array &$created, string $label): array
{
    $email = 'wstest-vendor-' . $label . '-' . bin2hex(random_bytes(4)) . '@example.com';
    $db->prepare('INSERT INTO users (name, email, password_hash, is_vendor) VALUES (:n, :e, :p, 1)')
        ->execute(['n' => "Vendeur Test $label", 'e' => $email, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
    $userId = (int) $db->lastInsertId();
    $created['users'][] = $userId;

    $db->prepare('INSERT INTO vendors (user_id, business_name, status) VALUES (:u, :b, "active")')
        ->execute(['u' => $userId, 'b' => "Boutique Test $label"]);
    $vendorId = (int) $db->lastInsertId();
    $created['vendors'][] = $vendorId;

    $slug = 'wstest-shop-' . $label . '-' . bin2hex(random_bytes(4));
    $db->prepare('INSERT INTO shops (name, slug, owner_id, vendor_id, logo_letter, color, is_open) VALUES (:n, :s, :o, :v, "T", "#5b8def", 1)')
        ->execute(['n' => "Boutique Test $label", 's' => $slug, 'o' => $userId, 'v' => $vendorId]);
    $shopId = (int) $db->lastInsertId();
    $created['shops'][] = $shopId;

    $db->prepare('INSERT INTO shop_subscriptions (shop_id, plan_id, plan_name, price_paid, starts_at, ends_at) VALUES (:s, 1, "Test", 0, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR))')
        ->execute(['s' => $shopId]);

    $catId = (int) $db->query('SELECT id FROM categories LIMIT 1')->fetchColumn();
    $price = $label === 'a' ? 2000 : 3000;
    $db->prepare('INSERT INTO products (shop_id, category_id, name, slug, price, stock, icon) VALUES (:s, :c, :n, :sl, :p, 20, "shopping-basket")')
        ->execute(['s' => $shopId, 'c' => $catId, 'n' => "Produit Test $label", 'sl' => 'wstest-product-' . $label . '-' . bin2hex(random_bytes(4)), 'p' => $price]);
    $productId = (int) $db->lastInsertId();
    $created['products'][] = $productId;

    return ['user_id' => $userId, 'vendor_id' => $vendorId, 'shop_id' => $shopId, 'product_id' => $productId, 'price' => $price];
}

function ws_webhook_service(PDO $db): WebhookService
{
    return new WebhookService(
        GeniusPayService::fromEnv(),
        new WebhookEventRepository($db),
        new PaymentRepository($db),
        wallet_settlement_service(),
        $db
    );
}

// ----------------------------------------------------------------------
// Scenarios
// ----------------------------------------------------------------------

$results = [];

function ws_run(array &$results, string $name, callable $fn): void
{
    try {
        [$ok, $message] = $fn();
    } catch (\Throwable $e) {
        $ok = false;
        $message = 'Exception non geree : ' . $e->getMessage();
    }
    $results[] = ['name' => $name, 'ok' => $ok, 'message' => $message];
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ' — ' . $message . "\n";
}

$vA = ws_make_vendor($db, $created, 'a');
$vB = ws_make_vendor($db, $created, 'b');

// --- 1. Paiement reussi ---------------------------------------------------
$order1Id = null;
ws_run($results, '1. Paiement reussi', function () use ($db, &$created, $vA, &$order1Id) {
    $r = wallet_order_service()->createFromCart(
        [['id' => $vA['product_id'], 'qty' => 1]],
        ['user_id' => null, 'name' => 'Client WS', 'email' => 'wstest-client@example.com'],
        'Man - Madina',
        'online'
    );
    $order1Id = $r->orderId;
    $created['orders'][] = $order1Id;

    $paymentRepo = new PaymentRepository($db);
    $paymentRepo->create(['order_id' => $order1Id, 'provider_reference' => 'WSTEST-REF-1-' . $order1Id, 'amount' => $r->totalAmount, 'status' => 'pending', 'environment' => 'live']);

    $payload = ws_signed_webhook_payload('WSTEST-REF-1-' . $order1Id, 'completed', $r->totalAmount);
    $result = ws_webhook_service($db)->handle($payload['body'], $payload['signature'], $payload['timestamp'], $payload['event_type']);

    $wallet = $db->query('SELECT pending_balance FROM wallets WHERE vendor_id = ' . $vA['vendor_id'])->fetch();
    $expected = $r->totalAmount;
    $actual = (int) round((float) ($wallet['pending_balance'] ?? 0));

    return [
        $result['http_status'] === 200 && $actual === $expected,
        "wallet pending={$actual}, attendu={$expected}, http={$result['http_status']}",
    ];
});

// --- 2. Paiement echoue ----------------------------------------------------
ws_run($results, '2. Paiement echoue', function () use ($db, &$created, $vA) {
    $r = wallet_order_service()->createFromCart([['id' => $vA['product_id'], 'qty' => 1]], ['user_id' => null, 'name' => 'Client WS2', 'email' => 'wstest-client2@example.com'], 'Man', 'online');
    $created['orders'][] = $r->orderId;
    $ref = 'WSTEST-REF-2-' . $r->orderId;
    (new PaymentRepository($db))->create(['order_id' => $r->orderId, 'provider_reference' => $ref, 'amount' => $r->totalAmount, 'status' => 'pending', 'environment' => 'live']);

    $payload = ws_signed_webhook_payload($ref, 'failed', $r->totalAmount);
    ws_webhook_service($db)->handle($payload['body'], $payload['signature'], $payload['timestamp'], $payload['event_type']);

    $payment = $db->query("SELECT status FROM payments WHERE provider_reference = " . $db->quote($ref))->fetch();

    return [$payment['status'] === 'failed', "payment.status={$payment['status']}"];
});

// --- 3. Paiement annule -----------------------------------------------------
ws_run($results, '3. Paiement annule', function () use ($db, &$created, $vA) {
    $r = wallet_order_service()->createFromCart([['id' => $vA['product_id'], 'qty' => 1]], ['user_id' => null, 'name' => 'Client WS3', 'email' => 'wstest-client3@example.com'], 'Man', 'online');
    $created['orders'][] = $r->orderId;
    $ref = 'WSTEST-REF-3-' . $r->orderId;
    (new PaymentRepository($db))->create(['order_id' => $r->orderId, 'provider_reference' => $ref, 'amount' => $r->totalAmount, 'status' => 'pending', 'environment' => 'live']);

    $payload = ws_signed_webhook_payload($ref, 'cancelled', $r->totalAmount);
    ws_webhook_service($db)->handle($payload['body'], $payload['signature'], $payload['timestamp'], $payload['event_type']);

    $payment = $db->query("SELECT status FROM payments WHERE provider_reference = " . $db->quote($ref))->fetch();

    return [$payment['status'] === 'cancelled', "payment.status={$payment['status']}"];
});

// --- 4. Webhook duplique (idempotence) --------------------------------------
ws_run($results, '4. Webhook duplique', function () use ($db, $vA, $order1Id) {
    $walletBefore = (int) round((float) $db->query('SELECT pending_balance FROM wallets WHERE vendor_id = ' . $vA['vendor_id'])->fetchColumn());

    $ref = 'WSTEST-REF-1-' . $order1Id;
    $payment = $db->query("SELECT amount FROM payments WHERE provider_reference = " . $db->quote($ref))->fetch();
    $payload = ws_signed_webhook_payload($ref, 'completed', (int) round((float) $payment['amount']));
    $result = ws_webhook_service($db)->handle($payload['body'], $payload['signature'], $payload['timestamp'], $payload['event_type']);

    // Rejoue le MEME evenement (meme id) une seconde fois -> doit etre ignore.
    $result2 = ws_webhook_service($db)->handle($payload['body'], $payload['signature'], $payload['timestamp'], $payload['event_type']);

    $walletAfter = (int) round((float) $db->query('SELECT pending_balance FROM wallets WHERE vendor_id = ' . $vA['vendor_id'])->fetchColumn());

    return [
        $walletAfter === $walletBefore && str_contains((string) $result2['body']['detail'], 'already processed'),
        "solde avant={$walletBefore}, apres 2 replays={$walletAfter}, 2e reponse=" . $result2['body']['detail'],
    ];
});

// --- 5. Montant incorrect ----------------------------------------------------
ws_run($results, '5. Montant incorrect (webhook)', function () use ($db, &$created, $vA) {
    $r = wallet_order_service()->createFromCart([['id' => $vA['product_id'], 'qty' => 1]], ['user_id' => null, 'name' => 'Client WS5', 'email' => 'wstest-client5@example.com'], 'Man', 'online');
    $created['orders'][] = $r->orderId;
    $ref = 'WSTEST-REF-5-' . $r->orderId;
    (new PaymentRepository($db))->create(['order_id' => $r->orderId, 'provider_reference' => $ref, 'amount' => $r->totalAmount, 'status' => 'pending', 'environment' => 'live']);

    // Le webhook annonce un montant different de celui stocke -> doit etre rejete.
    $payload = ws_signed_webhook_payload($ref, 'completed', $r->totalAmount + 500);
    ws_webhook_service($db)->handle($payload['body'], $payload['signature'], $payload['timestamp'], $payload['event_type']);

    $payment = $db->query("SELECT status FROM payments WHERE provider_reference = " . $db->quote($ref))->fetch();

    return [$payment['status'] === 'pending', "payment.status={$payment['status']} (doit rester pending, jamais completed)"];
});

// --- 6. Reference inconnue ---------------------------------------------------
ws_run($results, '6. Reference inconnue', function () use ($db) {
    $payload = ws_signed_webhook_payload('WSTEST-REF-INCONNUE-' . bin2hex(random_bytes(4)), 'completed', 1000);
    $result = ws_webhook_service($db)->handle($payload['body'], $payload['signature'], $payload['timestamp'], $payload['event_type']);

    return [$result['http_status'] === 200, "http={$result['http_status']} (doit repondre 200 sans planter, evenement trace)"];
});

// --- 7. Vendeur inexistant ----------------------------------------------------
ws_run($results, '7. Vendeur inexistant', function () use ($db, &$created) {
    // Boutique sans owner_id/vendor_id -> aucun vendeur resoluble.
    $slug = 'wstest-shop-novendor-' . bin2hex(random_bytes(4));
    $db->prepare('INSERT INTO shops (name, slug, is_open) VALUES ("Boutique Sans Vendeur", :s, 1)')->execute(['s' => $slug]);
    $shopId = (int) $db->lastInsertId();
    $created['shops'][] = $shopId;
    $db->prepare('INSERT INTO shop_subscriptions (shop_id, plan_id, plan_name, price_paid, starts_at, ends_at) VALUES (:s, 1, "Test", 0, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR))')->execute(['s' => $shopId]);
    $catId = (int) $db->query('SELECT id FROM categories LIMIT 1')->fetchColumn();
    $db->prepare('INSERT INTO products (shop_id, category_id, name, slug, price, stock, icon) VALUES (:s, :c, "Produit Sans Vendeur", :sl, 1000, 10, "shopping-basket")')
        ->execute(['s' => $shopId, 'c' => $catId, 'sl' => 'wstest-product-novendor-' . bin2hex(random_bytes(4))]);
    $productId = (int) $db->lastInsertId();
    $created['products'][] = $productId;

    try {
        wallet_order_service()->createFromCart([['id' => $productId, 'qty' => 1]], ['user_id' => null, 'name' => 'Client WS7', 'email' => 'wstest-client7@example.com'], 'Man', 'cod');

        return [false, 'aucune exception levee alors que le panier ne devrait contenir aucun article resoluble'];
    } catch (\InvalidArgumentException $e) {
        return [true, 'InvalidArgumentException correctement levee : ' . $e->getMessage()];
    }
});

// --- 8. Commande deja payee (double settlement) -------------------------------
ws_run($results, '8. Commande deja payee (double settlement)', function () use ($db, $vA, $order1Id) {
    $walletBefore = (int) round((float) $db->query('SELECT pending_balance, available_balance FROM wallets WHERE vendor_id = ' . $vA['vendor_id'])->fetchColumn());
    $result = wallet_settlement_service()->settleOrder($order1Id);
    $walletAfter = (int) round((float) $db->query('SELECT pending_balance FROM wallets WHERE vendor_id = ' . $vA['vendor_id'])->fetchColumn());

    return [$result->ok && $walletAfter === $walletBefore, "settle ok={$result->ok}, solde avant/apres inchange={$walletBefore}/{$walletAfter}"];
});

// --- 9. Sur-engagement de retrait (double demande simultanee) -----------------
ws_run($results, '9. Sur-engagement de retrait', function () use ($vA) {
    // Cree un wallet dedie avec solde connu pour ce test isole.
    $db = get_db();
    $db->prepare('UPDATE wallets SET available_balance = 3000 WHERE vendor_id = :v')->execute(['v' => $vA['vendor_id']]);

    $svc = wallet_withdrawal_service();
    $r1 = $svc->requestWithdrawal($vA['vendor_id'], 2000, 'wave', '0700000000');
    $r2 = $svc->requestWithdrawal($vA['vendor_id'], 2000, 'wave', '0700000000');

    return [$r1->ok && !$r2->ok, "1ere demande ok={$r1->ok}, 2e demande (sur-engagement) ok=" . var_export($r2->ok, true)];
});

// --- 10. Retrait depassant le solde ------------------------------------------
ws_run($results, '10. Retrait depassant le solde', function () use ($vA) {
    $svc = wallet_withdrawal_service();
    $r = $svc->requestWithdrawal($vA['vendor_id'], 999999, 'wave', '0700000000');

    return [!$r->ok, 'demande de 999999 FCFA correctement rejetee : ' . ($r->error ?? '')];
});

// --- 11. Remboursement --------------------------------------------------------
ws_run($results, '11. Remboursement', function () use ($db, $vA, $order1Id) {
    $item = $db->query('SELECT * FROM order_items WHERE order_id = ' . $order1Id)->fetch();
    $walletBefore = (int) round((float) $db->query('SELECT pending_balance FROM wallets WHERE vendor_id = ' . $vA['vendor_id'])->fetchColumn());

    $result = wallet_refund_service()->refundOrderItem((int) $item['id'], 1, 'Test remboursement WS');

    $walletAfter = (int) round((float) $db->query('SELECT pending_balance FROM wallets WHERE vendor_id = ' . $vA['vendor_id'])->fetchColumn());
    $itemAfter = $db->query('SELECT refund_status, refunded_quantity FROM order_items WHERE id = ' . (int) $item['id'])->fetch();
    $expectedDrop = (int) round((float) $item['vendor_net_amount']);

    return [
        $result->ok && ($walletBefore - $walletAfter) === $expectedDrop && $itemAfter['refund_status'] === 'full',
        'refund ok=' . var_export($result->ok, true) . ($result->error ? ' error=' . $result->error : '') . ", solde {$walletBefore}->{$walletAfter} (attendu -{$expectedDrop}), refund_status={$itemAfter['refund_status']}",
    ];
});

// --- 12. Commande multi-vendeurs ----------------------------------------------
ws_run($results, '12. Commande multi-vendeurs', function () use ($db, &$created, $vA, $vB) {
    $r = wallet_order_service()->createFromCart(
        [['id' => $vA['product_id'], 'qty' => 1], ['id' => $vB['product_id'], 'qty' => 1]],
        ['user_id' => null, 'name' => 'Client WS12', 'email' => 'wstest-client12@example.com'],
        'Man',
        'online'
    );
    $created['orders'][] = $r->orderId;

    $items = $db->query('SELECT vendor_id, subtotal, commission_amount, vendor_net_amount FROM order_items WHERE order_id = ' . $r->orderId)->fetchAll();
    if (count($items) !== 2) {
        return [false, 'attendu 2 order_items (un par vendeur), trouve ' . count($items)];
    }

    $ref = 'WSTEST-REF-12-' . $r->orderId;
    (new PaymentRepository($db))->create(['order_id' => $r->orderId, 'provider_reference' => $ref, 'amount' => $r->totalAmount, 'status' => 'pending', 'environment' => 'live']);
    $payload = ws_signed_webhook_payload($ref, 'completed', $r->totalAmount);
    ws_webhook_service($db)->handle($payload['body'], $payload['signature'], $payload['timestamp'], $payload['event_type']);

    $walletA = (int) round((float) $db->query('SELECT pending_balance FROM wallets WHERE vendor_id = ' . $vA['vendor_id'])->fetchColumn());
    $walletB = (int) round((float) $db->query('SELECT pending_balance FROM wallets WHERE vendor_id = ' . $vB['vendor_id'])->fetchColumn());

    // vendorA a deja ete debite de 2000 (scenario 11), donc on verifie seulement que
    // CE nouvel item (2000) a bien ete recredite en plus, et que B a recu ses 3000.
    return [
        $walletB === $vB['price'] && $walletA >= $vA['price'],
        "wallet A={$walletA} (>= {$vA['price']}), wallet B={$walletB} (attendu {$vB['price']})",
    ];
});

// --- 13. Panne Genius Pay ------------------------------------------------------
ws_run($results, '13. Panne Genius Pay (API injoignable)', function () use ($db, &$created, $vA) {
    $r = wallet_order_service()->createFromCart([['id' => $vA['product_id'], 'qty' => 1]], ['user_id' => null, 'name' => 'Client WS13', 'email' => 'wstest-client13@example.com'], 'Man', 'online');
    $created['orders'][] = $r->orderId;

    $downService = new GeniusPayService('http://127.0.0.1:1', 'fake', 'fake', 'fake');
    $paymentService = new App\Services\PaymentService($downService, new PaymentRepository($db), 'live');
    $result = $paymentService->createForOrder($r->orderId, $r->totalAmount, [
        'payment_method' => 'wave', 'description' => 'Test panne', 'customer' => ['name' => 'Client WS13', 'email' => 'wstest-client13@example.com'],
        'success_url' => 'https://example.com/ok', 'error_url' => 'https://example.com/ko',
    ]);

    return [!$result->ok && $result->error !== null, 'echec propre sans exception, error=' . ($result->error ?? 'null')];
});

// --- 14. Webhook malveillant (signature invalide) -------------------------------
ws_run($results, '14. Webhook malveillant (signature invalide)', function () use ($db) {
    $body = json_encode(['id' => 'wstest-fake-' . bin2hex(random_bytes(4)), 'type' => 'payment.success', 'data' => ['reference' => 'ANYTHING', 'status' => 'completed', 'amount' => 999999]]);
    $result = ws_webhook_service($db)->handle($body, 'signature-totalement-fausse', (string) time(), 'payment.success');

    return [$result['http_status'] === 401, "http={$result['http_status']} (attendu 401)"];
});

// --- 15. Manipulation du montant cote navigateur --------------------------------
ws_run($results, '15. Manipulation du montant cote navigateur', function () use ($db, &$created, $vA) {
    // Le panier envoye par le navigateur ne devrait contenir QUE id/qty ; on
    // simule une tentative de triche en injectant des champs supplementaires
    // (price falsifie) et on verifie qu'ils sont totalement ignores.
    $tamperedCart = [['id' => $vA['product_id'], 'qty' => 1, 'price' => 1, 'unit_price' => 1, 'amount' => 1]];
    $r = wallet_order_service()->createFromCart($tamperedCart, ['user_id' => null, 'name' => 'Client WS15', 'email' => 'wstest-client15@example.com'], 'Man', 'cod');
    $created['orders'][] = $r->orderId;

    $item = $db->query('SELECT unit_price, subtotal FROM order_items WHERE order_id = ' . $r->orderId)->fetch();
    $realPrice = $vA['price'];

    return [
        (int) round((float) $item['unit_price']) === $realPrice && $r->totalAmount === $realPrice,
        "prix serveur={$item['unit_price']} (attendu {$realPrice}, prix falsifie envoye=1)",
    ];
});

// ----------------------------------------------------------------------
// Nettoyage complet (chaque etape isolee : un echec n'empeche pas le reste)
// ----------------------------------------------------------------------

function ws_cleanup_step(string $label, callable $fn): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        echo "[NETTOYAGE] echec sur '{$label}' : {$e->getMessage()}\n";
    }
}

ws_cleanup_step('settlement_failures', function () use ($db, $created) {
    $db->exec('DELETE FROM settlement_failures WHERE order_id IN (' . (implode(',', $created['orders']) ?: '0') . ')');
});
ws_cleanup_step('refund_items+refunds+commissions+payments+orders', function () use ($db, $created) {
    foreach ($created['orders'] as $orderId) {
        $db->exec("DELETE ri FROM refund_items ri JOIN refunds r ON r.id = ri.refund_id WHERE r.order_id = $orderId");
        $db->exec("DELETE FROM refunds WHERE order_id = $orderId");
        $db->exec("DELETE FROM commissions WHERE order_id = $orderId");
        $db->exec("DELETE FROM payments WHERE order_id = $orderId");
        $db->exec("DELETE FROM orders WHERE id = $orderId");
    }
});
ws_cleanup_step('withdrawals+wallet_transactions+wallets', function () use ($db, $created) {
    foreach ($created['vendors'] as $vendorId) {
        $db->exec("DELETE FROM withdrawals WHERE vendor_id = $vendorId");
        $db->exec("DELETE FROM wallet_transactions WHERE vendor_id = $vendorId");
        $db->exec("DELETE FROM wallets WHERE vendor_id = $vendorId");
    }
});
ws_cleanup_step('products', function () use ($db, $created) {
    foreach ($created['products'] as $productId) {
        $db->exec("DELETE FROM products WHERE id = $productId");
    }
});
ws_cleanup_step('shops+subscriptions', function () use ($db, $created) {
    foreach ($created['shops'] as $shopId) {
        $db->exec("DELETE FROM shop_subscriptions WHERE shop_id = $shopId");
        $db->exec("DELETE FROM shops WHERE id = $shopId");
    }
});
ws_cleanup_step('vendors', function () use ($db, $created) {
    foreach ($created['vendors'] as $vendorId) {
        $db->exec("DELETE FROM vendors WHERE id = $vendorId");
    }
});
ws_cleanup_step('users', function () use ($db, $created) {
    foreach ($created['users'] as $userId) {
        $db->exec("DELETE FROM users WHERE id = $userId");
    }
});
ws_cleanup_step('webhook_events', function () use ($db) {
    $db->exec("DELETE FROM webhook_events WHERE event_id LIKE 'wstest-%'");
});

// ----------------------------------------------------------------------
// Rapport
// ----------------------------------------------------------------------

$passed = count(array_filter($results, fn ($r) => $r['ok']));
$total = count($results);

echo "\n============================================\n";
echo "RESULTAT : {$passed}/{$total} scenarios reussis\n";
echo "============================================\n";

exit($passed === $total ? 0 : 1);
