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
$reviewId = (int) ($_GET['id'] ?? 0);

$stmt = $db->prepare('
    SELECT r.*, p.name AS product_name, p.slug AS product_slug, p.shop_id,
        u.email AS user_email, u.phone AS user_phone,
        EXISTS(
            SELECT 1 FROM legacy_order_items oi
            JOIN contact_messages o ON o.id = oi.order_id
            WHERE oi.product_id = r.product_id AND o.user_id = r.user_id AND o.status = \'delivered\'
        ) AS is_verified_purchase
    FROM reviews r
    JOIN products p ON p.id = r.product_id
    LEFT JOIN users u ON u.id = r.user_id
    WHERE r.id = :id
');
$stmt->execute(['id' => $reviewId]);
$review = $stmt->fetch() ?: null;

// Garde-fou vie privée : l'avis doit porter sur un produit de cette boutique.
if ($review && (int) $review['shop_id'] !== $shopId) {
    $review = null;
}

if (!$review) {
    header('Location: /market/vendeur/avis.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reply') {
    $reply = trim((string) ($_POST['vendor_reply'] ?? ''));
    $stmt = $db->prepare('UPDATE reviews SET vendor_reply = :reply, vendor_reply_at = CURRENT_TIMESTAMP WHERE id = :id');
    $stmt->execute(['reply' => $reply !== '' ? $reply : null, 'id' => $reviewId]);

    header('Location: /market/vendeur/avis-detail.php?id=' . $reviewId);
    exit;
}

$pageTitle = 'Avis #' . $reviewId;
require_once __DIR__ . '/../includes/vendor_header.php';

$stmt = $db->prepare('
    SELECT AVG(rating) AS avg_rating, COUNT(*) AS review_count
    FROM reviews WHERE product_id = :product_id
');
$stmt->execute(['product_id' => (int) $review['product_id']]);
$productStats = $stmt->fetch();

$stmt = $db->prepare('SELECT * FROM reviews WHERE product_id = :product_id AND id != :id ORDER BY created_at DESC LIMIT 10');
$stmt->execute(['product_id' => (int) $review['product_id'], 'id' => $reviewId]);
$otherProductReviews = $stmt->fetchAll();

$otherCustomerReviews = [];
if ($review['user_id']) {
    $stmt = $db->prepare('
        SELECT r.*, p.name AS product_name, p.slug AS product_slug
        FROM reviews r
        JOIN products p ON p.id = r.product_id
        WHERE r.user_id = :user_id AND r.id != :id AND p.shop_id = :shop_id
        ORDER BY r.created_at DESC LIMIT 10
    ');
    $stmt->execute(['user_id' => (int) $review['user_id'], 'id' => $reviewId, 'shop_id' => $shopId]);
    $otherCustomerReviews = $stmt->fetchAll();
}
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/vendeur/avis.php" class="link-more"><?= icon('chevron-right', 14) ?> Retour aux avis</a>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Avis #<?= $reviewId ?></h2>
    </div>
    <ul class="account-info-list">
        <li><span class="account-info-label"><?= icon('shopping-basket', 16) ?> Produit</span><span><a href="/market/produit.php?slug=<?= e($review['product_slug']) ?>" class="link-muted"><?= e($review['product_name']) ?></a></span></li>
        <li><span class="account-info-label"><?= icon('user', 16) ?> Client</span><span>
            <?= e($review['name']) ?>
            <?php if ($review['is_verified_purchase']): ?> <span class="tag tag-green">Achat vérifié</span><?php endif; ?>
            <?php if ($review['user_id'] && $review['user_email']): ?><br><span class="char-count"><?= e($review['user_email']) ?><?php if ($review['user_phone']): ?> — <?= e($review['user_phone']) ?><?php endif; ?></span><?php endif; ?>
        </span></li>
        <li><span class="account-info-label"><?= icon('star-filled', 16) ?> Note</span><span><div class="rating-row"><?= render_stars((float) $review['rating']) ?></div></span></li>
        <li><span class="account-info-label"><?= icon('clock', 16) ?> Déposé le</span><span><?= e(date('d/m/Y à H:i', strtotime((string) $review['created_at']))) ?></span></li>
    </ul>
    <?php if ($review['comment']): ?>
        <p style="margin-top:12px; white-space:pre-line;"><?= nl2br(e($review['comment'])) ?></p>
    <?php else: ?>
        <p class="char-count" style="margin-top:12px;">Aucun commentaire — note seule.</p>
    <?php endif; ?>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Votre réponse</h2>
    </div>
    <?php if ($review['vendor_reply']): ?>
        <p class="char-count">Répondu le <?= e(date('d/m/Y à H:i', strtotime((string) $review['vendor_reply_at']))) ?></p>
    <?php endif; ?>
    <form method="post" action="/market/vendeur/avis-detail.php?id=<?= $reviewId ?>" class="vendor-reply-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reply">
        <textarea name="vendor_reply" rows="3" placeholder="Répondre à cet avis..."><?= e((string) ($review['vendor_reply'] ?? '')) ?></textarea>
        <button type="submit" class="btn btn-primary btn-sm" style="margin-top:8px;"><?= $review['vendor_reply'] ? 'Mettre à jour ma réponse' : 'Répondre' ?></button>
    </form>
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
                            <div class="admin-activity-text"><a href="/market/vendeur/avis-detail.php?id=<?= (int) $r['id'] ?>" class="link-muted"><?= e($r['name']) ?></a> <?= render_stars((float) $r['rating']) ?></div>
                            <div class="admin-activity-time"><?= $r['comment'] ? e(mb_strimwidth($r['comment'], 0, 80, '…')) . ' · ' : '' ?><?= e(date('d/m/Y', strtotime((string) $r['created_at']))) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="admin-toolbar">
            <h2>Autres avis de ce client sur votre boutique (<?= count($otherCustomerReviews) ?>)</h2>
        </div>
        <?php if (!$review['user_id']): ?>
            <p class="empty-state">Client sans compte lié — impossible de retrouver ses autres avis.</p>
        <?php elseif (!$otherCustomerReviews): ?>
            <p class="empty-state">Aucun autre avis de ce client sur vos produits.</p>
        <?php else: ?>
            <div class="admin-activity-list">
                <?php foreach ($otherCustomerReviews as $r): ?>
                    <div class="admin-activity-item">
                        <span class="admin-activity-icon"><?= icon('star-filled', 15) ?></span>
                        <div>
                            <div class="admin-activity-text"><a href="/market/vendeur/avis-detail.php?id=<?= (int) $r['id'] ?>" class="link-muted"><?= e($r['product_name']) ?></a> <?= render_stars((float) $r['rating']) ?></div>
                            <div class="admin-activity-time"><?= $r['comment'] ? e(mb_strimwidth($r['comment'], 0, 80, '…')) . ' · ' : '' ?><?= e(date('d/m/Y', strtotime((string) $r['created_at']))) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
