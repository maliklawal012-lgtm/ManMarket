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
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $stmt = $db->prepare('DELETE FROM news WHERE id = :id');
    $stmt->execute(['id' => (int) $_POST['id']]);
    header('Location: /market/admin/actualites');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $id = (int) ($_POST['id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $excerpt = trim((string) ($_POST['excerpt'] ?? ''));
    $eventDay = trim((string) ($_POST['event_day'] ?? ''));
    $eventMonth = trim((string) ($_POST['event_month'] ?? ''));
    $newsIcon = trim((string) ($_POST['icon'] ?? '')) ?: 'calendar';
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);

    if ($title === '') {
        $errors['title'] = 'Veuillez indiquer un titre.';
    }
    if ($excerpt === '') {
        $errors['excerpt'] = 'Veuillez indiquer une description.';
    }
    if ($eventDay === '' || !preg_match('/^\d{1,2}$/', $eventDay)) {
        $errors['event_day'] = 'Veuillez indiquer un jour valide (ex : 20).';
    }
    if ($eventMonth === '') {
        $errors['event_month'] = 'Veuillez indiquer un mois (ex : Mars).';
    }

    if (!$errors) {
        if ($id > 0) {
            $stmt = $db->prepare('
                UPDATE news
                SET title = :title, excerpt = :excerpt, event_day = :event_day, event_month = :event_month,
                    icon = :icon, sort_order = :sort_order
                WHERE id = :id
            ');
            $stmt->execute([
                'title' => $title, 'excerpt' => $excerpt, 'event_day' => $eventDay, 'event_month' => $eventMonth,
                'icon' => $newsIcon, 'sort_order' => $sortOrder, 'id' => $id,
            ]);
        } else {
            $stmt = $db->prepare('
                INSERT INTO news (title, excerpt, event_day, event_month, icon, sort_order)
                VALUES (:title, :excerpt, :event_day, :event_month, :icon, :sort_order)
            ');
            $stmt->execute([
                'title' => $title, 'excerpt' => $excerpt, 'event_day' => $eventDay, 'event_month' => $eventMonth,
                'icon' => $newsIcon, 'sort_order' => $sortOrder,
            ]);
        }

        header('Location: /market/admin/actualites');
        exit;
    }
}

$editing = null;
$formAction = (string) ($_GET['action'] ?? '');

if ($formAction === 'new') {
    $editing = ['id' => 0, 'title' => '', 'excerpt' => '', 'event_day' => '', 'event_month' => '', 'icon' => 'calendar', 'sort_order' => 0];
} elseif ($formAction === 'edit' && isset($_GET['id'])) {
    $stmt = $db->prepare('SELECT * FROM news WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['id']]);
    $editing = $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
    $editing = $_POST;
}

$pageTitle = $editing ? (($editing['id'] ?? 0) ? "Modifier l'actualité" : 'Nouvelle actualité') : 'Actualités & Événements';
require_once __DIR__ . '/../includes/admin_header.php';

if ($editing):
?>

    <div class="card">
        <div class="admin-toolbar">
            <h2><?= ($editing['id'] ?? 0) ? "Modifier l'actualité" : 'Nouvelle actualité' ?></h2>
            <a href="/market/admin/actualites" class="link-more"><?= icon('chevron-right', 14) ?> Retour à la liste</a>
        </div>

        <form method="post" action="/market/admin/actualites" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

            <div class="form-field <?= isset($errors['title']) ? 'has-error' : '' ?>">
                <label for="title">Titre *</label>
                <input type="text" id="title" name="title" value="<?= e((string) ($editing['title'] ?? '')) ?>" placeholder="Ex : Foire commerciale de Man 2026" required>
                <?php if (isset($errors['title'])): ?><span class="field-error"><?= e($errors['title']) ?></span><?php endif; ?>
            </div>

            <div class="form-field <?= isset($errors['excerpt']) ? 'has-error' : '' ?>">
                <label for="excerpt">Description courte *</label>
                <textarea id="excerpt" name="excerpt" rows="2" required><?= e((string) ($editing['excerpt'] ?? '')) ?></textarea>
                <?php if (isset($errors['excerpt'])): ?><span class="field-error"><?= e($errors['excerpt']) ?></span><?php endif; ?>
            </div>

            <div class="form-row">
                <div class="form-field <?= isset($errors['event_day']) ? 'has-error' : '' ?>">
                    <label for="event_day">Jour *</label>
                    <input type="text" id="event_day" name="event_day" value="<?= e((string) ($editing['event_day'] ?? '')) ?>" maxlength="2" placeholder="20" required>
                    <?php if (isset($errors['event_day'])): ?><span class="field-error"><?= e($errors['event_day']) ?></span><?php endif; ?>
                </div>
                <div class="form-field <?= isset($errors['event_month']) ? 'has-error' : '' ?>">
                    <label for="event_month">Mois *</label>
                    <input type="text" id="event_month" name="event_month" value="<?= e((string) ($editing['event_month'] ?? '')) ?>" placeholder="Mars" required>
                    <?php if (isset($errors['event_month'])): ?><span class="field-error"><?= e($errors['event_month']) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label for="icon">Icône</label>
                    <input type="text" id="icon" name="icon" value="<?= e((string) ($editing['icon'] ?? 'calendar')) ?>" placeholder="calendar, store, drama, road, building-2...">
                </div>
                <div class="form-field">
                    <label for="sort_order">Ordre d'affichage</label>
                    <input type="number" id="sort_order" name="sort_order" value="<?= e((string) ($editing['sort_order'] ?? 0)) ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Enregistrer</button>
        </form>
    </div>

<?php else:
    $news = $db->query('SELECT * FROM news ORDER BY sort_order')->fetchAll();
?>

    <div class="card">
        <div class="admin-toolbar">
            <h2>Actualités & Événements (<?= count($news) ?>)</h2>
            <a href="/market/admin/actualites?action=new" class="btn btn-primary btn-sm"><?= icon('plus', 14) ?> Nouvelle actualité</a>
        </div>

        <?php if (!$news): ?>
            <p class="empty-state">Aucune actualité pour le moment.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Titre</th>
                            <th>Description</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($news as $n): ?>
                            <tr>
                                <td><?= e($n['event_day']) ?> <?= e($n['event_month']) ?></td>
                                <td><?= e($n['title']) ?></td>
                                <td class="wrap"><?= e($n['excerpt']) ?></td>
                                <td>
                                    <div class="admin-table-actions">
                                        <a href="/market/admin/actualite-detail?id=<?= (int) $n['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a>
                                        <a href="/market/admin/actualites?action=edit&id=<?= (int) $n['id'] ?>" class="btn btn-outline-primary btn-sm">Modifier</a>
                                        <form method="post" action="/market/admin/actualites" onsubmit="return confirm('Supprimer cette actualité ?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
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

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
