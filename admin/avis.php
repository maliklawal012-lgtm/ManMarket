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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $reviewId = (int) ($_POST['id'] ?? 0);
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

$pageTitle = 'Avis & Commentaires';
require_once __DIR__ . '/../includes/admin_header.php';

$reviews = $db->query("
    SELECT r.*, p.name AS product_name, p.slug AS product_slug,
        EXISTS(
            SELECT 1 FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE oi.product_id = r.product_id AND o.customer_user_id = r.user_id AND oi.fulfillment_status = 'delivered'
        ) AS is_verified_purchase
    FROM reviews r
    JOIN products p ON p.id = r.product_id
    ORDER BY r.created_at DESC
")->fetchAll();
?>

<div class="card">
    <div class="admin-toolbar">
        <h2>Avis clients (<?= count($reviews) ?>)</h2>
    </div>

    <?php if (!$reviews): ?>
        <p class="empty-state">Aucun avis n'a encore été déposé.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Produit</th>
                        <th>Client</th>
                        <th>Note</th>
                        <th>Commentaire</th>
                        <th>Réponse boutique</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reviews as $r): ?>
                        <tr>
                            <td><?= e(date('d/m/Y H:i', strtotime((string) $r['created_at']))) ?></td>
                            <td><a href="/market/produit.php?slug=<?= e($r['product_slug']) ?>" class="link-muted"><?= e($r['product_name']) ?></a></td>
                            <td><?= e($r['name']) ?><?= $r['is_verified_purchase'] ? ' <span class="tag tag-green">Achat vérifié</span>' : '' ?></td>
                            <td><div class="rating-row"><?= render_stars((float) $r['rating']) ?></div></td>
                            <td class="wrap"><?= $r['comment'] ? nl2br(e($r['comment'])) : '—' ?></td>
                            <td class="wrap"><?= $r['vendor_reply'] ? nl2br(e($r['vendor_reply'])) : '—' ?></td>
                            <td>
                                <div class="admin-table-actions">
                                    <a href="/market/admin/avis-detail.php?id=<?= (int) $r['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a>
                                    <form method="post" action="/market/admin/avis.php" onsubmit="return confirm('Supprimer cet avis ?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="btn btn-outline-primary btn-sm">Supprimer</button>
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
