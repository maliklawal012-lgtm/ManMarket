<?php
declare(strict_types=1);

$pageTitle = 'Tableau de bord';
require_once __DIR__ . '/../includes/admin_header.php';
require_once __DIR__ . '/../includes/geniuspay.php';
require_once __DIR__ . '/../includes/wallet_bootstrap.php';

$db = get_db();

$geniuspayAccount = geniuspay_request('GET', '/account');

$pendingOrders = (int) $db->query("SELECT COUNT(*) FROM orders WHERE fulfillment_status = 'pending'")->fetchColumn();
$pendingMessages = (int) $db->query("SELECT COUNT(*) FROM contact_messages WHERE subject != 'Commande' AND status = 'pending'")->fetchColumn();
$productCount = (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn();
$userCount = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
// Retraits en attente : table withdrawals (nouvelle architecture wallet), pas
// l'ancienne withdrawal_requests desormais morte (plus aucune commande n'y ecrit).
$pendingWithdrawals = (int) $db->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'PENDING'")->fetchColumn();
$walletHeld = (int) round((float) $db->query('SELECT COALESCE(SUM(available_balance + pending_balance), 0) FROM wallets')->fetchColumn());
$unresolvedFailures = (int) $db->query('SELECT COUNT(*) FROM settlement_failures WHERE resolved = 0')->fetchColumn();

$subscriptionsToWatch = (int) $db->query("
    SELECT COUNT(*) FROM shops s
    WHERE NOT EXISTS (
        SELECT 1 FROM shop_subscriptions ss
        WHERE ss.shop_id = s.id AND CURDATE() BETWEEN ss.starts_at AND ss.ends_at AND DATEDIFF(ss.ends_at, CURDATE()) > 7
    )
")->fetchColumn();

/* ---------- Chiffre d'affaires livre (donnees reelles, order_items) ---------- */
$deliveredRevenue = (int) $db->query("
    SELECT COALESCE(SUM(oi.unit_price * oi.quantity), 0)
    FROM order_items oi
    WHERE oi.fulfillment_status = 'delivered'
")->fetchColumn();

/* ---------- Activite recente (donnees reelles, sources multiples) ---------- */
$activity = array_slice(get_recent_activity(4), 0, 7);

/* ---------- Commandes des 7 derniers jours + dernieres commandes ---------- */
$stmt = $db->prepare("
    SELECT DATE(created_at) AS day, COUNT(*) AS total
    FROM orders WHERE created_at >= :since
    GROUP BY DATE(created_at)
");
$stmt->execute(['since' => date('Y-m-d 00:00:00', strtotime('-6 days'))]);
$ordersByDay = array_column($stmt->fetchAll(), 'total', 'day');

$last7Days = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $last7Days[] = ['day' => $day, 'label' => date('d/m', strtotime($day)), 'count' => (int) ($ordersByDay[$day] ?? 0)];
}
$maxCount = max(array_merge([1], array_column($last7Days, 'count')));

$recentOrders = $db->query("
    SELECT o.*, COALESCE((SELECT p.status FROM payments p WHERE p.order_id = o.id ORDER BY p.id DESC LIMIT 1), NULL) AS payment_status_live
    FROM orders o
    ORDER BY o.created_at DESC
    LIMIT 5
")->fetchAll();
?>

<?php if ($unresolvedFailures > 0): ?>
    <div class="card" style="margin-bottom: var(--gap); border: 1px solid #fecaca;">
        <div class="admin-toolbar">
            <h2><?= icon('x', 18) ?> <?= $unresolvedFailures ?> échec(s) de rapprochement non résolu(s)</h2>
            <a href="/market/admin/finances" class="link-more">Voir le détail <?= icon('chevron-right', 14) ?></a>
        </div>
        <p class="char-count">Un paiement confirmé ne correspondait pas à la somme des parts vendeurs + commission — aucun crédit automatique n'a été effectué, l'argent reste intact.</p>
    </div>
<?php endif; ?>

<div class="admin-stats-grid admin-stats-grid-6">
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#e8f8ee; color:#16a34a;"><?= icon('cart', 18) ?></span>
        <span class="admin-stat-value"><?= format_price($deliveredRevenue) ?></span>
        <span class="admin-stat-label">Chiffre d'affaires (livré)</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#e8f8ee; color:#16a34a;"><?= icon('cart', 18) ?></span>
        <span class="admin-stat-value"><?= $pendingOrders ?></span>
        <span class="admin-stat-label">Commande(s) en attente</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#eef2ff; color:#4f46e5;"><?= icon('send', 18) ?></span>
        <span class="admin-stat-value"><?= $pendingMessages ?></span>
        <span class="admin-stat-label">Message(s) non traité(s)</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fff7ed; color:#d97706;"><?= icon('shopping-basket', 18) ?></span>
        <span class="admin-stat-value"><?= $productCount ?></span>
        <span class="admin-stat-label">Produit(s) en ligne</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fdf2f8; color:#db2777;"><?= icon('user', 18) ?></span>
        <span class="admin-stat-value"><?= $userCount ?></span>
        <span class="admin-stat-label">Utilisateur(s) inscrit(s)</span>
    </div>
    <a href="/market/admin/retraits?status=PENDING" class="card admin-stat-card" style="text-decoration:none; color:inherit;">
        <span class="admin-stat-icon" style="background:#fef3c7; color:#92400e;"><?= icon('shield', 18) ?></span>
        <span class="admin-stat-value"><?= $pendingWithdrawals ?></span>
        <span class="admin-stat-label">Retrait(s) en attente</span>
    </a>
    <a href="/market/admin/abonnements" class="card admin-stat-card" style="text-decoration:none; color:inherit;">
        <span class="admin-stat-icon" style="background:#fee2e2; color:#b91c1c;"><?= icon('clock', 18) ?></span>
        <span class="admin-stat-value"><?= $subscriptionsToWatch ?></span>
        <span class="admin-stat-label">Abonnement(s) à surveiller</span>
    </a>
    <a href="/market/admin/finances" class="card admin-stat-card" style="text-decoration:none; color:inherit;">
        <span class="admin-stat-icon" style="background:#e0e7ff; color:#4338ca;"><?= icon('bar-chart', 18) ?></span>
        <span class="admin-stat-value"><?= format_price($walletHeld) ?></span>
        <span class="admin-stat-label">Détenu dans les wallets vendeurs</span>
    </a>
</div>

<?php if ($geniuspayAccount['ok']): $gpData = $geniuspayAccount['body']['data']; ?>
    <div class="card" style="margin-bottom: var(--gap);">
        <div class="admin-toolbar">
            <h2><?= icon('cart', 18) ?> Compte Genius Pay (<?= e((string) ($gpData['environment'] ?? '?')) ?>)</h2>
            <span class="tag <?= ($gpData['status'] ?? '') === 'active' ? 'tag-open' : 'tag-closed' ?>"><?= e((string) ($gpData['status'] ?? '?')) ?></span>
        </div>
        <div class="admin-stats-grid admin-stats-grid-3">
            <div>
                <span class="admin-stat-value" style="font-size:1.3rem;"><?= format_price((int) ($gpData['balance']['available'] ?? 0)) ?></span>
                <span class="admin-stat-label">Solde disponible</span>
            </div>
            <div>
                <span class="admin-stat-value" style="font-size:1.3rem;"><?= format_price((int) ($gpData['balance']['pending'] ?? 0)) ?></span>
                <span class="admin-stat-label">Solde en attente</span>
            </div>
            <div>
                <span class="admin-stat-value" style="font-size:1.3rem;"><?= format_price((int) ($gpData['limits']['available'] ?? 0)) ?></span>
                <span class="admin-stat-label">Plafond mensuel restant</span>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="admin-dashboard-grid">
    <div class="col">
        <div class="card" style="margin-bottom: var(--gap);">
            <div class="admin-toolbar">
                <h2>Commandes des 7 derniers jours</h2>
            </div>
            <div class="admin-bars">
                <?php foreach ($last7Days as $day): ?>
                    <div class="admin-bar-col">
                        <div class="admin-bar" style="height: <?= max(6, (int) round($day['count'] / $maxCount * 100)) ?>%;">
                            <span class="admin-bar-value"><?= $day['count'] ?></span>
                        </div>
                        <span class="admin-bar-label"><?= e($day['label']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <div class="admin-toolbar">
                <h2>Dernières commandes</h2>
                <a href="/market/admin/commandes-actives" class="link-more">Voir toutes <?= icon('chevron-right', 14) ?></a>
            </div>

            <?php if (!$recentOrders): ?>
                <p class="empty-state">Aucune commande pour le moment.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Client</th>
                                <th>Total</th>
                                <th>Statut</th>
                                <th>Paiement</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentOrders as $order): ?>
                                <tr>
                                    <td><?= e(date('d/m/Y H:i', strtotime((string) $order['created_at']))) ?></td>
                                    <td><?= e($order['customer_name']) ?></td>
                                    <td><?= format_price((int) round((float) $order['total_amount'])) ?></td>
                                    <td><span class="tag <?= order_status_tag_class($order['fulfillment_status']) ?>"><?= e(order_status_label($order['fulfillment_status'])) ?></span></td>
                                    <td>
                                        <?php if ($order['payment_status_live']): ?>
                                            <span class="tag <?= payment_status_tag_class($order['payment_status_live']) ?>"><?= e(payment_status_label($order['payment_status_live'])) ?></span>
                                        <?php else: ?>
                                            <span class="char-count">À la livraison</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="admin-toolbar">
            <h2>Activité récente</h2>
            <a href="/market/admin/activite" class="link-more">Tout voir <?= icon('chevron-right', 14) ?></a>
        </div>

        <?php if (!$activity): ?>
            <p class="empty-state">Aucune activité pour le moment.</p>
        <?php else: ?>
            <div class="admin-activity-list">
                <?php foreach ($activity as $item): ?>
                    <div class="admin-activity-item">
                        <span class="admin-activity-icon"><?= icon($item['icon'], 15) ?></span>
                        <div>
                            <div class="admin-activity-text"><?= e($item['text']) ?></div>
                            <div class="admin-activity-time"><?= e(date('d/m/Y H:i', strtotime((string) $item['time']))) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
