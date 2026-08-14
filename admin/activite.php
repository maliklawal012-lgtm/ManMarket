<?php
declare(strict_types=1);

$pageTitle = "Journal d'activité";
require_once __DIR__ . '/../includes/admin_header.php';

$typeFilter = (string) ($_GET['type'] ?? '');
$typeLabels = [
    'commande' => 'Commandes',
    'message' => 'Messages',
    'utilisateur' => 'Utilisateurs',
    'produit' => 'Produits',
];

$activity = get_recent_activity(50);
if ($typeFilter !== '' && isset($typeLabels[$typeFilter])) {
    $activity = array_values(array_filter($activity, fn ($item) => $item['type'] === $typeFilter));
}
$activity = array_slice($activity, 0, 60);
?>

<div class="card">
    <div class="admin-toolbar">
        <h2>Journal d'activité (<?= count($activity) ?>)</h2>
        <div class="filter-sort">
            <label for="type-filter">Type</label>
            <select id="type-filter" onchange="location.href = this.value">
                <option value="/market/admin/activite.php" <?= $typeFilter === '' ? 'selected' : '' ?>>Tous</option>
                <?php foreach ($typeLabels as $key => $label): ?>
                    <option value="/market/admin/activite.php?type=<?= e($key) ?>" <?= $typeFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if (!$activity): ?>
        <p class="empty-state">Aucune activité ne correspond à ce filtre.</p>
    <?php else: ?>
        <div class="admin-activity-list">
            <?php foreach ($activity as $item): ?>
                <div class="admin-activity-item">
                    <span class="admin-activity-icon"><?= icon($item['icon'], 15) ?></span>
                    <div>
                        <div class="admin-activity-text"><?= e($item['text']) ?></div>
                        <div class="admin-activity-time"><?= e(date('d/m/Y à H:i', strtotime((string) $item['time']))) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
