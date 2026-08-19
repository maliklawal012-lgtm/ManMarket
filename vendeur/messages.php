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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    $stmt = $db->prepare("
        UPDATE contact_messages
        SET status = IF(status = 'pending', 'processed', 'pending')
        WHERE id = :id AND shop_id = :shop_id
    ");
    $stmt->execute(['id' => (int) $_POST['toggle_id'], 'shop_id' => $shopId]);
    header('Location: /market/vendeur/messages');
    exit;
}

$pageTitle = 'Messages';
require_once __DIR__ . '/../includes/vendor_header.php';

$stmt = $db->prepare('SELECT * FROM contact_messages WHERE shop_id = :shop_id ORDER BY created_at DESC');
$stmt->execute(['shop_id' => $shopId]);
$messages = $stmt->fetchAll();
?>

<div class="card">
    <div class="admin-toolbar">
        <h2>Messages reçus (<?= count($messages) ?>)</h2>
    </div>

    <?php if (!$messages): ?>
        <p class="empty-state">Aucun message pour le moment. Les clients peuvent vous écrire depuis la page de votre boutique.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Expéditeur</th>
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
                                <?php if ($msg['phone']): ?><br><span class="char-count"><?= e($msg['phone']) ?></span><?php endif; ?>
                            </td>
                            <td class="wrap"><?= nl2br(e($msg['message'])) ?></td>
                            <td><span class="tag <?= $msg['status'] === 'pending' ? 'tag-pending' : 'tag-green' ?>"><?= $msg['status'] === 'pending' ? 'Non traité' : 'Traité' ?></span></td>
                            <td>
                                <div class="admin-table-actions">
                                    <?php if ($msg['subject'] === 'Réclamation'): ?>
                                        <a href="/market/vendeur/reclamation-detail?id=<?= (int) $msg['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a>
                                    <?php else: ?>
                                        <a href="/market/vendeur/message-detail?id=<?= (int) $msg['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a>
                                    <?php endif; ?>
                                    <form method="post" action="/market/vendeur/messages">
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

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
