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
$messageId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $stmt = $db->prepare("
        UPDATE contact_messages
        SET status = IF(status = 'pending', 'processed', 'pending')
        WHERE id = :id AND subject NOT IN ('Commande', 'Réclamation')
    ");
    $stmt->execute(['id' => $messageId]);
    header('Location: /market/admin/message-detail.php?id=' . $messageId);
    exit;
}

$stmt = $db->prepare("
    SELECT cm.*, s.name AS shop_name, u.name AS user_name, u.email AS user_account_email
    FROM contact_messages cm
    LEFT JOIN shops s ON s.id = cm.shop_id
    LEFT JOIN users u ON u.id = cm.user_id
    WHERE cm.id = :id AND cm.subject NOT IN ('Commande', 'Réclamation')
");
$stmt->execute(['id' => $messageId]);
$message = $stmt->fetch() ?: null;

if (!$message) {
    header('Location: /market/admin/messages.php');
    exit;
}

$pageTitle = 'Message #' . $messageId;
require_once __DIR__ . '/../includes/admin_header.php';

if ($message['user_id']) {
    $stmt = $db->prepare("
        SELECT * FROM contact_messages
        WHERE subject NOT IN ('Commande', 'Réclamation') AND user_id = :user_id AND id != :id
        ORDER BY created_at DESC LIMIT 10
    ");
    $stmt->execute(['user_id' => (int) $message['user_id'], 'id' => $messageId]);
} else {
    $stmt = $db->prepare("
        SELECT * FROM contact_messages
        WHERE subject NOT IN ('Commande', 'Réclamation') AND email = :email AND id != :id
        ORDER BY created_at DESC LIMIT 10
    ");
    $stmt->execute(['email' => $message['email'], 'id' => $messageId]);
}
$otherByCustomer = $stmt->fetchAll();

$otherByShop = [];
if ($message['shop_id']) {
    $stmt = $db->prepare("
        SELECT * FROM contact_messages
        WHERE subject NOT IN ('Commande', 'Réclamation') AND shop_id = :shop_id AND id != :id
        ORDER BY created_at DESC LIMIT 10
    ");
    $stmt->execute(['shop_id' => (int) $message['shop_id'], 'id' => $messageId]);
    $otherByShop = $stmt->fetchAll();
}
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/admin/messages.php" class="link-more"><?= icon('chevron-right', 14) ?> Retour aux messages</a>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Message #<?= $messageId ?></h2>
        <div class="admin-table-actions">
            <span class="tag <?= $message['status'] === 'pending' ? 'tag-pending' : 'tag-green' ?>"><?= $message['status'] === 'pending' ? 'Non traité' : 'Traité' ?></span>
            <form method="post" action="/market/admin/message-detail.php?id=<?= $messageId ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="toggle_status" value="1">
                <button type="submit" class="btn btn-outline-primary btn-sm"><?= $message['status'] === 'pending' ? 'Marquer traité' : 'Rouvrir' ?></button>
            </form>
        </div>
    </div>
    <ul class="account-info-list">
        <li><span class="account-info-label"><?= icon('user', 16) ?> Expéditeur</span><span>
            <?= e($message['name']) ?>
            <?php if ($message['user_id']): ?><br><span class="char-count"><a href="/market/admin/utilisateurs.php?action=edit&id=<?= (int) $message['user_id'] ?>" class="link-muted"><?= e($message['user_name'] ?? $message['user_account_email']) ?></a> — compte lié</span><?php endif; ?>
        </span></li>
        <li><span class="account-info-label"><?= icon('send', 16) ?> Contact</span><span><a href="mailto:<?= e($message['email']) ?>" class="link-muted"><?= e($message['email']) ?></a><?php if ($message['phone']): ?> — <?= e($message['phone']) ?><?php endif; ?></span></li>
        <li><span class="account-info-label"><?= icon('menu', 16) ?> Sujet</span><span><?= e($message['subject']) ?></span></li>
        <?php if ($message['shop_id']): ?>
            <li><span class="account-info-label"><?= icon('store', 16) ?> Boutique concernée</span><span><a href="/market/admin/boutique-detail.php?id=<?= (int) $message['shop_id'] ?>" class="link-muted"><?= e($message['shop_name']) ?></a></span></li>
        <?php endif; ?>
        <li><span class="account-info-label"><?= icon('clock', 16) ?> Reçu le</span><span><?= e(date('d/m/Y à H:i', strtotime((string) $message['created_at']))) ?></span></li>
    </ul>
    <p style="margin-top:12px; white-space:pre-line;"><?= nl2br(e($message['message'])) ?></p>
</div>

<div class="admin-dashboard-grid">
    <div class="card">
        <div class="admin-toolbar">
            <h2>Autres messages de cet expéditeur (<?= count($otherByCustomer) ?>)</h2>
        </div>
        <?php if (!$otherByCustomer): ?>
            <p class="empty-state">Aucun autre message de cet expéditeur.</p>
        <?php else: ?>
            <div class="admin-activity-list">
                <?php foreach ($otherByCustomer as $m): ?>
                    <div class="admin-activity-item">
                        <span class="admin-activity-icon"><?= icon('send', 15) ?></span>
                        <div>
                            <div class="admin-activity-text"><a href="/market/admin/message-detail.php?id=<?= (int) $m['id'] ?>" class="link-muted"><?= e($m['subject']) ?></a> — <?= e(mb_strimwidth($m['message'], 0, 60, '…')) ?></div>
                            <div class="admin-activity-time"><span class="tag <?= $m['status'] === 'pending' ? 'tag-pending' : 'tag-green' ?>"><?= $m['status'] === 'pending' ? 'Non traité' : 'Traité' ?></span> · <?= e(date('d/m/Y', strtotime((string) $m['created_at']))) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="admin-toolbar">
            <h2>Autres messages sur cette boutique (<?= count($otherByShop) ?>)</h2>
        </div>
        <?php if (!$message['shop_id']): ?>
            <p class="empty-state">Aucune boutique liée à ce message.</p>
        <?php elseif (!$otherByShop): ?>
            <p class="empty-state">Aucun autre message sur cette boutique.</p>
        <?php else: ?>
            <div class="admin-activity-list">
                <?php foreach ($otherByShop as $m): ?>
                    <div class="admin-activity-item">
                        <span class="admin-activity-icon"><?= icon('send', 15) ?></span>
                        <div>
                            <div class="admin-activity-text"><a href="/market/admin/message-detail.php?id=<?= (int) $m['id'] ?>" class="link-muted"><?= e($m['name']) ?></a> — <?= e(mb_strimwidth($m['message'], 0, 60, '…')) ?></div>
                            <div class="admin-activity-time"><span class="tag <?= $m['status'] === 'pending' ? 'tag-pending' : 'tag-green' ?>"><?= $m['status'] === 'pending' ? 'Non traité' : 'Traité' ?></span> · <?= e(date('d/m/Y', strtotime((string) $m['created_at']))) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
