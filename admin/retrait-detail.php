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
$withdrawalId = (int) ($_GET['id'] ?? 0);

$stmt = $db->prepare('
    SELECT w.*, v.business_name, v.status AS vendor_status, u.name AS user_name, u.email AS user_email, u.phone AS user_phone, s.id AS shop_id
    FROM withdrawals w
    JOIN vendors v ON v.id = w.vendor_id
    JOIN users u ON u.id = v.user_id
    LEFT JOIN shops s ON s.vendor_id = v.id
    WHERE w.id = :id
');
$stmt->execute(['id' => $withdrawalId]);
$withdrawal = $stmt->fetch() ?: null;

if (!$withdrawal) {
    header('Location: /market/admin/retraits');
    exit;
}

$actionError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== '') {
    $action = (string) $_POST['action'];
    $note = trim((string) ($_POST['admin_note'] ?? ''));
    $svc = wallet_withdrawal_service();
    $adminId = (int) $adminUser['id'];

    $result = match ($action) {
        'approve' => $svc->approve($withdrawalId, $adminId),
        'reject' => $svc->reject($withdrawalId, $adminId, $note !== '' ? $note : 'Rejeté par l\'administrateur'),
        'processing' => $svc->markProcessing($withdrawalId, $adminId),
        'complete' => $svc->complete($withdrawalId, $adminId, $note !== '' ? $note : null),
        'fail' => $svc->fail($withdrawalId, $adminId, $note !== '' ? $note : 'Échec du transfert'),
        'reverse' => $svc->reverse($withdrawalId, $adminId, $note !== '' ? $note : 'Annulé par l\'administrateur'),
        default => null,
    };

    if ($result === null) {
        $actionError = 'Action inconnue.';
    } elseif (!$result->ok) {
        $actionError = $result->error;
    } else {
        $auditAction = [
            'approve' => 'withdrawal_approved', 'reject' => 'withdrawal_rejected', 'processing' => 'withdrawal_processing',
            'complete' => 'withdrawal_completed', 'fail' => 'withdrawal_failed', 'reverse' => 'withdrawal_reversed',
        ][$action] ?? 'withdrawal_' . $action;
        wallet_audit_log_repo()->record($adminId, $auditAction, 'withdrawal', $withdrawalId, $note !== '' ? $note : null, $_SERVER['REMOTE_ADDR'] ?? null);
        header('Location: /market/admin/retrait-detail?id=' . $withdrawalId);
        exit;
    }
}

$withdrawalMethodLabels = [
    'wave' => 'Wave',
    'orange_money' => 'Orange Money',
    'mtn_money' => 'MTN Money',
    'moov_money' => 'Moov Money',
];

$pageTitle = 'Retrait #' . $withdrawalId;
require_once __DIR__ . '/../includes/admin_header.php';

$vendorId = (int) $withdrawal['vendor_id'];
$wallet = wallet_service()->getOrCreateWallet($vendorId);
$openReserved = wallet_withdrawal_repo()->openReservedAmountForVendor($vendorId);
$spendable = max(0, (int) round((float) $wallet['available_balance']) - $openReserved);

