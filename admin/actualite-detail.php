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
$newsId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $db->prepare('DELETE FROM news WHERE id = :id')->execute(['id' => $newsId]);
    header('Location: /market/admin/actualites.php');
    exit;
}

$stmt = $db->prepare('SELECT * FROM news WHERE id = :id');
$stmt->execute(['id' => $newsId]);
$news = $stmt->fetch() ?: null;

if (!$news) {
    header('Location: /market/admin/actualites.php');
    exit;
}

$pageTitle = $news['title'];
require_once __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-toolbar" style="margin-bottom: var(--gap);">
    <a href="/market/admin/actualites.php" class="link-more"><?= icon('chevron-right', 14) ?> Retour aux actualités</a>
</div>

<div class="card">
    <div class="admin-toolbar">
        <h2><?= icon($news['icon'], 20) ?> <?= e($news['title']) ?></h2>
        <div class="admin-table-actions">
            <a href="/market/admin/actualites.php?action=edit&id=<?= $newsId ?>" class="btn btn-outline-primary btn-sm">Modifier</a>
            <form method="post" action="/market/admin/actualite-detail.php?id=<?= $newsId ?>" onsubmit="return confirm('Supprimer cette actualité ?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="btn btn-outline-primary btn-sm">Supprimer</button>
            </form>
        </div>
    </div>
    <ul class="account-info-list">
        <li><span class="account-info-label"><?= icon('calendar', 16) ?> Date affichée</span><span><?= e($news['event_day']) ?> <?= e($news['event_month']) ?></span></li>
        <li><span class="account-info-label"><?= icon('menu', 16) ?> Ordre d'affichage</span><span><?= (int) $news['sort_order'] ?></span></li>
    </ul>
    <p style="margin-top:12px; white-space:pre-line;"><?= nl2br(e($news['excerpt'])) ?></p>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
