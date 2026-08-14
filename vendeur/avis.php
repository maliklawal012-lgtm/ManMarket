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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reply') {
    $reviewId = (int) ($_POST['review_id'] ?? 0);
    $reply = trim((string) ($_POST['vendor_reply'] ?? ''));

    $stmt = $db->prepare('
        SELECT r.id FROM reviews r
        JOIN products p ON p.id = r.product_id
        WHERE r.id = :id AND p.shop_id = :shop_id
    ');
    $stmt->execute(['id' => $reviewId, 'shop_id' => $shopId]);

    if ($stmt->fetch()) {
        $stmt = $db->prepare('
            UPDATE reviews
            SET vendor_reply = :reply, vendor_reply_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ');
        $stmt->execute([
            'reply' => $reply !== '' ? $reply : null,
            'id' => $reviewId,
        ]);
    }

    header('Location: /market/vendeur/avis.php');
    exit;
}

$pageTitle = 'Avis & Notes';
require_once __DIR__ . '/../includes/vendor_header.php';

$stmt = $db->prepare('
    SELECT r.*, p.name AS product_name, p.slug AS product_slug
    FROM reviews r
    JOIN products p ON p.id = r.product_id
    WHERE p.shop_id = :shop_id
    ORDER BY r.created_at DESC
');
$stmt->execute(['shop_id' => $shopId]);
$reviews = $stmt->fetchAll();
?>

<div class="card">
    <div class="admin-toolbar">
        <h2>Avis clients (<?= count($reviews) ?>)</h2>
    </div>

    <?php if (!$reviews): ?>
        <p class="empty-state">Aucun avis n'a encore été déposé sur vos produits.</p>
    <?php else: ?>
        <div class="review-list">
            <?php foreach ($reviews as $r): ?>
                <div class="review-item">
                    <div class="review-item-header">
                        <strong><?= e($r['name']) ?></strong>
                        <span class="review-item-date"><?= e(date('d/m/Y', strtotime((string) $r['created_at']))) ?></span>
                        <a href="/market/vendeur/avis-detail.php?id=<?= (int) $r['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a>
                    </div>
                    <a href="/market/produit.php?slug=<?= e($r['product_slug']) ?>" class="link-muted"><?= e($r['product_name']) ?></a>
                    <div class="rating-row"><?= render_stars((float) $r['rating']) ?></div>
                    <?php if ($r['comment']): ?><p class="review-item-comment"><?= nl2br(e($r['comment'])) ?></p><?php endif; ?>

                    <?php if ($r['vendor_reply']): ?>
                        <div class="vendor-reply-box">
                            <strong><?= icon('store', 14) ?> Votre réponse</strong>
                            <span class="char-count"><?= e(date('d/m/Y', strtotime((string) $r['vendor_reply_at']))) ?></span>
                            <p><?= nl2br(e($r['vendor_reply'])) ?></p>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="/market/vendeur/avis.php" class="vendor-reply-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reply">
                        <input type="hidden" name="review_id" value="<?= (int) $r['id'] ?>">
                        <textarea name="vendor_reply" rows="2" placeholder="Répondre à cet avis..."><?= e((string) ($r['vendor_reply'] ?? '')) ?></textarea>
                        <button type="submit" class="btn btn-outline-primary btn-sm"><?= $r['vendor_reply'] ? 'Mettre à jour ma réponse' : 'Répondre' ?></button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
