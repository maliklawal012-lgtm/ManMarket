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
$locationId = (int) ($_GET['id'] ?? 0);
$deleteError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    $db->prepare('UPDATE locations SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id')->execute(['id' => $locationId]);
    header('Location: /market/admin/localite-detail?id=' . $locationId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    try {
        $db->prepare('DELETE FROM locations WHERE id = :id')->execute(['id' => $locationId]);
        header('Location: /market/admin/localites');
        exit;
    } catch (PDOException $e) {
        $deleteError = 'Impossible de supprimer cette localité.';
    }
}

$stmt = $db->prepare('SELECT * FROM locations WHERE id = :id');
$stmt->execute(['id' => $locationId]);
$location = $stmt->fetch() ?: null;

if (!$location) {
    header('Location: /market/admin/localites');
    exit;
}

$isCity = $location['parent_id'] === null;
$pageTitle = $location['name'];
require_once __DIR__ . '/../includes/admin_header.php';

$parentCity = null;
if (!$isCity) {
    $stmt = $db->prepare('SELECT * FROM locations WHERE id = :id');
    $stmt->execute(['id' => (int) $location['parent_id']]);
    $parentCity = $stmt->fetch() ?: null;
}

$neighborhoods = [];
if ($isCity) {
    $stmt = $db->prepare('SELECT * FROM locations WHERE parent_id = :id ORDER BY sort_order, name');
    $stmt->execute(['id' => $locationId]);
    $neighborhoods = $stmt->fetchAll();
}

// Correspondance texte : delivery_location vaut "Ville" ou "Ville - Quartier" (texte libre saisi a la commande).
if ($isCity) {
    $matchExact = $location['name'];
    $matchPrefix = $location['name'] . ' - %';
} else {
    $matchExact = $parentCity['name'] . ' - ' . $location['name'];
    $matchPrefix = null;
}

$whereClause = $isCity
    ? 'delivery_location = :exact OR delivery_location LIKE :prefix'
    : 'delivery_location = :exact';
$params = $isCity ? ['exact' => $matchExact, 'prefix' => $matchPrefix] : ['exact' => $matchExact];

$stmt = $db->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS total FROM orders WHERE $whereClause");
$stmt->execute($params);
$orderStats = $stmt->fetch();

$stmt = $db->prepare("SELECT * FROM orders WHERE $whereClause ORDER BY created_at DESC LIMIT 10");
$stmt->execute($params);
$recentOrders = $stmt->fetchAll();
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/admin/localites" class="link-more"><?= icon('chevron-right', 14) ?> Retour aux lieux de livraison</a>
</div>

<?php if ($deleteError): ?>
    <div class="alert alert-error"><?= icon('x', 18) ?><span><?= e($deleteError) ?></span></div>
<?php endif; ?>

<div class="card" style="margin-bottom: var(--gap);">
    <div class="admin-toolbar">
        <h2><?= e($location['name']) ?></h2>
        <div class="admin-table-actions">
            <span class="tag <?= $location['is_active'] ? 'tag-open' : 'tag-closed' ?>"><?= $location['is_active'] ? 'Active' : 'Désactivée' ?></span>
            <form method="post" action="/market/admin/localite-detail?id=<?= $locationId ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="toggle_id" value="<?= $locationId ?>">
                <button type="submit" class="btn btn-outline-primary btn-sm"><?= $location['is_active'] ? 'Désactiver' : 'Activer' ?></button>
            </form>
            <a href="/market/admin/localites?action=edit&id=<?= $locationId ?>" class="btn btn-outline-primary btn-sm">Modifier</a>
            <form method="post" action="/market/admin/localite-detail?id=<?= $locationId ?>" onsubmit="return confirm('Supprimer cette localité ?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="btn btn-outline-primary btn-sm">Supprimer</button>
            </form>
        </div>
    </div>
    <p class="char-count">
        <?php if ($isCity): ?>
            Ville (localité principale) — <?= count($neighborhoods) ?> quartier(s) enregistré(s).
        <?php else: ?>
            Quartier de <a href="/market/admin/localite-detail?id=<?= (int) $parentCity['id'] ?>" class="link-muted"><?= e($parentCity['name']) ?></a>.
        <?php endif; ?>
    </p>
</div>

<div class="admin-stats-grid" style="grid-template-columns: repeat(2, 1fr); margin-bottom: var(--gap);">
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#eef2ff; color:#4f46e5;"><?= icon('cart', 18) ?></span>
        <span class="admin-stat-value"><?= (int) $orderStats['cnt'] ?></span>
        <span class="admin-stat-label">Commande(s) livrée(s) ici (nouveau système)</span>
    </div>
    <div class="card admin-stat-card">
        <span class="admin-stat-icon" style="background:#e8f8ee; color:#16a34a;"><?= icon('bar-chart', 18) ?></span>
        <span class="admin-stat-value"><?= format_price((int) round((float) $orderStats['total'])) ?></span>
        <span class="admin-stat-label">Montant total commandé</span>
    </div>
</div>

<?php if ($isCity): ?>
    <div class="card" style="margin-bottom: var(--gap);">
        <div class="admin-toolbar">
            <h2>Quartiers (<?= count($neighborhoods) ?>)</h2>
            <a href="/market/admin/localites?action=new&parent_id=<?= $locationId ?>" class="btn btn-outline-primary btn-sm"><?= icon('plus', 14) ?> Quartier</a>
        </div>
        <?php if (!$neighborhoods): ?>
            <p class="empty-state">Aucun quartier enregistré pour cette ville.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead><tr><th>Quartier</th><th>Statut</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($neighborhoods as $n): ?>
                            <tr>
                                <td><?= e($n['name']) ?></td>
                                <td><span class="tag <?= $n['is_active'] ? 'tag-open' : 'tag-closed' ?>"><?= $n['is_active'] ? 'Actif' : 'Désactivé' ?></span></td>
                                <td><a href="/market/admin/localite-detail?id=<?= (int) $n['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="admin-toolbar">
        <h2>Commandes récentes (<?= count($recentOrders) ?>)</h2>
    </div>
    <?php if (!$recentOrders): ?>
        <p class="empty-state">Aucune commande sur le nouveau système pour cette localité.</p>
    <?php else: ?>
        <div class="admin-activity-list">
            <?php foreach ($recentOrders as $o): ?>
                <div class="admin-activity-item">
                    <span class="admin-activity-icon"><?= icon('cart', 15) ?></span>
                    <div>
                        <div class="admin-activity-text"><a href="/market/admin/commande-detail?id=<?= (int) $o['id'] ?>" class="link-muted"><?= e($o['customer_name']) ?></a> — <?= format_price((int) round((float) $o['total_amount'])) ?> <span class="tag <?= order_status_tag_class($o['fulfillment_status']) ?>"><?= e(order_status_label($o['fulfillment_status'])) ?></span></div>
                        <div class="admin-activity-time"><?= e($o['delivery_location']) ?> · <?= e(date('d/m/Y H:i', strtotime((string) $o['created_at']))) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
