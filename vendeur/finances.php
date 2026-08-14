<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wallet_bootstrap.php';

$vendorUser = require_vendor();
$vendorEntity = wallet_vendor_repo()->findByUserId((int) $vendorUser['id']);

$transactionTypes = ['SALE', 'COMMISSION', 'REFUND', 'WITHDRAWAL', 'WITHDRAWAL_REVERSAL', 'ADJUSTMENT'];
$typeFilter = (string) ($_GET['type'] ?? '');
$typeFilter = in_array($typeFilter, $transactionTypes, true) ? $typeFilter : null;

// Export CSV du journal — avant tout envoi de HTML.
if ($vendorEntity && ($_GET['export'] ?? '') === 'ledger') {
    $rows = wallet_transaction_repo()->findAllByVendorId((int) $vendorEntity['id'], $typeFilter);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="portefeuille-' . (int) $vendorEntity['id'] . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Type', 'Montant', 'Solde avant', 'Solde après', 'Description']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['created_at'], wallet_transaction_type_label($r['type']), $r['amount'], $r['balance_before'], $r['balance_after'], $r['description']]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Finances';
require_once __DIR__ . '/../includes/vendor_header.php';

$wallet = null;
$spendable = 0;
$commissionRate = 0.0;
$transactions = [];
$totalTransactions = 0;
$perPage = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));

if ($vendorEntity) {
    $vendorId = (int) $vendorEntity['id'];
    $wallet = wallet_service()->getOrCreateWallet($vendorId);
    $openReserved = wallet_withdrawal_repo()->openReservedAmountForVendor($vendorId);
    $spendable = max(0, (int) round((float) $wallet['available_balance']) - $openReserved);

    $stmt = get_db()->prepare("SELECT value FROM settings WHERE `key` = 'marketplace_commission_rate'");
    $stmt->execute();
    $commissionRate = (float) ($stmt->fetchColumn() ?: 0);

    $totalTransactions = wallet_transaction_repo()->countByVendorId($vendorId, $typeFilter);
    $totalPages = max(1, (int) ceil($totalTransactions / $perPage));
    $page = min($page, $totalPages);
    $transactions = wallet_transaction_repo()->findByVendorIdFiltered($vendorId, $typeFilter, $perPage, ($page - 1) * $perPage);
}

function finances_query(array $overrides): string
{
    $params = array_merge(['type' => $_GET['type'] ?? '', 'page' => $_GET['page'] ?? ''], $overrides);
    $params = array_filter($params, fn ($v) => $v !== '' && $v !== null);

    return '/market/vendeur/finances.php' . ($params ? '?' . http_build_query($params) : '');
}
?>

<?php if (!$vendorEntity): ?>
    <div class="card">
        <p class="empty-state">Votre compte vendeur n'est pas encore rattaché à une boutique. Contactez l'équipe ManMarket pour finaliser la configuration de votre espace.</p>
    </div>
