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

// Export CSV des paiements — avant tout envoi de HTML.
if (($_GET['export'] ?? '') === 'payments') {
    $rows = $db->query("
        SELECT p.created_at, p.provider_reference, o.customer_name, o.customer_email, p.amount, p.currency, p.payment_method, p.status
        FROM payments p JOIN orders o ON o.id = p.order_id
        ORDER BY p.created_at DESC
    ")->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="paiements-manmarket-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Reference', 'Client', 'Email', 'Montant', 'Devise', 'Moyen', 'Statut']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['created_at'], $r['provider_reference'], $r['customer_name'], $r['customer_email'], $r['amount'], $r['currency'], $r['payment_method'], $r['status']]);
    }
    fclose($out);
    exit;
}

$actionError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vendor_id'], $_POST['action'])) {
    $vendorId = (int) $_POST['vendor_id'];
    $adminId = (int) $adminUser['id'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    try {
        if ($_POST['action'] === 'suspend') {
            $reason = trim((string) ($_POST['reason'] ?? ''));
            wallet_vendor_admin_service()->suspend($vendorId, $adminId, $reason !== '' ? $reason : 'Suspendu par l\'administrateur', $ip);
        } elseif ($_POST['action'] === 'reactivate') {
            wallet_vendor_admin_service()->reactivate($vendorId, $adminId, $ip);
        }
        header('Location: /market/admin/finances.php');
        exit;
    } catch (\Throwable $e) {
        $actionError = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['failure_id'], $_POST['action']) && $_POST['action'] === 'resolve_failure') {
    $note = trim((string) ($_POST['note'] ?? ''));
    wallet_settlement_failure_repo()->resolve((int) $_POST['failure_id'], (int) $adminUser['id'], $note !== '' ? $note : 'Résolu par l\'administrateur');
    header('Location: /market/admin/finances.php');
    exit;
}

$pageTitle = 'Finances';
require_once __DIR__ . '/../includes/admin_header.php';

