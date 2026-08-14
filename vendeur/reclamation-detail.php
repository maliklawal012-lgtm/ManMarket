<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

$vendorUser = require_vendor();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$vendorShop = current_vendor_shop((int) $vendorUser['id']);

if (!$vendorShop) {
    header('Location: /market/vendeur/index.php');
    exit;
}

$db = get_db();
$shopId = (int) $vendorShop['id'];
$complaintId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $stmt = $db->prepare("
        UPDATE contact_messages
        SET status = IF(status = 'pending', 'processed', 'pending')
        WHERE id = :id AND shop_id = :shop_id AND subject = 'Réclamation'
    ");
    $stmt->execute(['id' => $complaintId, 'shop_id' => $shopId]);
    header('Location: /market/vendeur/reclamation-detail.php?id=' . $complaintId);
    exit;
}

$stmt = $db->prepare("
    SELECT * FROM contact_messages
    WHERE id = :id AND shop_id = :shop_id AND subject = 'Réclamation'
");
$stmt->execute(['id' => $complaintId, 'shop_id' => $shopId]);
$complaint = $stmt->fetch() ?: null;

if (!$complaint) {
    header('Location: /market/vendeur/messages.php');
    exit;
}

$pageTitle = 'Réclamation #' . $complaintId;
require_once __DIR__ . '/../includes/vendor_header.php';

if ($complaint['user_id']) {
    $stmt = $db->prepare("
        SELECT * FROM contact_messages
        WHERE subject = 'Réclamation' AND shop_id = :shop_id AND user_id = :user_id AND id != :id
        ORDER BY created_at DESC LIMIT 10
    ");
    $stmt->execute(['shop_id' => $shopId, 'user_id' => (int) $complaint['user_id'], 'id' => $complaintId]);
} else {
    $stmt = $db->prepare("
        SELECT * FROM contact_messages
        WHERE subject = 'Réclamation' AND shop_id = :shop_id AND email = :email AND id != :id
        ORDER BY created_at DESC LIMIT 10
    ");
    $stmt->execute(['shop_id' => $shopId, 'email' => $complaint['email'], 'id' => $complaintId]);
}
$otherByCustomer = $stmt->fetchAll();
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/vendeur/messages.php" class="link-more"><?= icon('chevron-right', 14) ?> Retour aux messages</a>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Réclamation #<?= $complaintId ?></h2>
        <div class="admin-table-actions">
            <span class="tag <?= $complaint['status'] === 'pending' ? 'tag-pending' : 'tag-green' ?>"><?= $complaint['status'] === 'pending' ? 'Non traitée' : 'Traitée' ?></span>
            <form method="post" action="/market/vendeur/reclamation-detail.php?id=<?= $complaintId ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="toggle_status" value="1">
                <button type="submit" class="btn btn-outline-primary btn-sm"><?= $complaint['status'] === 'pending' ? 'Marquer traitée' : 'Rouvrir' ?></button>
            </form>
        </div>
    </div>
    <ul class="account-info-list">
        <li><span class="account-info-label"><?= icon('user', 16) ?> Client</span><span><?= e($complaint['name']) ?></span></li>
        <li><span class="account-info-label"><?= icon('send', 16) ?> Contact</span><span><a href="mailto:<?= e($complaint['email']) ?>" class="link-muted"><?= e($complaint['email']) ?></a><?php if ($complaint['phone']): ?> — <?= e($complaint['phone']) ?><?php endif; ?></span></li>
        <?php if ($complaint['delivery_location']): ?>
            <li><span class="account-info-label"><?= icon('map-pin', 16) ?> Lieu de livraison</span><span><?= e($complaint['delivery_location']) ?></span></li>
        <?php endif; ?>
        <li><span class="account-info-label"><?= icon('clock', 16) ?> Reçue le</span><span><?= e(date('d/m/Y à H:i', strtotime((string) $complaint['created_at']))) ?></span></li>
    </ul>
    <p style="margin-top:12px; white-space:pre-line;"><?= nl2br(e($complaint['message'])) ?></p>
</div>

<div class="card">
    <div class="admin-toolbar">
        <h2>Autres réclamations de ce client (<?= count($otherByCustomer) ?>)</h2>
    </div>
    <?php if (!$otherByCustomer): ?>
        <p class="empty-state">Aucune autre réclamation de ce client sur votre boutique.</p>
    <?php else: ?>
        <div class="admin-activity-list">
            <?php foreach ($otherByCustomer as $c): ?>
                <div class="admin-activity-item">
                    <span class="admin-activity-icon"><?= icon('headset', 15) ?></span>
                    <div>
                        <div class="admin-activity-text"><a href="/market/vendeur/reclamation-detail.php?id=<?= (int) $c['id'] ?>" class="link-muted"><?= e(mb_strimwidth($c['message'], 0, 60, '…')) ?></a></div>
                        <div class="admin-activity-time"><span class="tag <?= $c['status'] === 'pending' ? 'tag-pending' : 'tag-green' ?>"><?= $c['status'] === 'pending' ? 'Non traitée' : 'Traitée' ?></span> · <?= e(date('d/m/Y', strtotime((string) $c['created_at']))) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
