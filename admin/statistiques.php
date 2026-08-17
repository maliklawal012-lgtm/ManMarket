<?php
declare(strict_types=1);

$pageTitle = 'Rapports & Statistiques';
require_once __DIR__ . '/../includes/admin_header.php';

$db = get_db();

/* ---------- Statistiques detaillees d'une boutique (choisie par l'admin) ---------- */
$allShops = $db->query('SELECT id, name FROM shops ORDER BY name')->fetchAll();
$selectedShopId = (int) ($_GET['shop_id'] ?? 0);
$selectedShop = null;
$shopStats = null;

if ($selectedShopId > 0) {
    $stmt = $db->prepare('SELECT * FROM shops WHERE id = :id');
    $stmt->execute(['id' => $selectedShopId]);
    $selectedShop = $stmt->fetch() ?: null;
}

if ($selectedShop) {
    $shopId = (int) $selectedShop['id'];

    $stmt = $db->prepare("
        SELECT COALESCE(SUM(oi.unit_price * oi.quantity), 0) AS revenue, COALESCE(SUM(oi.quantity), 0) AS items_sold
        FROM order_items oi
        WHERE oi.shop_id = :shop_id AND oi.fulfillment_status = 'delivered'
    ");
    $stmt->execute(['shop_id' => $shopId]);
    $shopDelivered = $stmt->fetch();

    $stmt = $db->prepare('
        SELECT COUNT(DISTINCT o.id) AS order_count, COUNT(DISTINCT o.customer_email) AS customer_count
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        WHERE oi.shop_id = :shop_id
    ');
    $stmt->execute(['shop_id' => $shopId]);
    $shopOverall = $stmt->fetch();

    $stmt = $db->prepare("
        SELECT YEARWEEK(o.created_at, 3) AS yw, COUNT(DISTINCT o.id) AS total
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        WHERE oi.shop_id = :shop_id AND o.created_at >= :since
        GROUP BY yw
    ");
    $stmt->execute(['shop_id' => $shopId, 'since' => date('Y-m-d 00:00:00', strtotime('-8 weeks'))]);
    $shopOrdersByWeek = array_column($stmt->fetchAll(), 'total', 'yw');

    $shopWeeklyOrders = [];
    for ($i = 7; $i >= 0; $i--) {
        $ts = strtotime("-{$i} weeks");
        $yw = (int) date('o', $ts) * 100 + (int) date('W', $ts);
        $shopWeeklyOrders[] = ['label' => 'S' . date('W', $ts), 'count' => (int) ($shopOrdersByWeek[$yw] ?? 0)];
    }
    $shopMaxWeeklyOrders = max(array_merge([1], array_column($shopWeeklyOrders, 'count')));

    $stmt = $db->prepare('
        SELECT product_name, SUM(quantity) AS total_qty
        FROM order_items
        WHERE shop_id = :shop_id
        GROUP BY product_name
        ORDER BY total_qty DESC
        LIMIT 5
    ');
    $stmt->execute(['shop_id' => $shopId]);
    $shopTopProducts = $stmt->fetchAll();
    $shopMaxTopProduct = max(array_merge([1], array_column($shopTopProducts, 'total_qty')));

    $shopStats = true;
}

/* ---------- Commandes par semaine (8 dernieres semaines) ---------- */
$stmt = $db->prepare("
    SELECT YEARWEEK(created_at, 3) AS yw, COUNT(*) AS total
    FROM contact_messages
    WHERE subject = 'Commande' AND created_at >= :since
    GROUP BY yw
");
$stmt->execute(['since' => date('Y-m-d 00:00:00', strtotime('-8 weeks'))]);
$ordersByWeek = array_column($stmt->fetchAll(), 'total', 'yw');

$weeklyOrders = [];
for ($i = 7; $i >= 0; $i--) {
    $ts = strtotime("-{$i} weeks");
    $yw = (int) date('o', $ts) * 100 + (int) date('W', $ts);
    $weeklyOrders[] = ['label' => 'S' . date('W', $ts), 'count' => (int) ($ordersByWeek[$yw] ?? 0)];
}
$maxWeeklyOrders = max(array_merge([1], array_column($weeklyOrders, 'count')));

/* ---------- Inscriptions par semaine (8 dernieres semaines) ---------- */
$stmt = $db->prepare('
    SELECT YEARWEEK(created_at, 3) AS yw, COUNT(*) AS total
    FROM users
    WHERE created_at >= :since
    GROUP BY yw
');
$stmt->execute(['since' => date('Y-m-d 00:00:00', strtotime('-8 weeks'))]);
$usersByWeek = array_column($stmt->fetchAll(), 'total', 'yw');

$weeklyUsers = [];
for ($i = 7; $i >= 0; $i--) {
    $ts = strtotime("-{$i} weeks");
    $yw = (int) date('o', $ts) * 100 + (int) date('W', $ts);
    $weeklyUsers[] = ['label' => 'S' . date('W', $ts), 'count' => (int) ($usersByWeek[$yw] ?? 0)];
}
$maxWeeklyUsers = max(array_merge([1], array_column($weeklyUsers, 'count')));

/* ---------- Produits par categorie ---------- */
$productsByCategory = $db->query('
    SELECT c.name, c.color, COUNT(p.id) AS total
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    GROUP BY c.id
    ORDER BY total DESC
')->fetchAll();
$maxByCategory = max(array_merge([1], array_column($productsByCategory, 'total')));

/* ---------- Produits par boutique ---------- */
$productsByShop = $db->query('
    SELECT s.name, s.color, COUNT(p.id) AS total
    FROM shops s
    LEFT JOIN products p ON p.shop_id = s.id
    GROUP BY s.id
    ORDER BY total DESC
')->fetchAll();
$maxByShop = max(array_merge([1], array_column($productsByShop, 'total')));

/* ---------- Chiffre d'affaires par boutique (commandes livrees) ---------- */
$revenueByShop = $db->query("
    SELECT s.name, s.color,
        COALESCE(SUM(CASE WHEN oi.fulfillment_status = 'delivered' THEN oi.unit_price * oi.quantity ELSE 0 END), 0) AS revenue
    FROM shops s
    LEFT JOIN order_items oi ON oi.shop_id = s.id
    GROUP BY s.id
    ORDER BY revenue DESC
")->fetchAll();
$maxRevenueByShop = max(array_merge([1], array_column($revenueByShop, 'revenue')));

/* ---------- Messages (hors commandes) : statut ---------- */
$pendingCount = (int) $db->query("SELECT COUNT(*) FROM contact_messages WHERE subject != 'Commande' AND status = 'pending'")->fetchColumn();
$processedCount = (int) $db->query("SELECT COUNT(*) FROM contact_messages WHERE subject != 'Commande' AND status = 'processed'")->fetchColumn();
$totalMessages = max(1, $pendingCount + $processedCount);

/* ---------- Repartition des commandes par statut ---------- */
$orderStatuses = ['pending', 'processing', 'shipping', 'delivered', 'cancelled'];
$stmt = $db->query("SELECT status, COUNT(*) AS total FROM contact_messages WHERE subject = 'Commande' GROUP BY status");
$ordersByStatus = array_column($stmt->fetchAll(), 'total', 'status');
$totalOrders = max(1, array_sum($ordersByStatus));
$orderStatusColors = [
    'pending' => '#d97706', 'processing' => '#4338ca', 'shipping' => '#1d4ed8',
    'delivered' => '#16a34a', 'cancelled' => '#dc2626',
];
?>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Statistiques d'une boutique</h2>
        <div class="filter-sort">
            <label for="shop-stats-filter">Boutique</label>
            <select id="shop-stats-filter" onchange="location.href = this.value">
                <option value="/market/admin/statistiques.php" <?= $selectedShopId === 0 ? 'selected' : '' ?>>Choisir une boutique...</option>
                <?php foreach ($allShops as $s): ?>
                    <option value="/market/admin/statistiques.php?shop_id=<?= (int) $s['id'] ?>" <?= $selectedShopId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if (!$selectedShop): ?>
        <p class="empty-state">Sélectionnez une boutique pour voir ses statistiques détaillées.</p>
    <?php else: ?>
        <div class="admin-stats-grid">
            <div class="card admin-stat-card">
                <span class="admin-stat-icon" style="background:#e8f8ee; color:#16a34a;"><?= icon('cart', 18) ?></span>
                <span class="admin-stat-value"><?= format_price((int) $shopDelivered['revenue']) ?></span>
                <span class="admin-stat-label">Chiffre d'affaires (commandes livrées)</span>
            </div>
            <div class="card admin-stat-card">
                <span class="admin-stat-icon" style="background:#fff7ed; color:#d97706;"><?= icon('shopping-basket', 18) ?></span>
                <span class="admin-stat-value"><?= (int) $shopDelivered['items_sold'] ?></span>
                <span class="admin-stat-label">Articles vendus (livrés)</span>
            </div>
            <div class="card admin-stat-card">
                <span class="admin-stat-icon" style="background:#eef2ff; color:#4f46e5;"><?= icon('send', 18) ?></span>
                <span class="admin-stat-value"><?= (int) $shopOverall['order_count'] ?></span>
                <span class="admin-stat-label">Commande(s) reçue(s) au total</span>
            </div>
            <div class="card admin-stat-card">
                <span class="admin-stat-icon" style="background:#fdf2f8; color:#db2777;"><?= icon('user', 18) ?></span>
                <span class="admin-stat-value"><?= (int) $shopOverall['customer_count'] ?></span>
                <span class="admin-stat-label">Client(s) unique(s)</span>
            </div>
        </div>

        <div class="admin-dashboard-grid">
            <div class="card">
                <div class="admin-toolbar">
                    <h2>Commandes par semaine (8 dernières semaines)</h2>
                </div>
                <?php if ((int) $shopOverall['order_count'] === 0): ?>
                    <p class="empty-state">Aucune commande pour le moment.</p>
                <?php else: ?>
                    <div class="admin-bars">
                        <?php foreach ($shopWeeklyOrders as $week): ?>
                            <div class="admin-bar-col">
                                <div class="admin-bar" style="height: <?= max(6, (int) round($week['count'] / $shopMaxWeeklyOrders * 100)) ?>%;">
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
                <?php if (!$shopTopProducts): ?>
                    <p class="empty-state">Aucune vente pour le moment.</p>
                <?php else: ?>
                    <div class="admin-hbar-list">
                        <?php foreach ($shopTopProducts as $row): ?>
                            <div class="admin-hbar-row">
                                <span class="admin-hbar-label"><?= e($row['product_name']) ?></span>
                                <div class="admin-hbar-track"><div class="admin-hbar-fill" style="width:<?= max(4, (int) round($row['total_qty'] / $shopMaxTopProduct * 100)) ?>%; background:#16a34a;"></div></div>
                                <span class="admin-hbar-value"><?= (int) $row['total_qty'] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <h2>Vue d'ensemble (toutes les boutiques)</h2>
</div>

<div class="admin-dashboard-grid">
    <div class="col">
        <div class="card" style="margin-bottom: var(--gap);">
            <div class="admin-toolbar">
                <h2>Commandes par semaine (8 dernières semaines)</h2>
            </div>
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
        </div>

        <div class="card">
            <div class="admin-toolbar">
                <h2>Nouvelles inscriptions par semaine</h2>
            </div>
            <div class="admin-bars">
                <?php foreach ($weeklyUsers as $week): ?>
                    <div class="admin-bar-col">
                        <div class="admin-bar" style="height: <?= max(6, (int) round($week['count'] / $maxWeeklyUsers * 100)) ?>%;">
                            <span class="admin-bar-value"><?= $week['count'] ?></span>
                        </div>
                        <span class="admin-bar-label"><?= e($week['label']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col">
        <div class="card" style="margin-bottom: var(--gap);">
            <div class="admin-toolbar">
                <h2>Produits par catégorie</h2>
            </div>
            <div class="admin-hbar-list">
                <?php foreach ($productsByCategory as $row): ?>
                    <div class="admin-hbar-row">
                        <span class="admin-hbar-label"><?= e($row['name']) ?></span>
                        <div class="admin-hbar-track"><div class="admin-hbar-fill" style="width:<?= max(4, (int) round($row['total'] / $maxByCategory * 100)) ?>%; background:<?= e($row['color']) ?>;"></div></div>
                        <span class="admin-hbar-value"><?= (int) $row['total'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card" style="margin-bottom: var(--gap);">
            <div class="admin-toolbar">
                <h2>Produits par boutique</h2>
            </div>
            <div class="admin-hbar-list">
                <?php foreach ($productsByShop as $row): ?>
                    <div class="admin-hbar-row">
                        <span class="admin-hbar-label"><?= e($row['name']) ?></span>
                        <div class="admin-hbar-track"><div class="admin-hbar-fill" style="width:<?= max(4, (int) round($row['total'] / $maxByShop * 100)) ?>%; background:<?= e($row['color']) ?>;"></div></div>
                        <span class="admin-hbar-value"><?= (int) $row['total'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card" style="margin-bottom: var(--gap);">
            <div class="admin-toolbar">
                <h2>Chiffre d'affaires par boutique (livré)</h2>
            </div>
            <div class="admin-hbar-list">
                <?php foreach ($revenueByShop as $row): ?>
                    <div class="admin-hbar-row">
                        <span class="admin-hbar-label"><?= e($row['name']) ?></span>
                        <div class="admin-hbar-track"><div class="admin-hbar-fill" style="width:<?= max(4, (int) round($row['revenue'] / $maxRevenueByShop * 100)) ?>%; background:<?= e($row['color']) ?>;"></div></div>
                        <span class="admin-hbar-value"><?= format_price((int) $row['revenue']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card" style="margin-bottom: var(--gap);">
            <div class="admin-toolbar">
                <h2>Répartition des commandes</h2>
            </div>
            <div class="admin-hbar-list">
                <?php foreach ($orderStatuses as $s): $count = (int) ($ordersByStatus[$s] ?? 0); ?>
                    <div class="admin-hbar-row">
                        <span class="admin-hbar-label"><?= e(order_status_label($s)) ?></span>
                        <div class="admin-hbar-track"><div class="admin-hbar-fill" style="width:<?= max(4, (int) round($count / $totalOrders * 100)) ?>%; background:<?= e($orderStatusColors[$s]) ?>;"></div></div>
                        <span class="admin-hbar-value"><?= $count ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <div class="admin-toolbar">
                <h2>Messages — statut</h2>
            </div>
            <div class="admin-hbar-list">
                <div class="admin-hbar-row">
                    <span class="admin-hbar-label">En attente</span>
                    <div class="admin-hbar-track"><div class="admin-hbar-fill" style="width:<?= max(4, (int) round($pendingCount / $totalMessages * 100)) ?>%; background:#d97706;"></div></div>
                    <span class="admin-hbar-value"><?= $pendingCount ?></span>
                </div>
                <div class="admin-hbar-row">
                    <span class="admin-hbar-label">Traités</span>
                    <div class="admin-hbar-track"><div class="admin-hbar-fill" style="width:<?= max(4, (int) round($processedCount / $totalMessages * 100)) ?>%; background:#16a34a;"></div></div>
                    <span class="admin-hbar-value"><?= $processedCount ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
