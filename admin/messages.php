<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$pageTitle = 'Messages';
require_once __DIR__ . '/../includes/admin_header.php';

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    $stmt = $db->prepare("
        UPDATE contact_messages
        SET status = IF(status = 'pending', 'processed', 'pending')
        WHERE id = :id AND subject NOT IN ('Commande', 'Réclamation')
    ");
    $stmt->execute(['id' => (int) $_POST['toggle_id']]);
    header('Location: /market/admin/messages.php' . (isset($_GET['status']) ? '?status=' . urlencode((string) $_GET['status']) : ''));
    exit;
}

$statusFilter = (string) ($_GET['status'] ?? '');
$sql = "
    SELECT cm.*, s.name AS shop_name
    FROM contact_messages cm
    LEFT JOIN shops s ON s.id = cm.shop_id
    WHERE cm.subject NOT IN ('Commande', 'Réclamation')
";
$params = [];
if (in_array($statusFilter, ['pending', 'processed'], true)) {
    $sql .= ' AND status = :status';
    $params['status'] = $statusFilter;
}
$sql .= ' ORDER BY created_at DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll();
?>

<div class="card">
    <div class="admin-toolbar">
        <h2>Messages (<?= count($messages) ?>)</h2>
        <div class="filter-sort">
            <label for="status-filter">Statut</label>
            <select id="status-filter" onchange="location.href = this.value">
                <option value="/market/admin/messages.php" <?= $statusFilter === '' ? 'selected' : '' ?>>Tous</option>
                <option value="/market/admin/messages.php?status=pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Non traités</option>
                <option value="/market/admin/messages.php?status=processed" <?= $statusFilter === 'processed' ? 'selected' : '' ?>>Traités</option>
            </select>
        </div>
    </div>

    <?php if (!$messages): ?>
        <p class="empty-state">Aucun message ne correspond à ce filtre.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Expéditeur</th>
                        <th>Sujet</th>
                        <th>Boutique</th>
                        <th>Message</th>
                        <th>Statut</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($messages as $msg): ?>
                        <tr>
                            <td><?= e(date('d/m/Y H:i', strtotime((string) $msg['created_at']))) ?></td>
                            <td>
                                <?= e($msg['name']) ?><br>
                                <a href="mailto:<?= e($msg['email']) ?>" class="link-muted"><?= e($msg['email']) ?></a>
                            </td>
                            <td><?= e($msg['subject']) ?></td>
                            <td><?= $msg['shop_name'] ? e($msg['shop_name']) : '—' ?></td>
                            <td class="wrap"><?= nl2br(e($msg['message'])) ?></td>
                            <td><span class="tag <?= $msg['status'] === 'pending' ? 'tag-pending' : 'tag-green' ?>"><?= $msg['status'] === 'pending' ? 'Non traité' : 'Traité' ?></span></td>
                            <td>
                                <div class="admin-table-actions">
                                    <a href="/market/admin/message-detail.php?id=<?= (int) $msg['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a>
                                    <form method="post" action="/market/admin/messages.php<?= $statusFilter !== '' ? '?status=' . e($statusFilter) : '' ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="toggle_id" value="<?= (int) $msg['id'] ?>">
                                        <button type="submit" class="btn btn-outline-primary btn-sm">
                                            <?= $msg['status'] === 'pending' ? 'Marquer traité' : 'Rouvrir' ?>
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
