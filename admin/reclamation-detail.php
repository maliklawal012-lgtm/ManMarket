<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$db = get_db();
$complaintId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $stmt = $db->prepare("
        UPDATE contact_messages
        SET status = IF(status = 'pending', 'processed', 'pending')
        WHERE id = :id AND subject = 'Réclamation'
    ");
    $stmt->execute(['id' => $complaintId]);
    header('Location: /market/admin/reclamation-detail?id=' . $complaintId);
    exit;
}

$stmt = $db->prepare("
    SELECT cm.*, s.name AS shop_name, u.name AS user_name, u.email AS user_account_email
    FROM contact_messages cm
    LEFT JOIN shops s ON s.id = cm.shop_id
    LEFT JOIN users u ON u.id = cm.user_id
    WHERE cm.id = :id AND cm.subject = 'Réclamation'
");
$stmt->execute(['id' => $complaintId]);
$complaint = $stmt->fetch() ?: null;

if (!$complaint) {
    header('Location: /market/admin/reclamations');
    exit;
}

$pageTitle = 'Réclamation #' . $complaintId;
require_once __DIR__ . '/../includes/admin_header.php';

$otherByCustomer = [];
if ($complaint['user_id']) {
    $stmt = $db->prepare("
        SELECT * FROM contact_messages
        WHERE subject = 'Réclamation' AND user_id = :user_id AND id != :id
        ORDER BY created_at DESC LIMIT 10
    ");
    $stmt->execute(['user_id' => (int) $complaint['user_id'], 'id' => $complaintId]);
    $otherByCustomer = $stmt->fetchAll();
} else {
    $stmt = $db->prepare("
        SELECT * FROM contact_messages
        WHERE subject = 'Réclamation' AND email = :email AND id != :id
        ORDER BY created_at DESC LIMIT 10
    ");
    $stmt->execute(['email' => $complaint['email'], 'id' => $complaintId]);
    $otherByCustomer = $stmt->fetchAll();
}

$otherByShop = [];
if ($complaint['shop_id']) {
    $stmt = $db->prepare("
        SELECT * FROM contact_messages
        WHERE subject = 'Réclamation' AND shop_id = :shop_id AND id != :id
        ORDER BY created_at DESC LIMIT 10
    ");
    $stmt->execute(['shop_id' => (int) $complaint['shop_id'], 'id' => $complaintId]);
    $otherByShop = $stmt->fetchAll();
}
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/admin/reclamations" class="link-more"><?= icon('chevron-right', 14) ?> Retour aux réclamations</a>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Réclamation #<?= $complaintId ?></h2>
        <div class="admin-table-actions">
            <span class="tag <?= $complaint['status'] === 'pending' ? 'tag-pending' : 'tag-green' ?>"><?= $complaint['status'] === 'pending' ? 'Non traitée' : 'Traitée' ?></span>
            <form method="post" action="/market/admin/reclamation-detail?id=<?= $complaintId ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="toggle_status" value="1">
                <button type="submit" class="btn btn-outline-primary btn-sm"><?= $complaint['status'] === 'pending' ? 'Marquer traitée' : 'Rouvrir' ?></button>
            </form>
        </div>
    </div>
    <ul class="account-info-list">
        <li><span class="account-info-label"><?= icon('user', 16) ?> Client</span><span>
            <?= e($complaint['name']) ?>
            <?php if ($complaint['user_id']): ?><br><span class="char-count"><a href="/market/admin/utilisateurs?action=edit&id=<?= (int) $complaint['user_id'] ?>" class="link-muted"><?= e($complaint['user_name'] ?? $complaint['user_account_email']) ?></a> — compte lié</span><?php endif; ?>
        </span></li>
        <li><span class="account-info-label"><?= icon('send', 16) ?> Contact</span><span><a href="mailto:<?= e($complaint['email']) ?>" class="link-muted"><?= e($complaint['email']) ?></a><?php if ($complaint['phone']): ?> — <?= e($complaint['phone']) ?><?php endif; ?></span></li>
        <?php if ($complaint['shop_id']): ?>
            <li><span class="account-info-label"><?= icon('store', 16) ?> Boutique concernée</span><span><a href="/market/admin/boutique-detail?id=<?= (int) $complaint['shop_id'] ?>" class="link-muted"><?= e($complaint['shop_name']) ?></a></span></li>
        <?php endif; ?>
        <?php if ($complaint['delivery_location']): ?>
            <li><span class="account-info-label"><?= icon('map-pin', 16) ?> Lieu de livraison</span><span><?= e($complaint['delivery_location']) ?></span></li>
        <?php endif; ?>
        <li><span class="account-info-label"><?= icon('clock', 16) ?> Reçue le</span><span><?= e(date('d/m/Y à H:i', strtotime((string) $complaint['created_at']))) ?></span></li>
    </ul>
    <p style="margin-top:12px; white-space:pre-line;"><?= nl2br(e($complaint['message'])) ?></p>
</div>

<div class="admin-dashboard-grid">
    <div class="card">
        <div class="admin-toolbar">
            <h2>Autres réclamations de ce client (<?= count($otherByCustomer) ?>)</h2>
        </div>
        <?php if (!$otherByCustomer): ?>
            <p class="empty-state">Aucune autre réclamation de ce client.</p>
        <?php else: ?>
            <div class="admin-activity-list">
                <?php foreach ($otherByCustomer as $c): ?>
                    <div class="admin-activity-item">
                        <span class="admin-activity-icon"><?= icon('headset', 15) ?></span>
                        <div>
                            <div class="admin-activity-text"><a href="/market/admin/reclamation-detail?id=<?= (int) $c['id'] ?>" class="link-muted"><?= e(mb_strimwidth($c['message'], 0, 60, '…')) ?></a></div>
                            <div class="admin-activity-time"><span class="tag <?= $c['status'] === 'pending' ? 'tag-pending' : 'tag-green' ?>"><?= $c['status'] === 'pending' ? 'Non traitée' : 'Traitée' ?></span> · <?= e(date('d/m/Y', strtotime((string) $c['created_at']))) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="admin-toolbar">
            <h2>Autres réclamations sur cette boutique (<?= count($otherByShop) ?>)</h2>
        </div>
        <?php if (!$complaint['shop_id']): ?>
            <p class="empty-state">Aucune boutique liée à cette réclamation.</p>
        <?php elseif (!$otherByShop): ?>
            <p class="empty-state">Aucune autre réclamation sur cette boutique.</p>
        <?php else: ?>
            <div class="admin-activity-list">
                <?php foreach ($otherByShop as $c): ?>
                    <div class="admin-activity-item">
                        <span class="admin-activity-icon"><?= icon('headset', 15) ?></span>
                        <div>
                            <div class="admin-activity-text"><a href="/market/admin/reclamation-detail?id=<?= (int) $c['id'] ?>" class="link-muted"><?= e($c['name']) ?></a> — <?= e(mb_strimwidth($c['message'], 0, 60, '…')) ?></div>
                            <div class="admin-activity-time"><span class="tag <?= $c['status'] === 'pending' ? 'tag-pending' : 'tag-green' ?>"><?= $c['status'] === 'pending' ? 'Non traitée' : 'Traitée' ?></span> · <?= e(date('d/m/Y', strtotime((string) $c['created_at']))) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
