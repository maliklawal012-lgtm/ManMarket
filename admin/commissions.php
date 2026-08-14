<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wallet_bootstrap.php';

$adminUser = require_admin();

$commissionStatuses = ['pending', 'applied', 'partially_reversed', 'reversed'];
$statusFilter = (string) ($_GET['status'] ?? '');
$statusFilter = in_array($statusFilter, $commissionStatuses, true) ? $statusFilter : null;

$perPage = 30;
$page = max(1, (int) ($_GET['page'] ?? 1));

$db = get_db();

// Export CSV — avant tout envoi de HTML.
if (($_GET['export'] ?? '') === 'commissions') {
    $rows = wallet_commission_repo()->findAll($statusFilter, 10000, 0);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="commissions-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Boutique', 'Produit', 'Montant brut', 'Taux (%)', 'Commission', 'Remboursé', 'Statut']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['created_at'], $r['business_name'], $r['product_name'],
            $r['gross_amount'], $r['commission_rate'], $r['commission_amount'],
            $r['reversed_amount'], commission_status_label($r['status']),
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Commissions';
require_once __DIR__ . '/../includes/admin_header.php';

$totalCollected = (int) round((float) $db->query("SELECT COALESCE(SUM(commission_amount - reversed_amount), 0) FROM commissions WHERE status != 'reversed'")->fetchColumn());
$totalReversed = (int) round((float) $db->query("SELECT COALESCE(SUM(reversed_amount), 0) FROM commissions")->fetchColumn());
$totalRecords = (int) $db->query('SELECT COUNT(*) FROM commissions')->fetchColumn();
$currentRate = (new App\Services\CommissionService($db))->currentRate();

$totalFiltered = wallet_commission_repo()->countAll($statusFilter);
$totalPages = max(1, (int) ceil($totalFiltered / $perPage));
$page = min($page, $totalPages);
$commissions = wallet_commission_repo()->findAll($statusFilter, $perPage, ($page - 1) * $perPage);

function commissions_query(array $overrides): string
{
    $params = array_merge(['status' => $_GET['status'] ?? '', 'page' => $_GET['page'] ?? ''], $overrides);
    $params = array_filter($params, fn ($v) => $v !== '' && $v !== null);

    return '/market/admin/commissions.php' . ($params ? '?' . http_build_query($params) : '');
}
?>

<div class="admin-stats-grid" style="grid-template-columns: repeat(4, 1fr);">
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#e8f8ee; color:#16a34a;"><?= icon('cart', 18) ?></span>
        <span class="admin-stat-value"><?= format_price($totalCollected) ?></span>
        <span class="admin-stat-label">Commission nette perçue (hors annulées)</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fee2e2; color:#b91c1c;"><?= icon('x', 18) ?></span>
        <span class="admin-stat-value"><?= format_price($totalReversed) ?></span>
        <span class="admin-stat-label">Commission annulée (remboursements)</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#eef2ff; color:#4f46e5;"><?= icon('bar-chart', 18) ?></span>
        <span class="admin-stat-value"><?= $totalRecords ?></span>
        <span class="admin-stat-label">Lignes de commission au total</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#f3f4f6; color:#374151;"><?= icon('shield', 18) ?></span>
        <span class="admin-stat-value"><?= number_format($currentRate, 1) ?>%</span>
        <span class="admin-stat-label">Taux actuel (nouvelles commandes)</span>
    </div>
</div>

<div class="card" style="margin-top: var(--gap);">
    <div class="admin-toolbar">
        <h2>Commissions (<?= $totalFiltered ?>)</h2>
        <div class="admin-table-actions">
            <div class="filter-sort">
                <label for="status-filter">Statut</label>
                <select id="status-filter" onchange="location.href = this.value">
                    <option value="<?= e(commissions_query(['status' => '', 'page' => ''])) ?>" <?= $statusFilter === null ? 'selected' : '' ?>>Tous</option>
                    <?php foreach ($commissionStatuses as $s): ?>
                        <option value="<?= e(commissions_query(['status' => $s, 'page' => ''])) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(commission_status_label($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <a href="<?= e(commissions_query(['export' => 'commissions'])) ?>" class="btn btn-outline-primary btn-sm"><?= icon('send', 14) ?> Exporter CSV</a>
        </div>
    </div>

    <?php if (!$commissions): ?>
        <p class="empty-state">Aucune commission ne correspond à ce filtre.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Boutique</th>
                        <th>Produit</th>
                        <th>Montant brut</th>
                        <th>Taux</th>
                        <th>Commission</th>
                        <th>Remboursé</th>
                        <th>Statut</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($commissions as $c): ?>
                        <tr>
                            <td><?= e(date('d/m/Y H:i', strtotime((string) $c['created_at']))) ?></td>
                            <td><?= e($c['business_name']) ?></td>
                            <td><?= e($c['product_name']) ?></td>
                            <td><?= format_price((int) round((float) $c['gross_amount'])) ?></td>
                            <td><?= number_format((float) $c['commission_rate'], 1) ?>%</td>
                            <td><?= format_price((int) round((float) $c['commission_amount'])) ?></td>
                            <td><?= (float) $c['reversed_amount'] > 0 ? format_price((int) round((float) $c['reversed_amount'])) : '<span class="char-count">—</span>' ?></td>
                            <td><span class="tag <?= commission_status_tag_class($c['status']) ?>"><?= e(commission_status_label($c['status'])) ?></span></td>
                            <td><a href="/market/admin/commission-detail.php?id=<?= (int) $c['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="admin-table-actions" style="margin-top: 12px; justify-content: center;">
                <?php if ($page > 1): ?>
                    <a href="<?= e(commissions_query(['page' => (string) ($page - 1)])) ?>" class="btn btn-outline-primary btn-sm">← Précédent</a>
                <?php endif; ?>
                <span class="char-count">Page <?= $page ?> / <?= $totalPages ?></span>
                <?php if ($page < $totalPages): ?>
                    <a href="<?= e(commissions_query(['page' => (string) ($page + 1)])) ?>" class="btn btn-outline-primary btn-sm">Suivant →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
