<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wallet_bootstrap.php';

$vendorUser = require_vendor();
$vendorShop = current_vendor_shop((int) $vendorUser['id']);

if (!$vendorShop) {
    header('Location: /market/vendeur/index');
    exit;
}

$db = get_db();
$shopId = (int) $vendorShop['id'];
$vendorEntity = wallet_vendor_repo()->findByUserId((int) $vendorUser['id']);
$vendorId = $vendorEntity ? (int) $vendorEntity['id'] : 0;

// Export CSV des commandes — avant tout envoi de HTML.
if (($_GET['export'] ?? '') === 'orders') {
    $rows = $db->query("
        SELECT o.created_at, o.id AS order_id, oi.product_name, oi.quantity, oi.unit_price, oi.subtotal, o.fulfillment_status, o.customer_name
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE oi.shop_id = " . $shopId . "
        ORDER BY o.created_at DESC
    ")->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="commandes-' . $shopId . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Commande', 'Produit', 'Quantite', 'Prix unitaire', 'Sous-total', 'Statut', 'Client']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['created_at'], $r['order_id'], $r['product_name'], $r['quantity'], $r['unit_price'], $r['subtotal'], order_status_label($r['fulfillment_status']), $r['customer_name']]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Statistiques';
require_once __DIR__ . '/../includes/vendor_header.php';

