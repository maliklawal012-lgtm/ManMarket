<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();

$db = get_db();
$subscriptionId = (int) ($_GET['id'] ?? 0);

$stmt = $db->prepare('
    SELECT ss.*, s.name AS shop_name, s.slug AS shop_slug, s.vendor_id, v.business_name
    FROM shop_subscriptions ss
    JOIN shops s ON s.id = ss.shop_id
    LEFT JOIN vendors v ON v.id = s.vendor_id
    WHERE ss.id = :id
');
$stmt->execute(['id' => $subscriptionId]);
$subscription = $stmt->fetch() ?: null;

if (!$subscription) {
    header('Location: /market/admin/abonnements');
    exit;
}

$pageTitle = 'Abonnement #' . $subscriptionId;
require_once __DIR__ . '/../includes/admin_header.php';

$shopId = (int) $subscription['shop_id'];
$today = date('Y-m-d');
$daysLeft = (int) floor((strtotime((string) $subscription['ends_at']) - strtotime($today)) / 86400);

if ($daysLeft >= 0 && $daysLeft <= 7) {
    $statusLabel = 'Expire bientôt';
    $statusClass = 'tag-pending';
} elseif ($subscription['ends_at'] >= $today) {
    $statusLabel = 'Actif';
    $statusClass = 'tag-open';
} else {
    $statusLabel = 'Expiré';
    $statusClass = 'tag-closed';
}

$latest = get_shop_latest_subscription($shopId);
$isCurrent = $latest && (int) $latest['id'] === $subscriptionId;

$stmt = $db->prepare('SELECT * FROM shop_subscriptions WHERE shop_id = :shop_id AND id != :id ORDER BY ends_at DESC');
$stmt->execute(['shop_id' => $shopId, 'id' => $subscriptionId]);
$otherSubscriptions = $stmt->fetchAll();
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/admin/abonnements" class="link-more"><?= icon('chevron-right', 14) ?> Retour aux abonnements</a>
</div>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2>Abonnement #<?= $subscriptionId ?></h2>
        <div class="admin-table-actions">
            <?php if ($isCurrent): ?><span class="tag tag-processing">Abonnement actuel</span><?php endif; ?>
            <span class="tag <?= $statusClass ?>"><?= e($statusLabel) ?></span>
        </div>
    </div>
    <ul class="account-info-list">
        <li><span class="account-info-label"><?= icon('store', 16) ?> Boutique</span><span><a href="/market/admin/boutique-detail?id=<?= $shopId ?>" class="link-muted"><?= e($subscription['shop_name']) ?></a><?php if ($subscription['business_name']): ?> — <?= e($subscription['business_name']) ?><?php endif; ?></span></li>
        <li><span class="account-info-label"><?= icon('shield', 16) ?> Formule</span><span><?= e($subscription['plan_name']) ?></span></li>
        <li><span class="account-info-label"><?= icon('cart', 16) ?> Prix payé</span><span><?= format_price((int) $subscription['price_paid']) ?></span></li>
        <li><span class="account-info-label"><?= icon('clock', 16) ?> Période</span><span><?= e(date('d/m/Y', strtotime((string) $subscription['starts_at']))) ?> → <?= e(date('d/m/Y', strtotime((string) $subscription['ends_at']))) ?></span></li>
        <li><span class="account-info-label"><?= icon('clock', 16) ?> Enregistré le</span><span><?= e(date('d/m/Y à H:i', strtotime((string) $subscription['created_at']))) ?></span></li>
        <?php if (!$isCurrent): ?>
            <li><span class="account-info-label"><?= icon('x', 16) ?> Remarque</span><span class="char-count">Cet abonnement n'est plus le plus récent de la boutique — consultez « Autres abonnements » ci-dessous pour l'actuel.</span></li>
        <?php endif; ?>
    </ul>
</div>

<?php if ($otherSubscriptions): ?>
    <div class="card">
        <div class="admin-toolbar">
            <h2>Autres abonnements de cette boutique (<?= count($otherSubscriptions) ?>)</h2>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Formule</th>
                        <th>Prix payé</th>
                        <th>Début</th>
                        <th>Fin</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($otherSubscriptions as $s): ?>
                        <tr>
                            <td><?= e($s['plan_name']) ?></td>
                            <td><?= format_price((int) $s['price_paid']) ?></td>
                            <td><?= e(date('d/m/Y', strtotime((string) $s['starts_at']))) ?></td>
                            <td><?= e(date('d/m/Y', strtotime((string) $s['ends_at']))) ?></td>
                            <td><a href="/market/admin/abonnement-detail?id=<?= (int) $s['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <p class="empty-state">Aucun autre abonnement enregistré pour cette boutique — c'est le premier.</p>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