$totalRevenue = (int) round((float) $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE payment_status = 'paid'")->fetchColumn());
$totalOnlinePaymentFees = (int) round((float) $db->query("SELECT COALESCE(SUM(online_payment_fee_amount), 0) FROM orders WHERE payment_status = 'paid'")->fetchColumn());
$totalCommission = (int) round((float) $db->query("SELECT COALESCE(SUM(commission_amount - reversed_amount), 0) FROM commissions WHERE status != 'reversed'")->fetchColumn());
$totalWalletHeld = (int) round((float) $db->query("SELECT COALESCE(SUM(available_balance + pending_balance), 0) FROM wallets")->fetchColumn());
$totalWithdrawn = (int) round((float) $db->query("SELECT COALESCE(SUM(total_withdrawn), 0) FROM wallets")->fetchColumn());
$failedPaymentsCount = (int) $db->query("SELECT COUNT(*) FROM payments WHERE status IN ('failed', 'cancelled', 'expired')")->fetchColumn();
$pendingWithdrawalsCount = (int) $db->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'PENDING'")->fetchColumn();
$pendingWithdrawalsAmount = (int) round((float) $db->query("SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE status = 'PENDING'")->fetchColumn());
$settlementFailures = wallet_settlement_failure_repo()->findUnresolved();
$unresolvedFailures = count($settlementFailures);

$totalRefunded = (int) round((float) $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM refunds WHERE status = 'completed'")->fetchColumn());
$refundCount = (int) $db->query("SELECT COUNT(*) FROM refunds WHERE status = 'completed'")->fetchColumn();
$recentRefunds = $db->query("
    SELECT r.*, o.customer_name
    FROM refunds r JOIN orders o ON o.id = r.order_id
    ORDER BY r.created_at DESC
    LIMIT 10
")->fetchAll();

$paymentMethodLabels = ['wave' => 'Wave', 'orange_money' => 'Orange Money', 'mtn_money' => 'MTN Money', 'moov_money' => 'Moov Money', 'card' => 'Carte bancaire'];
$paymentMethodColors = ['wave' => '#00d4ff', 'orange_money' => '#ff6600', 'mtn_money' => '#ffcc00', 'moov_money' => '#0066cc', 'card' => '#1f2937'];
$paymentMethodRows = $db->query("
    SELECT payment_method, COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total
    FROM payments
    WHERE status = 'completed' AND payment_method IS NOT NULL
    GROUP BY payment_method
    ORDER BY total DESC
")->fetchAll();
$maxPaymentMethodTotal = max(array_merge([1], array_column($paymentMethodRows, 'total')));

/* ---------- Chiffre d'affaires et commission par semaine (8 dernieres semaines) ---------- */
$stmt = $db->prepare("
    SELECT YEARWEEK(created_at, 3) AS yw, COALESCE(SUM(total_amount), 0) AS total
    FROM orders WHERE payment_status = 'paid' AND created_at >= :since
    GROUP BY yw
");
$stmt->execute(['since' => date('Y-m-d 00:00:00', strtotime('-8 weeks'))]);
$revenueByWeekRaw = array_column($stmt->fetchAll(), 'total', 'yw');

$stmt = $db->prepare("
    SELECT YEARWEEK(created_at, 3) AS yw, COALESCE(SUM(commission_amount - reversed_amount), 0) AS total
    FROM commissions WHERE status != 'reversed' AND created_at >= :since
    GROUP BY yw
");
$stmt->execute(['since' => date('Y-m-d 00:00:00', strtotime('-8 weeks'))]);
$commissionByWeekRaw = array_column($stmt->fetchAll(), 'total', 'yw');

$weeklyRevenue = [];
$weeklyCommission = [];
for ($i = 7; $i >= 0; $i--) {
    $ts = strtotime("-{$i} weeks");
    $yw = (int) date('o', $ts) * 100 + (int) date('W', $ts);
    $weeklyRevenue[] = ['label' => 'S' . date('W', $ts), 'amount' => (int) round((float) ($revenueByWeekRaw[$yw] ?? 0))];
    $weeklyCommission[] = ['label' => 'S' . date('W', $ts), 'amount' => (int) round((float) ($commissionByWeekRaw[$yw] ?? 0))];
}
$maxWeeklyRevenue = max(array_merge([1], array_column($weeklyRevenue, 'amount')));
$maxWeeklyCommission = max(array_merge([1], array_column($weeklyCommission, 'amount')));

$vendorRows = $db->query("
    SELECT v.id, v.business_name, v.status, v.suspended_reason, u.name AS user_name, u.email AS user_email,
        COALESCE(w.available_balance, 0) AS available_balance,
        COALESCE(w.pending_balance, 0) AS pending_balance,
        COALESCE(w.total_earned, 0) AS total_earned,
        COALESCE(w.total_withdrawn, 0) AS total_withdrawn
    FROM vendors v
    JOIN users u ON u.id = v.user_id
    LEFT JOIN wallets w ON w.vendor_id = v.id
    ORDER BY v.created_at DESC
")->fetchAll();

$recentPayments = $db->query("
    SELECT p.*, o.customer_name
    FROM payments p
    JOIN orders o ON o.id = p.order_id
    ORDER BY p.created_at DESC
    LIMIT 20
")->fetchAll();
?>

<?php if ($actionError): ?>
    <div class="alert alert-error"><?= icon('x', 18) ?><span><?= e($actionError) ?></span></div>
<?php endif; ?>

<?php if ($unresolvedFailures > 0): ?>
    <div class="card" style="margin-bottom: var(--gap); border: 1px solid #fecaca;">
        <div class="admin-toolbar">
            <h2><?= icon('x', 18) ?> <?= $unresolvedFailures ?> échec(s) de rapprochement non résolu(s)</h2>
        </div>
        <p class="char-count">Un paiement confirmé ne correspondait pas à la somme des parts vendeurs + commission — aucun crédit n'a été effectué automatiquement, l'argent reste intact. Investiguer manuellement, corriger si besoin, puis marquer résolu.</p>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Commande</th>
                        <th>Montant attendu</th>
                        <th>Montant calculé</th>
                        <th>Écart</th>
                        <th>Erreur</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($settlementFailures as $f): ?>
                        <tr>
                            <td><?= e(date('d/m/Y H:i', strtotime((string) $f['created_at']))) ?></td>
                            <td><a href="/market/admin/commandes-actives.php" class="link-muted">#<?= (int) $f['order_id'] ?></a></td>
                            <td><?= format_price((int) round((float) $f['expected_total'])) ?></td>
                            <td><?= format_price((int) round((float) $f['computed_total'])) ?></td>
                            <td style="color:#dc2626;"><?= format_price((int) round((float) $f['difference'])) ?></td>
                            <td class="wrap"><?= e($f['error_message']) ?></td>
                            <td>
                                <form method="post" action="/market/admin/finances.php" onsubmit="return confirm('Marquer cet incident comme résolu ?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="failure_id" value="<?= (int) $f['id'] ?>">
                                    <input type="hidden" name="action" value="resolve_failure">
                                    <button type="submit" class="btn btn-outline-primary btn-sm">Marquer résolu</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="admin-stats-grid admin-stats-grid-6">
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#e8f8ee; color:#16a34a;"><?= icon('cart', 18) ?></span>
        <span class="admin-stat-value"><?= format_price($totalRevenue) ?></span>
        <span class="admin-stat-label">Revenu total (commandes payées)</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#eef2ff; color:#4f46e5;"><?= icon('bar-chart', 18) ?></span>
        <span class="admin-stat-value"><?= format_price($totalCommission) ?></span>
        <span class="admin-stat-label">Commission ManMarket perçue</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#e0e7ff; color:#4338ca;"><?= icon('cart', 18) ?></span>
        <span class="admin-stat-value"><?= format_price($totalOnlinePaymentFees) ?></span>
        <span class="admin-stat-label">Frais de paiement en ligne collectés</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fff7ed; color:#d97706;"><?= icon('shield', 18) ?></span>
        <span class="admin-stat-value"><?= format_price($totalWalletHeld) ?></span>
        <span class="admin-stat-label">Détenu dans les wallets vendeurs</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fdf2f8; color:#db2777;"><?= icon('check-circle', 18) ?></span>
        <span class="admin-stat-value"><?= format_price($totalWithdrawn) ?></span>
        <span class="admin-stat-label">Total déjà retiré</span>
    </div>
    <a href="/market/admin/retraits.php?status=PENDING" class="card admin-stat-card" style="text-decoration:none; color:inherit;">
        <span class="admin-stat-icon" style="background:#fef3c7; color:#92400e;"><?= icon('clock', 18) ?></span>
        <span class="admin-stat-value"><?= $pendingWithdrawalsCount ?></span>
        <span class="admin-stat-label">Retrait(s) en attente (<?= format_price($pendingWithdrawalsAmount) ?>)</span>
    </a>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fee2e2; color:#b91c1c;"><?= icon('x', 18) ?></span>
        <span class="admin-stat-value"><?= $failedPaymentsCount ?></span>
        <span class="admin-stat-label">Paiement(s) échoué(s)/annulé(s)</span>
    </div>
</div>

<div class="admin-dashboard-grid" style="margin-bottom: var(--gap);">
    <div class="card">
        <div class="admin-toolbar">
            <h2>Chiffre d'affaires par semaine</h2>
        </div>
        <?php if ($totalRevenue === 0): ?>
            <p class="empty-state">Aucune vente en ligne réglée pour le moment.</p>
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
            <h2>Commission ManMarket par semaine</h2>
        </div>
        <?php if ($totalCommission === 0): ?>
            <p class="empty-state">Aucune commission perçue pour le moment (taux actuel : 0%).</p>
        <?php else: ?>
            <div class="admin-bars">
                <?php foreach ($weeklyCommission as $week): ?>
                    <div class="admin-bar-col">
                        <div class="admin-bar" style="height: <?= max(6, (int) round($week['amount'] / $maxWeeklyCommission * 100)) ?>%; background:#4f46e5;">
                            <span class="admin-bar-value"><?= format_price($week['amount']) ?></span>
                        </div>
                        <span class="admin-bar-label"><?= e($week['label']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="admin-dashboard-grid" style="margin-bottom: var(--gap);">
    <div class="card">
        <div class="admin-toolbar">
            <h2>Paiements par moyen</h2>
        </div>
        <?php if (!$paymentMethodRows): ?>
            <p class="empty-state">Aucun paiement complété pour le moment.</p>
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

    <div class="card">
        <div class="admin-toolbar">
            <h2>Remboursements</h2>
        </div>
        <div class="admin-stats-grid admin-stats-grid-2" style="margin-bottom: 14px;">
            <div>
                <span class="admin-stat-value" style="font-size:1.3rem;"><?= format_price($totalRefunded) ?></span>
                <span class="admin-stat-label">Total remboursé</span>
            </div>
            <div>
                <span class="admin-stat-value" style="font-size:1.3rem;"><?= $refundCount ?></span>
                <span class="admin-stat-label">Remboursement(s)</span>
            </div>
        </div>
        <?php if (!$recentRefunds): ?>
            <p class="empty-state">Aucun remboursement pour le moment.</p>
        <?php else: ?>
            <div class="admin-activity-list">
                <?php foreach ($recentRefunds as $r): ?>
                    <div class="admin-activity-item">
                        <span class="admin-activity-icon"><?= icon('x', 15) ?></span>
                        <div>
                            <div class="admin-activity-text"><?= e($r['customer_name']) ?> — <?= format_price((int) round((float) $r['total_amount'])) ?></div>
                            <div class="admin-activity-time"><?= e($r['reason']) ?> · <?= e(date('d/m/Y', strtotime((string) $r['created_at']))) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Vendeurs & soldes (<?= count($vendorRows) ?>)</h2>
    </div>

    <?php if (!$vendorRows): ?>
        <p class="empty-state">Aucun vendeur pour le moment. Assignez un propriétaire à une boutique depuis <a href="/market/admin/boutiques.php">Boutiques</a> pour en créer un.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Vendeur</th>
                        <th>Statut</th>
                        <th>Disponible</th>
                        <th>En attente</th>
                        <th>Total gagné</th>
                        <th>Total retiré</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vendorRows as $v): ?>
                        <tr>
                            <td><?= e($v['business_name']) ?><br><span class="char-count"><?= e($v['user_name']) ?> — <?= e($v['user_email']) ?></span></td>
                            <td>
                                <span class="tag <?= $v['status'] === 'active' ? 'tag-open' : 'tag-closed' ?>"><?= $v['status'] === 'active' ? 'Actif' : 'Suspendu' ?></span>
                                <?php if ($v['suspended_reason']): ?><br><span class="char-count"><?= e($v['suspended_reason']) ?></span><?php endif; ?>
                            </td>
                            <td><?= format_price((int) round((float) $v['available_balance'])) ?></td>
                            <td><?= format_price((int) round((float) $v['pending_balance'])) ?></td>
                            <td><?= format_price((int) round((float) $v['total_earned'])) ?></td>
                            <td><?= format_price((int) round((float) $v['total_withdrawn'])) ?></td>
                            <td>
                                <div class="admin-table-actions">
                                    <a href="/market/admin/vendeur-finance.php?id=<?= (int) $v['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a>
                                    <?php if ($v['status'] === 'active'): ?>
                                        <form method="post" action="/market/admin/finances.php" onsubmit="return confirm('Suspendre ce vendeur ? Il ne pourra plus demander de retrait.');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="vendor_id" value="<?= (int) $v['id'] ?>">
                                            <input type="hidden" name="action" value="suspend">
                                            <button type="submit" class="btn btn-outline-primary btn-sm">Suspendre</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="/market/admin/finances.php">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="vendor_id" value="<?= (int) $v['id'] ?>">
                                            <input type="hidden" name="action" value="reactivate">
                                            <button type="submit" class="btn btn-outline-primary btn-sm">Réactiver</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="admin-toolbar">
        <h2>Derniers paiements (<?= count($recentPayments) ?>)</h2>
        <a href="/market/admin/finances.php?export=payments" class="btn btn-outline-primary btn-sm"><?= icon('send', 14) ?> Exporter CSV</a>
    </div>

    <?php if (!$recentPayments): ?>
        <p class="empty-state">Aucun paiement pour le moment.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Référence</th>
                        <th>Montant</th>
                        <th>Moyen</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentPayments as $p): ?>
                        <tr>
                            <td><?= e(date('d/m/Y H:i', strtotime((string) $p['created_at']))) ?></td>
                            <td><?= e($p['customer_name']) ?></td>
                            <td><span class="char-count"><?= e($p['provider_reference']) ?></span></td>
                            <td><?= format_price((int) round((float) $p['amount'])) ?></td>
                            <td><?= e($p['payment_method'] ?? '—') ?></td>
                            <td><span class="tag <?= payment_status_tag_class($p['status']) ?>"><?= e(payment_status_label($p['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
