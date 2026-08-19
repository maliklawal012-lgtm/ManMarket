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
$adId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $stmt = $db->prepare('SELECT image FROM advertisements WHERE id = :id');
    $stmt->execute(['id' => $adId]);
    $image = $stmt->fetchColumn();

    $db->prepare('DELETE FROM advertisements WHERE id = :id')->execute(['id' => $adId]);

    if ($image) {
        delete_uploaded_image($image);
    }

    header('Location: /market/admin/publicites');
    exit;
}

$stmt = $db->prepare('SELECT * FROM advertisements WHERE id = :id');
$stmt->execute(['id' => $adId]);
$ad = $stmt->fetch() ?: null;

if (!$ad) {
    header('Location: /market/admin/publicites');
    exit;
}

$pageTitle = $ad['title'];
require_once __DIR__ . '/../includes/admin_header.php';

$today = date('Y-m-d');
$isLive = (bool) $ad['is_active'] && $today >= $ad['starts_at'] && $today <= $ad['ends_at'];
$isFuture = $today < $ad['starts_at'];

if (!$ad['is_active']) {
    $statusLabel = 'Désactivée';
    $statusClass = 'tag-closed';
} elseif ($isLive) {
    $statusLabel = 'Active maintenant';
    $statusClass = 'tag-green';
} elseif ($isFuture) {
    $statusLabel = 'À venir';
    $statusClass = 'tag-pending';
} else {
    $statusLabel = 'Expirée';
    $statusClass = 'tag-closed';
}

$linkedShop = null;
if ($ad['link_url'] && preg_match('#/market/boutique\.php\?slug=([^&]+)#', (string) $ad['link_url'], $m)) {
    $stmt = $db->prepare('SELECT id, name FROM shops WHERE slug = :slug');
    $stmt->execute(['slug' => urldecode($m[1])]);
    $linkedShop = $stmt->fetch() ?: null;
}
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/admin/publicites" class="link-more"><?= icon('chevron-right', 14) ?> Retour aux publicités</a>
</div>

<div class="card">
    <div class="admin-toolbar">
        <h2><?= e($ad['title']) ?></h2>
        <div class="admin-table-actions">
            <span class="tag <?= $statusClass ?>"><?= e($statusLabel) ?></span>
            <a href="/market/admin/publicites?action=edit&id=<?= $adId ?>" class="btn btn-outline-primary btn-sm">Modifier</a>
            <form method="post" action="/market/admin/publicite-detail?id=<?= $adId ?>" onsubmit="return confirm('Supprimer cette publicité ?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="btn btn-outline-primary btn-sm">Supprimer</button>
            </form>
        </div>
    </div>

    <?php if ($ad['image']): ?>
        <div class="admin-image-preview" style="margin-bottom:16px;">
            <img src="/market/<?= e($ad['image']) ?>" alt="" style="max-width:100%; border-radius:8px;">
        </div>
    <?php endif; ?>

    <ul class="account-info-list">
        <li><span class="account-info-label"><?= icon('send', 16) ?> Lien au clic</span><span>
            <?php if ($ad['link_url']): ?>
                <a href="<?= e($ad['link_url']) ?>" class="link-muted" target="_blank"><?= e($ad['link_url']) ?> <?= icon('chevron-right', 12) ?></a>
                <?php if ($linkedShop): ?><br><span class="char-count">Boutique détectée : <a href="/market/admin/boutique-detail?id=<?= (int) $linkedShop['id'] ?>" class="link-muted"><?= e($linkedShop['name']) ?></a></span><?php endif; ?>
            <?php else: ?>
                —
            <?php endif; ?>
        </span></li>
        <li><span class="account-info-label"><?= icon('clock', 16) ?> Période</span><span><?= e(date('d/m/Y', strtotime((string) $ad['starts_at']))) ?> → <?= e(date('d/m/Y', strtotime((string) $ad['ends_at']))) ?></span></li>
        <li><span class="account-info-label"><?= icon('menu', 16) ?> Ordre d'affichage</span><span><?= (int) $ad['sort_order'] ?></span></li>
        <li><span class="account-info-label"><?= icon('clock', 16) ?> Créée le</span><span><?= e(date('d/m/Y à H:i', strtotime((string) $ad['created_at']))) ?></span></li>
    </ul>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
