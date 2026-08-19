<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wallet_bootstrap.php';
require_once __DIR__ . '/../includes/csrf.php';

$adminUser = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$db = get_db();

$vendorId = (int) ($_GET['id'] ?? 0);
$vendor = wallet_vendor_repo()->findById($vendorId);

if (!$vendor) {
    header('Location: /market/admin/finances');
    exit;
}

$actionError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'adjust') {
    $amount = (int) ($_POST['amount'] ?? 0);
    $direction = ($_POST['direction'] ?? 'credit') === 'debit' ? -1 : 1;
    $reason = trim((string) ($_POST['reason'] ?? ''));

    try {
        wallet_vendor_admin_service()->adjustWallet(
            $vendorId,
            (int) $adminUser['id'],
            abs($amount) * $direction,
            $reason,
            $_SERVER['REMOTE_ADDR'] ?? null
        );
        header('Location: /market/admin/vendeur-finance?id=' . $vendorId);
        exit;
    } catch (\Throwable $e) {
        $actionError = $e->getMessage();
    }
}

$pageTitle = $vendor['business_name'];
require_once __DIR__ . '/../includes/admin_header.php';

$vendorUser = $db->query('SELECT name, email FROM users WHERE id = ' . (int) $vendor['user_id'])->fetch();
$wallet = wallet_service()->getOrCreateWallet($vendorId);
$transactions = wallet_transaction_repo()->findByVendorId($vendorId, 50);
$withdrawals = wallet_withdrawal_repo()->findByVendorId($vendorId);
$auditHistory = wallet_audit_log_repo()->findByEntity('vendor', $vendorId);
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/admin/finances" class="link-more"><?= icon('chevron-right', 14) ?> Retour aux finances</a>
</div>

<?php if ($actionError): ?>
    <div class="alert alert-error"><?= icon('x', 18) ?><span><?= e($actionError) ?></span></div>
<?php endif; ?>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2><?= e($vendor['business_name']) ?></h2>
        <span class="tag <?= $vendor['status'] === 'active' ? 'tag-open' : 'tag-closed' ?>"><?= $vendor['status'] === 'active' ? 'Actif' : 'Suspendu' ?></span>
    </div>
    <p class="char-count"><?= e($vendorUser['name'] ?? '') ?> — <?= e($vendorUser['email'] ?? '') ?><?php if ($vendor['suspended_reason']): ?><br>Motif de suspension : <?= e($vendor['suspended_reason']) ?><?php endif; ?></p>
</div>

<div class="admin-stats-grid" style="grid-template-columns: repeat(4, 1fr);">
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#e8f8ee; color:#16a34a;"><?= icon('cart', 18) ?></span>
        <span class="admin-stat-value"><?= format_price((int) round((float) $wallet['available_balance'])) ?></span>
        <span class="admin-stat-label">Disponible</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fef3c7; color:#92400e;"><?= icon('clock', 18) ?></span>
        <span class="admin-stat-value"><?= format_price((int) round((float) $wallet['pending_balance'])) ?></span>
        <span class="admin-stat-label">En attente</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#eef2ff; color:#4f46e5;"><?= icon('bar-chart', 18) ?></span>
        <span class="admin-stat-value"><?= format_price((int) round((float) $wallet['total_earned'])) ?></span>
        <span class="admin-stat-label">Total gagné</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fdf2f8; color:#db2777;"><?= icon('check-circle', 18) ?></span>
        <span class="admin-stat-value"><?= format_price((int) round((float) $wallet['total_withdrawn'])) ?></span>
        <span class="admin-stat-label">Total retiré</span>
    </div>
</div>

<div class="admin-dashboard-grid">
    <div class="card">
        <div class="admin-toolbar">
            <h2>Ajustement manuel</h2>
        </div>
        <p class="char-count">Correction d'erreur ou geste commercial. Toujours appliqué au solde disponible, immédiatement, et tracé dans le journal d'audit.</p>
        <form method="post" action="/market/admin/vendeur-finance?id=<?= $vendorId ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="adjust">
            <div class="form-row">
                <div class="form-field">
                    <label for="amount">Montant (FCFA) *</label>
                    <input type="number" id="amount" name="amount" min="1" required>
                </div>
                <div class="form-field">
                    <label for="direction">Sens</label>
                    <select id="direction" name="direction">
                        <option value="credit">Créditer (+)</option>
                        <option value="debit">Débiter (-)</option>
                    </select>
                </div>
            </div>
            <div class="form-field">
                <label for="reason">Justification *</label>
                <textarea id="reason" name="reason" rows="2" required></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Appliquer l'ajustement</button>
        </form>
    </div>

    <div class="card">
        <div class="admin-toolbar">
            <h2>Retraits</h2>
        </div>
        <?php if (!$withdrawals): ?>
            <p class="empty-state">Aucun retrait pour le moment.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead><tr><th>Date</th><th>Montant</th><th>Statut</th></tr></thead>
                    <tbody>
                        <?php foreach ($withdrawals as $w): ?>
                            <tr>
                                <td><?= e(date('d/m/Y', strtotime((string) $w['created_at']))) ?></td>
                                <td><?= format_price((int) round((float) $w['amount'])) ?></td>
                                <td><span class="tag <?= wallet_withdrawal_status_tag_class($w['status']) ?>"><?= e(wallet_withdrawal_status_label($w['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Journal du portefeuille (<?= count($transactions) ?>)</h2>
    </div>
    <?php if (!$transactions): ?>
        <p class="empty-state">Aucune transaction pour le moment.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead><tr><th>Date</th><th>Type</th><th>Montant</th><th>Description</th><th>Solde après</th></tr></thead>
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
    <?php endif; ?>
</div>

<div class="card">
    <div class="admin-toolbar">
        <h2>Journal d'audit</h2>
    </div>
    <?php if (!$auditHistory): ?>
        <p class="empty-state">Aucune action administrative sur ce vendeur pour le moment.</p>
    <?php else: ?>
        <div class="admin-activity-list">
            <?php foreach ($auditHistory as $log): ?>
                <div class="admin-activity-item">
                    <span class="admin-activity-icon"><?= icon('shield', 15) ?></span>
                    <div>
                        <div class="admin-activity-text"><?= e($log['action']) ?> — <?= e($log['admin_name']) ?><?php if ($log['reason']): ?> : <?= e($log['reason']) ?><?php endif; ?></div>
                        <div class="admin-activity-time"><?= e(date('d/m/Y H:i', strtotime((string) $log['created_at']))) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
