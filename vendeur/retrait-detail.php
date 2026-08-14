<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wallet_bootstrap.php';

$vendorUser = require_vendor();
$vendor = wallet_vendor_repo()->findByUserId((int) $vendorUser['id']);

$withdrawalId = (int) ($_GET['id'] ?? 0);
$withdrawal = $vendor ? wallet_withdrawal_repo()->findById($withdrawalId) : null;

// Garde-fou vie privée : le retrait doit appartenir au vendeur connecté.
if ($withdrawal && $vendor && (int) $withdrawal['vendor_id'] !== (int) $vendor['id']) {
    $withdrawal = null;
}

if (!$withdrawal) {
    header('Location: /market/vendeur/retraits.php');
    exit;
}

$withdrawalMethodLabels = [
    'wave' => 'Wave',
    'orange_money' => 'Orange Money',
    'mtn_money' => 'MTN Money',
    'moov_money' => 'Moov Money',
];

$pageTitle = 'Retrait #' . $withdrawalId;
require_once __DIR__ . '/../includes/vendor_header.php';

$stmt = get_db()->prepare("
    SELECT * FROM wallet_transactions
    WHERE reference_type = 'withdrawal' AND reference_id = :id AND vendor_id = :vendor_id
    ORDER BY created_at ASC
");
$stmt->execute(['id' => $withdrawalId, 'vendor_id' => (int) $vendor['id']]);
$transactions = $stmt->fetchAll();
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/vendeur/retraits.php" class="link-more"><?= icon('chevron-right', 14) ?> Retour aux retraits</a>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Retrait #<?= $withdrawalId ?></h2>
        <span class="tag <?= wallet_withdrawal_status_tag_class($withdrawal['status']) ?>"><?= e(wallet_withdrawal_status_label($withdrawal['status'])) ?></span>
    </div>
    <ul class="account-info-list">
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
            <li><span class="account-info-label"><?= icon('user', 16) ?> Note de l'équipe ManMarket</span><span><?= e($withdrawal['admin_note']) ?></span></li>
        <?php endif; ?>
    </ul>

    <?php if (in_array($withdrawal['status'], ['PENDING', 'APPROVED', 'PROCESSING'], true)): ?>
        <p class="char-count" style="margin-top:8px;">Cette demande est en cours de traitement par l'équipe ManMarket. Vous serez notifié dès qu'elle sera payée.</p>
    <?php elseif ($withdrawal['status'] === 'REJECTED' || $withdrawal['status'] === 'FAILED'): ?>
        <p class="char-count" style="margin-top:8px;">Le montant demandé n'a pas été débité de votre solde disponible et reste utilisable pour une nouvelle demande.</p>
    <?php endif; ?>
</div>

<div class="card">
    <div class="admin-toolbar">
        <h2>Mouvements de portefeuille liés (<?= count($transactions) ?>)</h2>
    </div>
    <?php if (!$transactions): ?>
        <p class="empty-state">Aucun mouvement de portefeuille pour l'instant — le solde n'est débité qu'au moment où le paiement est confirmé par l'équipe ManMarket.</p>
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

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
