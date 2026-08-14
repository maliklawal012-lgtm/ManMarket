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

$actionError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'], $_POST['action'])) {
    $id = (int) $_POST['id'];
    $action = (string) $_POST['action'];
    $note = trim((string) ($_POST['admin_note'] ?? ''));
    $svc = wallet_withdrawal_service();
    $adminId = (int) $adminUser['id'];

    $result = match ($action) {
        'approve' => $svc->approve($id, $adminId),
        'reject' => $svc->reject($id, $adminId, $note !== '' ? $note : 'Rejeté par l\'administrateur'),
        'processing' => $svc->markProcessing($id, $adminId),
        'complete' => $svc->complete($id, $adminId, $note !== '' ? $note : null),
        'fail' => $svc->fail($id, $adminId, $note !== '' ? $note : 'Échec du transfert'),
        'reverse' => $svc->reverse($id, $adminId, $note !== '' ? $note : 'Annulé par l\'administrateur'),
        default => null,
    };

    if ($result === null) {
        $actionError = 'Action inconnue.';
    } elseif (!$result->ok) {
        $actionError = $result->error;
    } else {
        header('Location: /market/admin/retraits.php' . (isset($_GET['status']) ? '?status=' . urlencode((string) $_GET['status']) : ''));
        exit;
    }
}

$pageTitle = 'Retraits';
require_once __DIR__ . '/../includes/admin_header.php';

$withdrawalStatuses = ['PENDING', 'APPROVED', 'PROCESSING', 'COMPLETED', 'REJECTED', 'FAILED', 'CANCELLED'];
$statusFilter = (string) ($_GET['status'] ?? '');
$withdrawals = wallet_withdrawal_repo()->findAll(in_array($statusFilter, $withdrawalStatuses, true) ? $statusFilter : null);

$db = get_db();
$pendingTotal = (int) round((float) $db->query("SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE status = 'PENDING'")->fetchColumn());
$inFlightTotal = (int) round((float) $db->query("SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE status IN ('APPROVED','PROCESSING')")->fetchColumn());
$completedTotal = (int) round((float) $db->query("SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE status = 'COMPLETED'")->fetchColumn());

$withdrawalMethodLabels = [
    'wave' => 'Wave',
    'orange_money' => 'Orange Money',
    'mtn_money' => 'MTN Money',
    'moov_money' => 'Moov Money',
];
?>

<div class="admin-stats-grid" style="grid-template-columns: repeat(3, 1fr);">
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#fef3c7; color:#92400e;"><?= icon('clock', 18) ?></span>
        <span class="admin-stat-value"><?= format_price($pendingTotal) ?></span>
        <span class="admin-stat-label">En attente d'approbation</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#e0e7ff; color:#4338ca;"><?= icon('check-circle', 18) ?></span>
        <span class="admin-stat-value"><?= format_price($inFlightTotal) ?></span>
        <span class="admin-stat-label">Approuvé / en cours de paiement</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#e8f8ee; color:#16a34a;"><?= icon('cart', 18) ?></span>
        <span class="admin-stat-value"><?= format_price($completedTotal) ?></span>
        <span class="admin-stat-label">Déjà payé</span>
    </div>
</div>

<?php if ($actionError): ?>
    <div class="alert alert-error"><?= icon('x', 18) ?><span><?= e($actionError) ?></span></div>
<?php endif; ?>

