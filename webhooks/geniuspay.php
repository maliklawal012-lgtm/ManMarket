<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/geniuspay.php';

header('Content-Type: application/json');

$rawBody = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
$timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';
$eventType = $_SERVER['HTTP_X_WEBHOOK_EVENT'] ?? '';

if (!geniuspay_verify_webhook_signature($rawBody, $signature, $timestamp)) {
    http_response_code(401);
    echo json_encode(['status' => 401, 'detail' => 'Invalid signature']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['status' => 400, 'detail' => 'Invalid payload']);
    exit;
}

if ($eventType === 'webhook.test' || !isset($payload['data']['reference'])) {
    http_response_code(200);
    echo json_encode(['status' => 200, 'detail' => 'ok']);
    exit;
}

$reference = (string) $payload['data']['reference'];
$status = (string) ($payload['data']['status'] ?? '');
$paymentMethod = $payload['data']['payment_method'] ?? $payload['data']['provider'] ?? null;

$validStatuses = ['pending', 'processing', 'completed', 'failed', 'cancelled', 'expired', 'refunded'];
if ($status === '' || !in_array($status, $validStatuses, true)) {
    http_response_code(200);
    echo json_encode(['status' => 200, 'detail' => 'Ignored: unknown status']);
    exit;
}

// NOTE : ce fichier est l'ANCIEN recepteur webhook, conserve pour compatibilite
// pendant la transition. Les commandes utilisent desormais /api/webhooks/geniuspay.php
// (architecture wallet). Celui-ci ne gere plus que les abonnements boutique
// (subscription_payments) et les anciens paiements de commande archives.
$db = get_db();
$stmt = $db->prepare('UPDATE legacy_payments SET status = :status, payment_method = :method, updated_at = CURRENT_TIMESTAMP WHERE reference = :reference');
$stmt->execute([
    'status' => $status,
    'method' => $paymentMethod,
    'reference' => $reference,
]);

if ($stmt->rowCount() === 0) {
    $stmt = $db->prepare('SELECT * FROM subscription_payments WHERE reference = :reference');
    $stmt->execute(['reference' => $reference]);
    $subPayment = $stmt->fetch();

    if ($subPayment) {
        $db->prepare('UPDATE subscription_payments SET status = :status, payment_method = :method, updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute([
            'status' => $status,
            'method' => $paymentMethod,
            'id' => $subPayment['id'],
        ]);

        if ($status === 'completed' && !$subPayment['applied']) {
            $stmt = $db->prepare('SELECT * FROM subscription_plans WHERE id = :id');
            $stmt->execute(['id' => $subPayment['plan_id']]);
            $plan = $stmt->fetch();

            if ($plan) {
                apply_subscription_payment((int) $subPayment['shop_id'], $plan, (int) $subPayment['amount']);
                $db->prepare('UPDATE subscription_payments SET applied = 1 WHERE id = :id')->execute(['id' => $subPayment['id']]);
            }
        }
    }
}

http_response_code(200);
echo json_encode(['status' => 200, 'detail' => 'ok']);
