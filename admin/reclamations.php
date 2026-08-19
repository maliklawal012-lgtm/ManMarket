<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$pageTitle = 'Réclamations';
require_once __DIR__ . '/../includes/admin_header.php';

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    $stmt = $db->prepare("
        UPDATE contact_messages
        SET status = IF(status = 'pending', 'processed', 'pending')
        WHERE id = :id AND subject = 'Réclamation'
    ");
    $stmt->execute(['id' => (int) $_POST['toggle_id']]);
    header('Location: /market/admin/reclamations' . (isset($_GET['status']) ? '?status=' . urlencode((string) $_GET['status']) : ''));
    exit;
}

$statusFilter = (string) ($_GET['status'] ?? '');
$sql = "SELECT * FROM contact_messages WHERE subject = 'Réclamation'";
$params = [];
if (in_array($statusFilter, ['pending', 'processed'], true)) {
    $sql .= ' AND status = :status';
    $params['status'] = $statusFilter;
}
$sql .= ' ORDER BY created_at DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$complaints = $stmt->fetchAll();
?>

<div class="card">
    <div class="admin-toolbar">
        <h2>Réclamations (<?= count($complaints) ?>)</h2>
        <div class="filter-sort">
            <label for="status-filter">Statut</label>
            <select id="status-filter" onchange="location.href = this.value">
                <option value="/market/admin/reclamations" <?= $statusFilter === '' ? 'selected' : '' ?>>Toutes</option>
                <option value="/market/admin/reclamations?status=pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Non traitées</option>
                <option value="/market/admin/reclamations?status=processed" <?= $statusFilter === 'processed' ? 'selected' : '' ?>>Traitées</option>
            </select>
        </div>
    </div>

    <?php if (!$complaints): ?>
        <p class="empty-state">Aucune réclamation ne correspond à ce filtre.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Contact</th>
                        <th>Message</th>
                        <th>Statut</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($complaints as $complaint): ?>
                        <tr>
                            <td><?= e(date('d/m/Y H:i', strtotime((string) $complaint['created_at']))) ?></td>
                            <td><?= e($complaint['name']) ?></td>
                            <td>
                                <a href="mailto:<?= e($complaint['email']) ?>" class="link-muted"><?= e($complaint['email']) ?></a>
                                <?php if ($complaint['phone']): ?><br><?= e($complaint['phone']) ?><?php endif; ?>
                            </td>
                            <td class="wrap"><?= nl2br(e($complaint['message'])) ?></td>
                            <td><span class="tag <?= $complaint['status'] === 'pending' ? 'tag-pending' : 'tag-green' ?>"><?= $complaint['status'] === 'pending' ? 'Non traitée' : 'Traitée' ?></span></td>
                            <td>
                                <div class="admin-table-actions">
                                    <a href="/market/admin/reclamation-detail?id=<?= (int) $complaint['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a>
                                    <form method="post" action="/market/admin/reclamations<?= $statusFilter !== '' ? '?status=' . e($statusFilter) : '' ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="toggle_id" value="<?= (int) $complaint['id'] ?>">
                                        <button type="submit" class="btn btn-outline-primary btn-sm">
                                            <?= $complaint['status'] === 'pending' ? 'Marquer traitée' : 'Rouvrir' ?>
                                        </button>
                                    </form>
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