<div class="card">
    <div class="admin-toolbar">
        <h2>Demandes de retrait (<?= count($withdrawals) ?>)</h2>
        <div class="filter-sort">
            <label for="status-filter">Statut</label>
            <select id="status-filter" onchange="location.href = this.value">
                <option value="/market/admin/retraits.php" <?= $statusFilter === '' ? 'selected' : '' ?>>Tous</option>
                <?php foreach ($withdrawalStatuses as $s): ?>
                    <option value="/market/admin/retraits.php?status=<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(wallet_withdrawal_status_label($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if (!$withdrawals): ?>
        <p class="empty-state">Aucune demande de retrait ne correspond à ce filtre.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Vendeur</th>
                        <th>Montant</th>
                        <th>Moyen</th>
                        <th>Référence</th>
                        <th>Statut</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($withdrawals as $w): $returnQs = $statusFilter !== '' ? '?status=' . e($statusFilter) : ''; ?>
                        <tr>
                            <td><?= e(date('d/m/Y H:i', strtotime((string) $w['created_at']))) ?></td>
                            <td><?= e($w['business_name']) ?><br><span class="char-count"><?= e($w['user_name']) ?> — <?= e($w['user_email']) ?></span></td>
                            <td><?= format_price((int) round((float) $w['amount'])) ?></td>
                            <td><?= e($withdrawalMethodLabels[$w['payment_method']] ?? $w['payment_method']) ?><br><span class="char-count"><?= e($w['account_number']) ?></span></td>
                            <td><span class="char-count"><?= e($w['reference']) ?></span></td>
                            <td>
                                <span class="tag <?= wallet_withdrawal_status_tag_class($w['status']) ?>"><?= e(wallet_withdrawal_status_label($w['status'])) ?></span>
                                <?php if ($w['admin_note']): ?><br><span class="char-count"><?= e($w['admin_note']) ?></span><?php endif; ?>
                            </td>
                            <td>
                                <div class="admin-table-actions">
                                    <a href="/market/admin/retrait-detail.php?id=<?= (int) $w['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a>
                                    <?php if ($w['status'] === 'PENDING'): ?>
                                        <form method="post" action="/market/admin/retraits.php<?= $returnQs ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="btn btn-outline-primary btn-sm">Approuver</button>
                                        </form>
                                        <form method="post" action="/market/admin/retraits.php<?= $returnQs ?>" onsubmit="return confirm('Rejeter cette demande de retrait ?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <button type="submit" class="btn btn-outline-primary btn-sm">Rejeter</button>
                                        </form>
                                    <?php elseif ($w['status'] === 'APPROVED'): ?>
                                        <form method="post" action="/market/admin/retraits.php<?= $returnQs ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
                                            <input type="hidden" name="action" value="processing">
                                            <button type="submit" class="btn btn-outline-primary btn-sm">Marquer en cours</button>
                                        </form>
                                        <form method="post" action="/market/admin/retraits.php<?= $returnQs ?>" onsubmit="return confirm('Rejeter cette demande de retrait ?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <button type="submit" class="btn btn-outline-primary btn-sm">Rejeter</button>
                                        </form>
                                    <?php elseif ($w['status'] === 'PROCESSING'): ?>
                                        <form method="post" action="/market/admin/retraits.php<?= $returnQs ?>" onsubmit="return confirm('Confirmer que l\'argent a bien été envoyé au vendeur ?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
                                            <input type="hidden" name="action" value="complete">
                                            <button type="submit" class="btn btn-outline-primary btn-sm">Marquer payé</button>
                                        </form>
                                        <form method="post" action="/market/admin/retraits.php<?= $returnQs ?>" onsubmit="return confirm('Marquer ce transfert comme échoué ?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
                                            <input type="hidden" name="action" value="fail">
                                            <button type="submit" class="btn btn-outline-primary btn-sm">Échec</button>
                                        </form>
                                    <?php elseif ($w['status'] === 'COMPLETED'): ?>
                                        <form method="post" action="/market/admin/retraits.php<?= $returnQs ?>" onsubmit="return confirm('Annuler ce retrait et recréditer le vendeur ? À utiliser uniquement en cas d\'erreur (argent jamais réellement envoyé).');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
                                            <input type="hidden" name="action" value="reverse">
                                            <button type="submit" class="btn btn-outline-primary btn-sm">Annuler / recréditer</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="char-count">—</span>
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

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