<?php else: ?>

    <div class="admin-stats-grid" style="grid-template-columns: repeat(3, 1fr);">
        <div class="card admin-stat-card">
            <span class="admin-stat-icon" style="background:#e8f8ee; color:#16a34a;"><?= icon('cart', 18) ?></span>
            <span class="admin-stat-value"><?= format_price($spendable) ?></span>
            <span class="admin-stat-label">Disponible pour retrait</span>
        </div>
        <div class="card admin-stat-card">
            <span class="admin-stat-icon" style="background:#fef3c7; color:#92400e;"><?= icon('clock', 18) ?></span>
            <span class="admin-stat-value"><?= format_price((int) round((float) $wallet['pending_balance'])) ?></span>
            <span class="admin-stat-label">En attente (livraison non confirmée)</span>
        </div>
        <div class="card admin-stat-card">
            <span class="admin-stat-icon" style="background:#eef2ff; color:#4f46e5;"><?= icon('bar-chart', 18) ?></span>
            <span class="admin-stat-value"><?= format_price((int) round((float) $wallet['total_earned'])) ?></span>
            <span class="admin-stat-label">Total gagné (brut)</span>
        </div>
        <div class="card admin-stat-card">
            <span class="admin-stat-icon" style="background:#fdf2f8; color:#db2777;"><?= icon('check-circle', 18) ?></span>
            <span class="admin-stat-value"><?= format_price((int) round((float) $wallet['total_withdrawn'])) ?></span>
            <span class="admin-stat-label">Total retiré</span>
        </div>
        <div class="card admin-stat-card">
            <span class="admin-stat-icon" style="background:#fee2e2; color:#b91c1c;"><?= icon('x', 18) ?></span>
            <span class="admin-stat-value"><?= format_price((int) round((float) $wallet['total_refunded'])) ?></span>
            <span class="admin-stat-label">Total remboursé (déduit)</span>
        </div>
        <div class="card admin-stat-card">
            <span class="admin-stat-icon" style="background:#f3f4f6; color:#374151;"><?= icon('shield', 18) ?></span>
            <span class="admin-stat-value"><?= number_format($commissionRate, 1) ?>%</span>
            <span class="admin-stat-label">Commission ManMarket (taux actuel)</span>
        </div>
    </div>

    <p class="char-count">
        Total gagné = somme brute de vos ventes. Commission déjà déduite : <strong><?= format_price((int) round((float) $wallet['total_commission_paid'])) ?></strong>.
        <a href="/market/vendeur/retraits.php" class="link-muted">Demander un retrait →</a>
    </p>

    <div class="card" style="margin-top: var(--gap);">
        <div class="admin-toolbar">
            <h2>Journal du portefeuille (<?= $totalTransactions ?>)</h2>
            <div class="admin-table-actions">
                <div class="filter-sort">
                    <label for="type-filter">Type</label>
                    <select id="type-filter" onchange="location.href = this.value">
                        <option value="<?= e(finances_query(['type' => '', 'page' => ''])) ?>" <?= $typeFilter === null ? 'selected' : '' ?>>Tous</option>
                        <?php foreach ($transactionTypes as $t): ?>
                            <option value="<?= e(finances_query(['type' => $t, 'page' => ''])) ?>" <?= $typeFilter === $t ? 'selected' : '' ?>><?= e(wallet_transaction_type_label($t)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <a href="<?= e(finances_query(['export' => 'ledger'])) ?>" class="btn btn-outline-primary btn-sm"><?= icon('send', 14) ?> Exporter CSV</a>
            </div>
        </div>

        <?php if (!$transactions): ?>
            <p class="empty-state">Aucune transaction pour le moment.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Montant</th>
                            <th>Description</th>
                            <th>Solde après</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $tx): ?>
                            <tr>
                                <td><?= e(date('d/m/Y H:i', strtotime((string) $tx['created_at']))) ?></td>
                                <td><?= e(wallet_transaction_type_label($tx['type'])) ?></td>
                                <td style="color: <?= (float) $tx['amount'] >= 0 ? '#16a34a' : '#dc2626' ?>;"><?= (float) $tx['amount'] >= 0 ? '+' : '' ?><?= format_price((int) round((float) $tx['amount'])) ?></td>
                                <td class="wrap"><?= e($tx['description']) ?></td>
                                <td><?= format_price((int) round((float) $tx['balance_after'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="admin-table-actions" style="margin-top: 12px; justify-content: center;">
                    <?php if ($page > 1): ?>
                        <a href="<?= e(finances_query(['page' => (string) ($page - 1)])) ?>" class="btn btn-outline-primary btn-sm">← Précédent</a>
                    <?php endif; ?>
                    <span class="char-count">Page <?= $page ?> / <?= $totalPages ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="<?= e(finances_query(['page' => (string) ($page + 1)])) ?>" class="btn btn-outline-primary btn-sm">Suivant →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