$stmt = $db->prepare("
    SELECT * FROM wallet_transactions
    WHERE reference_type = 'withdrawal' AND reference_id = :id
    ORDER BY created_at ASC
");
$stmt->execute(['id' => $withdrawalId]);
$transactions = $stmt->fetchAll();

$otherWithdrawals = array_filter(
    wallet_withdrawal_repo()->findByVendorId($vendorId),
    fn ($w) => (int) $w['id'] !== $withdrawalId
);
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/admin/retraits" class="link-more"><?= icon('chevron-right', 14) ?> Retour aux retraits</a>
</div>

<?php if ($actionError): ?>
    <div class="alert alert-error"><?= icon('x', 18) ?><span><?= e($actionError) ?></span></div>
<?php endif; ?>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Retrait #<?= $withdrawalId ?></h2>
        <span class="tag <?= wallet_withdrawal_status_tag_class($withdrawal['status']) ?>"><?= e(wallet_withdrawal_status_label($withdrawal['status'])) ?></span>
    </div>
    <ul class="account-info-list">
        <li><span class="account-info-label"><?= icon('store', 16) ?> Vendeur</span><span><a href="/market/admin/vendeur-finance?id=<?= $vendorId ?>" class="link-muted"><?= e($withdrawal['business_name']) ?></a> — <?= e($withdrawal['user_name']) ?> — <?= e($withdrawal['user_email']) ?></span></li>
        <li><span class="account-info-label"><?= icon('cart', 16) ?> Montant demandé</span><span><?= format_price((int) round((float) $withdrawal['amount'])) ?></span></li>
        <?php if ((float) $withdrawal['fee'] > 0): ?>
            <li><span class="account-info-label"><?= icon('x', 16) ?> Frais</span><span><?= format_price((int) round((float) $withdrawal['fee'])) ?></span></li>
            <li><span class="account-info-label"><?= icon('check-circle', 16) ?> Montant net</span><span><?= format_price((int) round((float) $withdrawal['net_amount'])) ?></span></li>
        <?php endif; ?>
        <li><span class="account-info-label"><?= icon('shield', 16) ?> Moyen de réception</span><span><?= e($withdrawalMethodLabels[$withdrawal['payment_method']] ?? $withdrawal['payment_method']) ?> — <?= e($withdrawal['account_number']) ?></span></li>
        <li><span class="account-info-label"><?= icon('send', 16) ?> Référence</span><span><?= e($withdrawal['reference']) ?></span></li>
        <li><span class="account-info-label"><?= icon('clock', 16) ?> Demandé le</span><span><?= e(date('d/m/Y à H:i', strtotime((string) $withdrawal['created_at']))) ?></span></li>
        <?php if ($withdrawal['processed_at']): ?>
            <li><span class="account-info-label"><?= icon('clock', 16) ?> Dernière mise à jour</span><span><?= e(date('d/m/Y à H:i', strtotime((string) $withdrawal['processed_at']))) ?></span></li>
        <?php endif; ?>
        <?php if ($withdrawal['admin_note']): ?>
            <li><span class="account-info-label"><?= icon('user', 16) ?> Note</span><span><?= e($withdrawal['admin_note']) ?></span></li>
        <?php endif; ?>
    </ul>
</div>

<div class="admin-stats-grid" style="grid-template-columns: repeat(3, 1fr); margin-bottom: var(--gap);">
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#e8f8ee; color:#16a34a;"><?= icon('cart', 18) ?></span>
        <span class="admin-stat-value"><?= format_price($spendable) ?></span>
        <span class="admin-stat-label">Disponible actuellement (après réservations)</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fef3c7; color:#92400e;"><?= icon('clock', 18) ?></span>
        <span class="admin-stat-value"><?= format_price((int) round((float) $wallet['pending_balance'])) ?></span>
        <span class="admin-stat-label">En attente (livraison non confirmée)</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#eef2ff; color:#4f46e5;"><?= icon('check-circle', 18) ?></span>
        <span class="admin-stat-value"><?= format_price((int) round((float) $wallet['total_withdrawn'])) ?></span>
        <span class="admin-stat-label">Total déjà retiré (tous retraits)</span>
    </div>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Actions</h2>
    </div>
    <div class="admin-table-actions">
        <?php if ($withdrawal['status'] === 'PENDING'): ?>
            <form method="post" action="/market/admin/retrait-detail?id=<?= $withdrawalId ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="btn btn-primary btn-sm">Approuver</button>
            </form>
            <form method="post" action="/market/admin/retrait-detail?id=<?= $withdrawalId ?>" onsubmit="return confirm('Rejeter cette demande de retrait ?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reject">
                <button type="submit" class="btn btn-outline-primary btn-sm">Rejeter</button>
            </form>
        <?php elseif ($withdrawal['status'] === 'APPROVED'): ?>
            <form method="post" action="/market/admin/retrait-detail?id=<?= $withdrawalId ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="processing">
                <button type="submit" class="btn btn-primary btn-sm">Marquer en cours</button>
            </form>
            <form method="post" action="/market/admin/retrait-detail?id=<?= $withdrawalId ?>" onsubmit="return confirm('Rejeter cette demande de retrait ?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reject">
                <button type="submit" class="btn btn-outline-primary btn-sm">Rejeter</button>
            </form>
        <?php elseif ($withdrawal['status'] === 'PROCESSING'): ?>
            <form method="post" action="/market/admin/retrait-detail?id=<?= $withdrawalId ?>" onsubmit="return confirm('Confirmer que l\'argent a bien été envoyé au vendeur ?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="complete">
                <button type="submit" class="btn btn-primary btn-sm">Marquer payé</button>
            </form>
            <form method="post" action="/market/admin/retrait-detail?id=<?= $withdrawalId ?>" onsubmit="return confirm('Marquer ce transfert comme échoué ?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="fail">
                <button type="submit" class="btn btn-outline-primary btn-sm">Échec</button>
            </form>
        <?php elseif ($withdrawal['status'] === 'COMPLETED'): ?>
            <form method="post" action="/market/admin/retrait-detail?id=<?= $withdrawalId ?>" onsubmit="return confirm('Annuler ce retrait et recréditer le vendeur ? À utiliser uniquement en cas d\'erreur (argent jamais réellement envoyé).');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reverse">
                <button type="submit" class="btn btn-outline-primary btn-sm">Annuler / recréditer</button>
            </form>
        <?php else: ?>
            <span class="char-count">Aucune action disponible pour ce statut.</span>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Mouvements de portefeuille liés (<?= count($transactions) ?>)</h2>
    </div>
    <?php if (!$transactions): ?>
        <p class="empty-state">Aucun mouvement de portefeuille pour l'instant — le solde n'est débité qu'à la confirmation du paiement (« Marquer payé »).</p>
    <?php else: ?>
        <div class="admin-activity-list">
            <?php foreach ($transactions as $tx): ?>
                <div class="admin-activity-item">
                    <span class="admin-activity-icon"><?= icon((float) $tx['amount'] >= 0 ? 'check-circle' : 'x', 15) ?></span>
                    <div>
                        <div class="admin-activity-text">
                            <?= e(wallet_transaction_type_label($tx['type'])) ?> —
                            <span style="color: <?= (float) $tx['amount'] >= 0 ? '#16a34a' : '#dc2626' ?>;"><?= (float) $tx['amount'] >= 0 ? '+' : '' ?><?= format_price((int) round((float) $tx['amount'])) ?></span>
                        </div>
                        <div class="admin-activity-time"><?= e($tx['description']) ?> · <?= e(date('d/m/Y à H:i', strtotime((string) $tx['created_at']))) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($otherWithdrawals): ?>
    <div class="card">
        <div class="admin-toolbar">
            <h2>Autres retraits de ce vendeur (<?= count($otherWithdrawals) ?>)</h2>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Montant</th>
                        <th>Statut</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($otherWithdrawals as $w): ?>
                        <tr>
                            <td><?= e(date('d/m/Y H:i', strtotime((string) $w['created_at']))) ?></td>
                            <td><?= format_price((int) round((float) $w['amount'])) ?></td>
                            <td><span class="tag <?= wallet_withdrawal_status_tag_class($w['status']) ?>"><?= e(wallet_withdrawal_status_label($w['status'])) ?></span></td>
                            <td><a href="/market/admin/retrait-detail?id=<?= (int) $w['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
