<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wallet_bootstrap.php';

$adminUser = require_admin();

$db = get_db();
$commissionId = (int) ($_GET['id'] ?? 0);
$commission = wallet_commission_repo()->findById($commissionId);

if (!$commission) {
    header('Location: /market/admin/commissions');
    exit;
}

$pageTitle = 'Commission #' . $commissionId;
require_once __DIR__ . '/../includes/admin_header.php';

$stmt = $db->prepare('SELECT * FROM order_items WHERE id = :id');
$stmt->execute(['id' => (int) $commission['order_item_id']]);
$orderItem = $stmt->fetch() ?: null;

$stmt = $db->prepare('SELECT * FROM orders WHERE id = :id');
$stmt->execute(['id' => (int) $commission['order_id']]);
$order = $stmt->fetch() ?: null;

$stmt = $db->prepare('
    SELECT r.reason, r.created_at, ri.quantity, ri.refunded_gross_amount, ri.refunded_commission_amount, ri.refunded_net_amount
    FROM refund_items ri
    JOIN refunds r ON r.id = ri.refund_id
    WHERE ri.order_item_id = :order_item_id
    ORDER BY ri.created_at DESC
');
$stmt->execute(['order_item_id' => (int) $commission['order_item_id']]);
$refundItems = $stmt->fetchAll();
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/admin/commissions" class="link-more"><?= icon('chevron-right', 14) ?> Retour aux commissions</a>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Commission #<?= $commissionId ?></h2>
        <span class="tag <?= commission_status_tag_class($commission['status']) ?>"><?= e(commission_status_label($commission['status'])) ?></span>
    </div>
    <ul class="account-info-list">
        <li><span class="account-info-label"><?= icon('store', 16) ?> Boutique</span><span><a href="/market/admin/boutique-detail?id=<?= (int) $commission['shop_id'] ?>" class="link-muted"><?= e($commission['shop_name']) ?></a> — <?= e($commission['business_name']) ?></span></li>
        <li><span class="account-info-label"><?= icon('shopping-basket', 16) ?> Produit</span><span><?= e($commission['product_name']) ?></span></li>
        <li><span class="account-info-label"><?= icon('cart', 16) ?> Commande</span><span><a href="/market/admin/commande-detail?id=<?= (int) $commission['order_id'] ?>" class="link-muted">Commande #<?= (int) $commission['order_id'] ?></a><?php if ($order): ?> — <?= e(date('d/m/Y à H:i', strtotime((string) $order['created_at']))) ?><?php endif; ?></span></li>
        <li><span class="account-info-label"><?= icon('clock', 16) ?> Créée le</span><span><?= e(date('d/m/Y à H:i', strtotime((string) $commission['created_at']))) ?></span></li>
        <?php if ($commission['applied_at']): ?>
            <li><span class="account-info-label"><?= icon('check-circle', 16) ?> Appliquée le</span><span><?= e(date('d/m/Y à H:i', strtotime((string) $commission['applied_at']))) ?></span></li>
        <?php endif; ?>
        <?php if ($commission['reversed_at']): ?>
            <li><span class="account-info-label"><?= icon('x', 16) ?> Dernière annulation le</span><span><?= e(date('d/m/Y à H:i', strtotime((string) $commission['reversed_at']))) ?></span></li>
        <?php endif; ?>
    </ul>
</div>

<div class="admin-stats-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: var(--gap);">
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#eef2ff; color:#4f46e5;"><?= icon('cart', 18) ?></span>
        <span class="admin-stat-value"><?= format_price((int) round((float) $commission['gross_amount'])) ?></span>
        <span class="admin-stat-label">Montant brut de la ligne</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#f3f4f6; color:#374151;"><?= icon('shield', 18) ?></span>
        <span class="admin-stat-value"><?= number_format((float) $commission['commission_rate'], 1) ?>%</span>
        <span class="admin-stat-label">Taux appliqué (figé à la commande)</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#e8f8ee; color:#16a34a;"><?= icon('check-circle', 18) ?></span>
        <span class="admin-stat-value"><?= format_price((int) round((float) $commission['commission_amount'])) ?></span>
        <span class="admin-stat-label">Commission ManMarket</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fee2e2; color:#b91c1c;"><?= icon('x', 18) ?></span>
        <span class="admin-stat-value"><?= format_price((int) round((float) $commission['reversed_amount'])) ?></span>
        <span class="admin-stat-label">Commission annulée</span>
    </div>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Répartition</h2>
    </div>
    <ul class="account-info-list">
        <li><span class="account-info-label">Montant brut</span><span><?= format_price((int) round((float) $commission['gross_amount'])) ?></span></li>
        <li><span class="account-info-label">− Commission ManMarket</span><span><?= format_price((int) round((float) $commission['commission_amount'])) ?></span></li>
        <li><span class="account-info-label">= Net vendeur (au moment du crédit)</span><span><?= format_price((int) round((float) $commission['net_amount'])) ?></span></li>
    </ul>
</div>

<?php if ($refundItems): ?>
    <div class="card">
        <div class="admin-toolbar">
            <h2>Remboursements liés à cet article (<?= count($refundItems) ?>)</h2>
        </div>
        <div class="admin-activity-list">
            <?php foreach ($refundItems as $ri): ?>
                <div class="admin-activity-item">
                    <span class="admin-activity-icon"><?= icon('x', 15) ?></span>
                    <div>
                        <div class="admin-activity-text">
                            <?= (int) $ri['quantity'] ?> unité(s) — <?= format_price((int) round((float) $ri['refunded_gross_amount'])) ?>
                            (commission annulée : <?= format_price((int) round((float) $ri['refunded_commission_amount'])) ?>, net vendeur déduit : <?= format_price((int) round((float) $ri['refunded_net_amount'])) ?>)
                        </div>
                        <div class="admin-activity-time"><?= e($ri['reason']) ?> · <?= e(date('d/m/Y à H:i', strtotime((string) $ri['created_at']))) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <p class="empty-state">Aucun remboursement sur cet article — la commission reste intégralement appliquée.</p>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
