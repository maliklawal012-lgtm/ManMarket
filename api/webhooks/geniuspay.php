<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/geniuspay.php';
require_once __DIR__ . '/../../config/mail.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/rate_limit.php';

header('Content-Type: application/json');

if (!rate_limit_check('webhook:' . rate_limit_client_ip(), 120, 60)) {
    http_response_code(429);
    echo json_encode(['status' => 429, 'detail' => 'Too many requests']);
    exit;
}

use App\Repositories\CommissionRepository;
use App\Repositories\OrderItemRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\SettlementFailureRepository;
use App\Repositories\WalletRepository;
use App\Repositories\WalletTransactionRepository;
use App\Repositories\WebhookEventRepository;
use App\Services\GeniusPayService;
use App\Services\NotificationService;
use App\Services\OrderSettlementService;
use App\Services\SmtpMailer;
use App\Services\WalletService;
use App\Services\WebhookService;

$db = get_db();

$geniusPay = GeniusPayService::fromEnv();
$webhookEvents = new WebhookEventRepository($db);
$paymentRepo = new PaymentRepository($db);

$settlement = new OrderSettlementService(
    $db,
    new OrderRepository($db),
    new OrderItemRepository($db),
    $paymentRepo,
    new CommissionRepository($db),
    new SettlementFailureRepository($db),
    new WalletService(new WalletRepository($db), new WalletTransactionRepository($db))
);

$notifications = new NotificationService($db, new SmtpMailer());

$webhookService = new WebhookService($geniusPay, $webhookEvents, $paymentRepo, $settlement, $db, $notifications);

$rawBody = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
$timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';
$eventType = $_SERVER['HTTP_X_WEBHOOK_EVENT'] ?? '';

$result = $webhookService->handle($rawBody, $signature, $timestamp, $eventType);

http_response_code($result['http_status']);
echo json_encode($result['body']);
