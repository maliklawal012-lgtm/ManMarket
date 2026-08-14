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
$reviewId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $stmt = $db->prepare('SELECT product_id FROM reviews WHERE id = :id');
    $stmt->execute(['id' => $reviewId]);
    $productId = $stmt->fetchColumn();

    if ($productId) {
        $db->prepare('DELETE FROM reviews WHERE id = :id')->execute(['id' => $reviewId]);
        recompute_product_rating((int) $productId);
    }

    header('Location: /market/admin/avis.php');
    exit;
}

$stmt = $db->prepare('
    SELECT r.*, p.name AS product_name, p.slug AS product_slug, p.shop_id, s.name AS shop_name,
        u.email AS user_email, u.phone AS user_phone,
        EXISTS(
            SELECT 1 FROM legacy_order_items oi
            JOIN contact_messages o ON o.id = oi.order_id
            WHERE oi.product_id = r.product_id AND o.user_id = r.user_id AND o.status = \'delivered\'
        ) AS is_verified_purchase
    FROM reviews r
    JOIN products p ON p.id = r.product_id
    JOIN shops s ON s.id = p.shop_id
    LEFT JOIN users u ON u.id = r.user_id
    WHERE r.id = :id
');
$stmt->execute(['id' => $reviewId]);
$review = $stmt->fetch() ?: null;

if (!$review) {
    header('Location: /market/admin/avis.php');
    exit;
}

$pageTitle = 'Avis #' . $reviewId;
require_once __DIR__ . '/../includes/admin_header.php';

$stmt = $db->prepare('
    SELECT AVG(rating) AS avg_rating, COUNT(*) AS review_count
    FROM reviews WHERE product_id = :product_id
');
$stmt->execute(['product_id' => (int) $review['product_id']]);
$productStats = $stmt->fetch();

$stmt = $db->prepare('
    SELECT r.* FROM reviews r
    WHERE r.product_id = :product_id AND r.id != :id
    ORDER BY r.created_at DESC LIMIT 10
');
$stmt->execute(['product_id' => (int) $review['product_id'], 'id' => $reviewId]);
$otherProductReviews = $stmt->fetchAll();

$otherCustomerReviews = [];
if ($review['user_id']) {
    $stmt = $db->prepare('
        SELECT r.*, p.name AS product_name, p.slug AS product_slug
        FROM reviews r
        JOIN products p ON p.id = r.product_id
        WHERE r.user_id = :user_id AND r.id != :id
        ORDER BY r.created_at DESC LIMIT 10
    ');
    $stmt->execute(['user_id' => (int) $review['user_id'], 'id' => $reviewId]);
    $otherCustomerReviews = $stmt->fetchAll();
}
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/admin/avis.php" class="link-more"><?= icon('chevron-right', 14) ?> Retour aux avis</a>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Avis #<?= $reviewId ?></h2>
        <form method="post" action="/market/admin/avis-detail.php?id=<?= $reviewId ?>" onsubmit="return confirm('Supprimer cet avis ?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn btn-outline-primary btn-sm">Supprimer</button>
        </form>
    </div>
    <ul class="account-info-list">
        <li><span class="account-info-label"><?= icon('shopping-basket', 16) ?> Produit</span><span><a href="/market/produit.php?slug=<?= e($review['product_slug']) ?>" class="link-muted"><?= e($review['product_name']) ?></a> — <a href="/market/admin/boutique-detail.php?id=<?= (int) $review['shop_id'] ?>" class="link-muted"><?= e($review['shop_name']) ?></a></span></li>
        <li><span class="account-info-label"><?= icon('user', 16) ?> Client</span><span>
            <?= e($review['name']) ?>
            <?php if ($review['is_verified_purchase']): ?> <span class="tag tag-green">Achat vérifié</span><?php endif; ?>
            <?php if ($review['user_id']): ?><br><span class="char-count"><a href="/market/admin/utilisateurs.php?action=edit&id=<?= (int) $review['user_id'] ?>" class="link-muted"><?= e($review['user_email']) ?></a><?php if ($review['user_phone']): ?> — <?= e($review['user_phone']) ?><?php endif; ?></span>
            <?php else: ?><br><span class="char-count">Compte supprimé ou avis anonyme</span><?php endif; ?>
        </span></li>
        <li><span class="account-info-label"><?= icon('star-filled', 16) ?> Note</span><span><div class="rating-row"><?= render_stars((float) $review['rating']) ?></div></span></li>
        <li><span class="account-info-label"><?= icon('clock', 16) ?> Déposé le</span><span><?= e(date('d/m/Y à H:i', strtotime((string) $review['created_at']))) ?></span></li>
        <?php if ($review['updated_at'] && $review['updated_at'] !== $review['created_at']): ?>
            <li><span class="account-info-label"><?= icon('clock', 16) ?> Modifié le</span><span><?= e(date('d/m/Y à H:i', strtotime((string) $review['updated_at']))) ?></span></li>
        <?php endif; ?>
    </ul>
    <?php if ($review['comment']): ?>
        <p style="margin-top:12px; white-space:pre-line;"><?= nl2br(e($review['comment'])) ?></p>
    <?php else: ?>
        <p class="char-count" style="margin-top:12px;">Aucun commentaire — note seule.</p>
    <?php endif; ?>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Réponse de la boutique</h2>
    </div>
    <?php if ($review['vendor_reply']): ?>
        <p class="char-count">Répondu le <?= e(date('d/m/Y à H:i', strtotime((string) $review['vendor_reply_at']))) ?></p>
        <p style="white-space:pre-line;"><?= nl2br(e($review['vendor_reply'])) ?></p>
    <?php else: ?>
        <p class="empty-state">La boutique n'a pas encore répondu à cet avis.</p>
    <?php endif; ?>
</div>

<div class="admin-dashboard-grid">
    <div class="card">
        <div class="admin-toolbar">
            <h2>Autres avis sur ce produit (<?= count($otherProductReviews) ?>)</h2>
        </div>
        <p class="char-count">Note moyenne du produit : <strong><?= number_format((float) $productStats['avg_rating'], 1) ?>/5</strong> sur <?= (int) $productStats['review_count'] ?> avis.</p>
        <?php if (!$otherProductReviews): ?>
            <p class="empty-state">Aucun autre avis sur ce produit.</p>
        <?php else: ?>
            <div class="admin-activity-list">
                <?php foreach ($otherProductReviews as $r): ?>
                    <div class="admin-activity-item">
                        <span class="admin-activity-icon"><?= icon('star-filled', 15) ?></span>
                        <div>
                            <div class="admin-activity-text"><a href="/market/admin/avis-detail.php?id=<?= (int) $r['id'] ?>" class="link-muted"><?= e($r['name']) ?></a> <?= render_stars((float) $r['rating']) ?></div>
                            <div class="admin-activity-time"><?= $r['comment'] ? e(mb_strimwidth($r['comment'], 0, 80, '…')) . ' · ' : '' ?><?= e(date('d/m/Y', strtotime((string) $r['created_at']))) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="admin-toolbar">
            <h2>Autres avis de ce client (<?= count($otherCustomerReviews) ?>)</h2>
        </div>
        <?php if (!$review['user_id']): ?>
            <p class="empty-state">Client sans compte lié — impossible de retrouver ses autres avis.</p>
        <?php elseif (!$otherCustomerReviews): ?>
            <p class="empty-state">Aucun autre avis déposé par ce client.</p>
        <?php else: ?>
            <div class="admin-activity-list">
                <?php foreach ($otherCustomerReviews as $r): ?>
                    <div class="admin-activity-item">
                        <span class="admin-activity-icon"><?= icon('star-filled', 15) ?></span>
                        <div>
                            <div class="admin-activity-text"><a href="/market/admin/avis-detail.php?id=<?= (int) $r['id'] ?>" class="link-muted"><?= e($r['product_name']) ?></a> <?= render_stars((float) $r['rating']) ?></div>
                            <div class="admin-activity-time"><?= $r['comment'] ? e(mb_strimwidth($r['comment'], 0, 80, '…')) . ' · ' : '' ?><?= e(date('d/m/Y', strtotime((string) $r['created_at']))) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
