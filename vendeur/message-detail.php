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
    header('Location: /market/vendeur/index');
    exit;
}

$db = get_db();
$shopId = (int) $vendorShop['id'];
$messageId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $stmt = $db->prepare("
        UPDATE contact_messages
        SET status = IF(status = 'pending', 'processed', 'pending')
        WHERE id = :id AND shop_id = :shop_id AND subject != 'Réclamation'
    ");
    $stmt->execute(['id' => $messageId, 'shop_id' => $shopId]);
    header('Location: /market/vendeur/message-detail?id=' . $messageId);
    exit;
}

$stmt = $db->prepare("
    SELECT * FROM contact_messages
    WHERE id = :id AND shop_id = :shop_id AND subject != 'Réclamation'
");
$stmt->execute(['id' => $messageId, 'shop_id' => $shopId]);
$message = $stmt->fetch() ?: null;

if (!$message) {
    header('Location: /market/vendeur/messages');
    exit;
}

$pageTitle = 'Message #' . $messageId;
require_once __DIR__ . '/../includes/vendor_header.php';

if ($message['user_id']) {
    $stmt = $db->prepare("
        SELECT * FROM contact_messages
        WHERE subject != 'Réclamation' AND shop_id = :shop_id AND user_id = :user_id AND id != :id
        ORDER BY created_at DESC LIMIT 10
    ");
    $stmt->execute(['shop_id' => $shopId, 'user_id' => (int) $message['user_id'], 'id' => $messageId]);
} else {
    $stmt = $db->prepare("
        SELECT * FROM contact_messages
        WHERE subject != 'Réclamation' AND shop_id = :shop_id AND email = :email AND id != :id
        ORDER BY created_at DESC LIMIT 10
    ");
    $stmt->execute(['shop_id' => $shopId, 'email' => $message['email'], 'id' => $messageId]);
}
$otherByCustomer = $stmt->fetchAll();
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/vendeur/messages" class="link-more"><?= icon('chevron-right', 14) ?> Retour aux messages</a>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Message #<?= $messageId ?></h2>
        <div class="admin-table-actions">
            <span class="tag <?= $message['status'] === 'pending' ? 'tag-pending' : 'tag-green' ?>"><?= $message['status'] === 'pending' ? 'Non traité' : 'Traité' ?></span>
            <form method="post" action="/market/vendeur/message-detail?id=<?= $messageId ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="toggle_status" value="1">
                <button type="submit" class="btn btn-outline-primary btn-sm"><?= $message['status'] === 'pending' ? 'Marquer traité' : 'Rouvrir' ?></button>
            </form>
        </div>
    </div>
    <ul class="account-info-list">
        <li><span class="account-info-label"><?= icon('user', 16) ?> Expéditeur</span><span><?= e($message['name']) ?></span></li>
        <li><span class="account-info-label"><?= icon('send', 16) ?> Contact</span><span><a href="mailto:<?= e($message['email']) ?>" class="link-muted"><?= e($message['email']) ?></a><?php if ($message['phone']): ?> — <?= e($message['phone']) ?><?php endif; ?></span></li>
        <li><span class="account-info-label"><?= icon('menu', 16) ?> Sujet</span><span><?= e($message['subject']) ?></span></li>
        <li><span class="account-info-label"><?= icon('clock', 16) ?> Reçu le</span><span><?= e(date('d/m/Y à H:i', strtotime((string) $message['created_at']))) ?></span></li>
    </ul>
    <p style="margin-top:12px; white-space:pre-line;"><?= nl2br(e($message['message'])) ?></p>
</div>

<div class="card">
    <div class="admin-toolbar">
        <h2>Autres messages de cet expéditeur (<?= count($otherByCustomer) ?>)</h2>
    </div>
    <?php if (!$otherByCustomer): ?>
        <p class="empty-state">Aucun autre message de cet expéditeur sur votre boutique.</p>
    <?php else: ?>
        <div class="admin-activity-list">
            <?php foreach ($otherByCustomer as $m): ?>
                <div class="admin-activity-item">
                    <span class="admin-activity-icon"><?= icon('send', 15) ?></span>
                    <div>
                        <div class="admin-activity-text"><a href="/market/vendeur/message-detail?id=<?= (int) $m['id'] ?>" class="link-muted"><?= e($m['subject']) ?></a> — <?= e(mb_strimwidth($m['message'], 0, 60, '…')) ?></div>
                        <div class="admin-activity-time"><span class="tag <?= $m['status'] === 'pending' ? 'tag-pending' : 'tag-green' ?>"><?= $m['status'] === 'pending' ? 'Non traité' : 'Traité' ?></span> · <?= e(date('d/m/Y', strtotime((string) $m['created_at']))) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