/* ---------- Chiffre d'affaires livre (donnees reelles, commandes livrees uniquement) ---------- */
$stmt = $db->prepare("
    SELECT COALESCE(SUM(oi.unit_price * oi.quantity), 0) AS revenue, COALESCE(SUM(oi.quantity), 0) AS items_sold
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE oi.shop_id = :shop_id AND o.fulfillment_status = 'delivered'
");
$stmt->execute(['shop_id' => $shopId]);
$delivered = $stmt->fetch();

/* ---------- Commandes recues (toutes, quel que soit le statut) ---------- */
$stmt = $db->prepare('
    SELECT COUNT(DISTINCT o.id) AS order_count, COUNT(DISTINCT o.customer_email) AS customer_count
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    WHERE oi.shop_id = :shop_id
');
$stmt->execute(['shop_id' => $shopId]);
$overall = $stmt->fetch();

/* ---------- Commandes par semaine (8 dernieres semaines) ---------- */
$stmt = $db->prepare("
    SELECT YEARWEEK(o.created_at, 3) AS yw, COUNT(DISTINCT o.id) AS total
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    WHERE oi.shop_id = :shop_id AND o.created_at >= :since
    GROUP BY yw
");
$stmt->execute(['shop_id' => $shopId, 'since' => date('Y-m-d 00:00:00', strtotime('-8 weeks'))]);
$ordersByWeek = array_column($stmt->fetchAll(), 'total', 'yw');

$weeklyOrders = [];
for ($i = 7; $i >= 0; $i--) {
    $ts = strtotime("-{$i} weeks");
    $yw = (int) date('o', $ts) * 100 + (int) date('W', $ts);
    $weeklyOrders[] = ['label' => 'S' . date('W', $ts), 'count' => (int) ($ordersByWeek[$yw] ?? 0)];
}
$maxWeeklyOrders = max(array_merge([1], array_column($weeklyOrders, 'count')));

/* ---------- Produits les plus vendus (toutes commandes, quel que soit le statut) ---------- */
$stmt = $db->prepare('
    SELECT product_name, SUM(quantity) AS total_qty
    FROM order_items
    WHERE shop_id = :shop_id
    GROUP BY product_name
    ORDER BY total_qty DESC
    LIMIT 5
');
$stmt->execute(['shop_id' => $shopId]);
$topProducts = $stmt->fetchAll();
$maxTopProduct = max(array_merge([1], array_column($topProducts, 'total_qty')));

/* ---------- Chiffre d'affaires par semaine, commandes livrees (8 dernieres semaines) ---------- */
$stmt = $db->prepare("
    SELECT YEARWEEK(o.created_at, 3) AS yw, COALESCE(SUM(oi.unit_price * oi.quantity), 0) AS revenue
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE oi.shop_id = :shop_id AND o.fulfillment_status = 'delivered' AND o.created_at >= :since
    GROUP BY yw
");
$stmt->execute(['shop_id' => $shopId, 'since' => date('Y-m-d 00:00:00', strtotime('-8 weeks'))]);
$revenueByWeekRaw = array_column($stmt->fetchAll(), 'revenue', 'yw');

$weeklyRevenue = [];
for ($i = 7; $i >= 0; $i--) {
    $ts = strtotime("-{$i} weeks");
    $yw = (int) date('o', $ts) * 100 + (int) date('W', $ts);
    $weeklyRevenue[] = ['label' => 'S' . date('W', $ts), 'amount' => (int) round((float) ($revenueByWeekRaw[$yw] ?? 0))];
}
$maxWeeklyRevenue = max(array_merge([1], array_column($weeklyRevenue, 'amount')));

/* ---------- Paiements en ligne par moyen (part de cette boutique dans chaque paiement) ---------- */
$paymentMethodLabels = ['wave' => 'Wave', 'orange_money' => 'Orange Money', 'mtn_money' => 'MTN Money', 'moov_money' => 'Moov Money', 'card' => 'Carte bancaire'];
$paymentMethodColors = ['wave' => '#00d4ff', 'orange_money' => '#ff6600', 'mtn_money' => '#ffcc00', 'moov_money' => '#0066cc', 'card' => '#1f2937'];
$stmt = $db->prepare("
    SELECT p.payment_method, COUNT(DISTINCT p.id) AS cnt, COALESCE(SUM(oi.subtotal), 0) AS total
    FROM payments p
    JOIN order_items oi ON oi.order_id = p.order_id AND oi.shop_id = :shop_id
    WHERE p.status = 'completed' AND p.payment_method IS NOT NULL
    GROUP BY p.payment_method
    ORDER BY total DESC
");
$stmt->execute(['shop_id' => $shopId]);
$paymentMethodRows = $stmt->fetchAll();
$maxPaymentMethodTotal = max(array_merge([1], array_column($paymentMethodRows, 'total')));

/* ---------- Remboursements touchant cette boutique ---------- */
$recentRefunds = [];
$totalRefunded = 0;
if ($vendorId > 0) {
    $stmt = $db->prepare('
        SELECT ri.refunded_gross_amount, ri.quantity, r.reason, r.created_at, o.customer_name, oi.product_name
        FROM refund_items ri
        JOIN refunds r ON r.id = ri.refund_id
        JOIN orders o ON o.id = r.order_id
        JOIN order_items oi ON oi.id = ri.order_item_id
        WHERE ri.vendor_id = :vendor_id
        ORDER BY ri.created_at DESC
        LIMIT 10
    ');
    $stmt->execute(['vendor_id' => $vendorId]);
    $recentRefunds = $stmt->fetchAll();

    $stmt = $db->prepare('SELECT COALESCE(SUM(refunded_gross_amount), 0) FROM refund_items WHERE vendor_id = :vendor_id');
    $stmt->execute(['vendor_id' => $vendorId]);
    $totalRefunded = (int) round((float) $stmt->fetchColumn());
}
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <h2>Statistiques</h2>
    <a href="/market/vendeur/statistiques?export=orders" class="btn btn-outline-primary btn-sm"><?= icon('send', 14) ?> Exporter mes commandes (CSV)</a>
</div>

<div class="admin-stats-grid">
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#e8f8ee; color:#16a34a;"><?= icon('cart', 18) ?></span>
        <span class="admin-stat-value"><?= format_price((int) $delivered['revenue']) ?></span>
        <span class="admin-stat-label">Chiffre d'affaires (commandes livrées)</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fff7ed; color:#d97706;"><?= icon('shopping-basket', 18) ?></span>
        <span class="admin-stat-value"><?= (int) $delivered['items_sold'] ?></span>
        <span class="admin-stat-label">Articles vendus (livrés)</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#eef2ff; color:#4f46e5;"><?= icon('send', 18) ?></span>
        <span class="admin-stat-value"><?= (int) $overall['order_count'] ?></span>
        <span class="admin-stat-label">Commande(s) reçue(s) au total</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fdf2f8; color:#db2777;"><?= icon('user', 18) ?></span>
        <span class="admin-stat-value"><?= (int) $overall['customer_count'] ?></span>
        <span class="admin-stat-label">Client(s) unique(s)</span>
    </div>
</div>

<p class="char-count">Le chiffre d'affaires ne compte que les commandes au statut « Livrée » ; les autres statuts sont des ventes en cours, pas encore confirmées.</p>

<div class="admin-dashboard-grid">
    <div class="card">
        <div class="admin-toolbar">
            <h2>Commandes par semaine (8 dernières semaines)</h2>
        </div>
        <?php if ((int) $overall['order_count'] === 0): ?>
            <p class="empty-state">Aucune commande pour le moment.</p>
        <?php else: ?>
            <div class="admin-bars">
                <?php foreach ($weeklyOrders as $week): ?>
                    <div class="admin-bar-col">
                        <div class="admin-bar" style="height: <?= max(6, (int) round($week['count'] / $maxWeeklyOrders * 100)) ?>%;">
                            <span class="admin-bar-value"><?= $week['count'] ?></span>
                        </div>
                        <span class="admin-bar-label"><?= e($week['label']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="admin-toolbar">
            <h2>Produits les plus commandés</h2>
        </div>
        <?php if (!$topProducts): ?>
            <p class="empty-state">Aucune vente pour le moment.</p>
        <?php else: ?>
            <div class="admin-hbar-list">
                <?php foreach ($topProducts as $row): ?>
                    <div class="admin-hbar-row">
                        <span class="admin-hbar-label"><?= e($row['product_name']) ?></span>
                        <div class="admin-hbar-track"><div class="admin-hbar-fill" style="width:<?= max(4, (int) round($row['total_qty'] / $maxTopProduct * 100)) ?>%; background:#16a34a;"></div></div>
                        <span class="admin-hbar-value"><?= (int) $row['total_qty'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="admin-dashboard-grid" style="margin-top: var(--gap);">
    <div class="card">
        <div class="admin-toolbar">
            <h2>Chiffre d'affaires par semaine</h2>
        </div>
        <?php if ((int) $delivered['revenue'] === 0): ?>
            <p class="empty-state">Aucune commande livrée pour le moment.</p>
        <?php else: ?>
            <div class="admin-bars">
                <?php foreach ($weeklyRevenue as $week): ?>
                    <div class="admin-bar-col">
                        <div class="admin-bar" style="height: <?= max(6, (int) round($week['amount'] / $maxWeeklyRevenue * 100)) ?>%;">
                            <span class="admin-bar-value"><?= number_format($week['amount'] / 1000, 0) ?>k</span>
                        </div>
                        <span class="admin-bar-label"><?= e($week['label']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="admin-toolbar">
            <h2>Paiements en ligne par moyen</h2>
        </div>
        <?php if (!$paymentMethodRows): ?>
            <p class="empty-state">Aucun paiement en ligne complété pour le moment.</p>
        <?php else: ?>
            <div class="admin-hbar-list">
                <?php foreach ($paymentMethodRows as $row): $method = $row['payment_method']; ?>
                    <div class="admin-hbar-row">
                        <span class="admin-hbar-label"><?= e($paymentMethodLabels[$method] ?? $method) ?></span>
                        <div class="admin-hbar-track"><div class="admin-hbar-fill" style="width:<?= max(4, (int) round($row['total'] / $maxPaymentMethodTotal * 100)) ?>%; background:<?= e($paymentMethodColors[$method] ?? '#16a34a') ?>;"></div></div>
                        <span class="admin-hbar-value"><?= format_price((int) round((float) $row['total'])) ?> (<?= (int) $row['cnt'] ?>)</span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-top: var(--gap);">
    <div class="admin-toolbar">
        <h2>Remboursements (<?= count($recentRefunds) ?>)</h2>
    </div>
    <?php if ($totalRefunded > 0): ?>
        <p class="char-count">Total remboursé sur vos ventes : <strong><?= format_price($totalRefunded) ?></strong>. Ce montant a déjà été retiré de votre portefeuille (voir « Retraits » pour le détail).</p>
    <?php endif; ?>
    <?php if (!$recentRefunds): ?>
        <p class="empty-state">Aucun remboursement sur vos ventes pour le moment.</p>
    <?php else: ?>
        <div class="admin-activity-list">
            <?php foreach ($recentRefunds as $r): ?>
                <div class="admin-activity-item">
                    <span class="admin-activity-icon"><?= icon('x', 15) ?></span>
                    <div>
                        <div class="admin-activity-text"><?= (int) $r['quantity'] ?> x <?= e($r['product_name']) ?> — <?= e($r['customer_name']) ?> — <?= format_price((int) round((float) $r['refunded_gross_amount'])) ?></div>
                        <div class="admin-activity-time"><?= e($r['reason']) ?> · <?= e(date('d/m/Y', strtotime((string) $r['created_at']))) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
