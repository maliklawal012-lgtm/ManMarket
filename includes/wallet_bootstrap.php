<?php

declare(strict_types=1);

/**
 * Point d'entree unique pour instancier les services de la nouvelle
 * architecture portefeuille (commandes/paiements/wallets) depuis les pages
 * procedurales existantes (commander.php, commandes.php, ...).
 */

require_once __DIR__ . '/../config/autoload.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/geniuspay.php';

use App\Repositories\AuditLogRepository;
use App\Repositories\CommissionRepository;
use App\Repositories\OrderItemRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\RefundRepository;
use App\Repositories\SettlementFailureRepository;
use App\Repositories\VendorRepository;
use App\Repositories\WalletRepository;
use App\Repositories\WalletTransactionRepository;
use App\Services\CommissionService;
use App\Services\GeniusPayService;
use App\Repositories\WithdrawalRepository;
use App\Services\OrderService;
use App\Services\OrderSettlementService;
use App\Services\PaymentService;
use App\Services\RefundService;
use App\Services\VendorAdminService;
use App\Services\WalletReleaseService;
use App\Services\WalletService;
use App\Services\WithdrawalService;

function wallet_order_service(): OrderService
{
    $db = get_db();

    return new OrderService(
        $db,
        new OrderRepository($db),
        new OrderItemRepository($db),
        new VendorRepository($db),
        new CommissionService($db)
    );
}

function wallet_payment_service(): PaymentService
{
    return new PaymentService(
        GeniusPayService::fromEnv(),
        new PaymentRepository(get_db()),
        GENIUSPAY_MODE
    );
}

function wallet_settlement_service(): OrderSettlementService
{
    $db = get_db();

    return new OrderSettlementService(
        $db,
        new OrderRepository($db),
        new OrderItemRepository($db),
        new PaymentRepository($db),
        new CommissionRepository($db),
        new SettlementFailureRepository($db),
        new WalletService(new WalletRepository($db), new WalletTransactionRepository($db))
    );
}

function wallet_order_repo(): OrderRepository
{
    return new OrderRepository(get_db());
}

function wallet_order_item_repo(): OrderItemRepository
{
    return new OrderItemRepository(get_db());
}

function wallet_payment_repo(): PaymentRepository
{
    return new PaymentRepository(get_db());
}

function wallet_withdrawal_service(): WithdrawalService
{
    $db = get_db();

    return new WithdrawalService(
        $db,
        new WithdrawalRepository($db),
        new WalletRepository($db),
        new WalletService(new WalletRepository($db), new WalletTransactionRepository($db)),
        new VendorRepository($db)
    );
}

function wallet_withdrawal_repo(): WithdrawalRepository
{
    return new WithdrawalRepository(get_db());
}

function wallet_wallet_repo(): WalletRepository
{
    return new WalletRepository(get_db());
}

function wallet_transaction_repo(): WalletTransactionRepository
{
    return new WalletTransactionRepository(get_db());
}

function wallet_vendor_repo(): VendorRepository
{
    return new VendorRepository(get_db());
}

function wallet_commission_repo(): CommissionRepository
{
    return new CommissionRepository(get_db());
}

function wallet_service(): WalletService
{
    $db = get_db();

    return new WalletService(new WalletRepository($db), new WalletTransactionRepository($db));
}

function wallet_audit_log_repo(): AuditLogRepository
{
    return new AuditLogRepository(get_db());
}

function wallet_settlement_failure_repo(): SettlementFailureRepository
{
    return new SettlementFailureRepository(get_db());
}

function wallet_vendor_admin_service(): VendorAdminService
{
    $db = get_db();

    return new VendorAdminService(
        $db,
        new VendorRepository($db),
        new AuditLogRepository($db),
        new WalletService(new WalletRepository($db), new WalletTransactionRepository($db))
    );
}

function wallet_refund_service(): RefundService
{
    $db = get_db();

    return new RefundService(
        $db,
        new OrderItemRepository($db),
        new PaymentRepository($db),
        new RefundRepository($db),
        new CommissionRepository($db),
        new WalletService(new WalletRepository($db), new WalletTransactionRepository($db))
    );
}

function wallet_release_service(): WalletReleaseService
{
    $db = get_db();

    return new WalletReleaseService(
        $db,
        new OrderItemRepository($db),
        new WalletService(new WalletRepository($db), new WalletTransactionRepository($db))
    );
}
