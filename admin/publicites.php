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

const AD_UPLOAD_DIR = __DIR__ . '/../assets/uploads/ads/';
const AD_UPLOAD_WEB_PATH = 'assets/uploads/ads/';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $stmt = $db->prepare('SELECT image FROM advertisements WHERE id = :id');
    $stmt->execute(['id' => (int) $_POST['id']]);
    $image = $stmt->fetchColumn();

    $db->prepare('DELETE FROM advertisements WHERE id = :id')->execute(['id' => (int) $_POST['id']]);

    if ($image) {
        delete_uploaded_image($image);
    }

    header('Location: /market/admin/publicites.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $id = (int) ($_POST['id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $linkUrl = trim((string) ($_POST['link_url'] ?? ''));
    $startsAt = (string) ($_POST['starts_at'] ?? '');
    $endsAt = (string) ($_POST['ends_at'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);
    $removeImage = isset($_POST['remove_image']);

    $currentImage = null;
    if ($id > 0) {
        $stmt = $db->prepare('SELECT image FROM advertisements WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $currentImage = $stmt->fetchColumn() ?: null;
    }

    if ($title === '') {
        $errors['title'] = 'Veuillez indiquer un titre.';
    }
    if ($linkUrl === '') {
        $errors['link_url'] = 'Veuillez indiquer un lien : une publicité doit mener quelque part.';
    } elseif (!preg_match('#^(/market/|https?://)#', $linkUrl)) {
        $errors['link_url'] = "Le lien doit commencer par /market/ ou http(s)://";
    }
    if ($startsAt === '' || $endsAt === '') {
        $errors['dates'] = 'Veuillez indiquer une date de début et de fin.';
    } elseif ($endsAt < $startsAt) {
        $errors['dates'] = 'La date de fin doit être postérieure à la date de début.';
    }

    $newImageExt = null;
    $hasUpload = isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE;

    if ($hasUpload) {
        $validation = validate_uploaded_image($_FILES['image']);
        if ($validation['error']) {
            $errors['image'] = $validation['error'];
        } else {
            $newImageExt = $validation['ext'];
        }
    } elseif ($id === 0 && !$errors) {
        $errors['image'] = 'Veuillez ajouter une image pour cette publicité.';
    }

    if (!$errors) {
        if ($newImageExt) {
            $finalImage = store_uploaded_image($_FILES['image'], $newImageExt, AD_UPLOAD_DIR, AD_UPLOAD_WEB_PATH);
            delete_uploaded_image($currentImage);
        } elseif ($removeImage) {
            delete_uploaded_image($currentImage);
            $finalImage = null;
        } else {
            $finalImage = $currentImage;
        }

        if ($id > 0) {
            $stmt = $db->prepare('
                UPDATE advertisements
                SET title = :title, image = :image, link_url = :link_url,
                    starts_at = :starts_at, ends_at = :ends_at, is_active = :is_active, sort_order = :sort_order
                WHERE id = :id
            ');
            $stmt->execute([
                'title' => $title, 'image' => $finalImage, 'link_url' => $linkUrl !== '' ? $linkUrl : null,
                'starts_at' => $startsAt, 'ends_at' => $endsAt, 'is_active' => $isActive, 'sort_order' => $sortOrder, 'id' => $id,
            ]);
        } else {
            $stmt = $db->prepare('
                INSERT INTO advertisements (title, image, link_url, starts_at, ends_at, is_active, sort_order)
                VALUES (:title, :image, :link_url, :starts_at, :ends_at, :is_active, :sort_order)
            ');
            $stmt->execute([
                'title' => $title, 'image' => $finalImage, 'link_url' => $linkUrl !== '' ? $linkUrl : null,
                'starts_at' => $startsAt, 'ends_at' => $endsAt, 'is_active' => $isActive, 'sort_order' => $sortOrder,
            ]);
        }

        header('Location: /market/admin/publicites.php');
        exit;
    }
}

$editing = null;
$formAction = (string) ($_GET['action'] ?? '');

if ($formAction === 'new') {
    $editing = [
        'id' => 0, 'title' => '', 'image' => null, 'link_url' => '',
        'starts_at' => date('Y-m-d'), 'ends_at' => date('Y-m-d', strtotime('+30 days')), 'is_active' => 1, 'sort_order' => 0,
    ];
} elseif ($formAction === 'edit' && isset($_GET['id'])) {
    $stmt = $db->prepare('SELECT * FROM advertisements WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['id']]);
    $editing = $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
    $editing = $_POST;
    $editing['image'] = $currentImage ?? null;
}

$pageTitle = $editing ? (($editing['id'] ?? 0) ? 'Modifier la publicité' : 'Nouvelle publicité') : 'Publicités';
require_once __DIR__ . '/../includes/admin_header.php';

if ($editing):
?>

    <div class="card">
        <div class="admin-toolbar">
            <h2><?= ($editing['id'] ?? 0) ? 'Modifier la publicité' : 'Nouvelle publicité' ?></h2>
            <a href="/market/admin/publicites.php" class="link-more"><?= icon('chevron-right', 14) ?> Retour à la liste</a>
        </div>

        <form method="post" action="/market/admin/publicites.php" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

            <div class="form-field <?= isset($errors['title']) ? 'has-error' : '' ?>">
                <label for="title">Titre (usage interne) *</label>
                <input type="text" id="title" name="title" value="<?= e((string) ($editing['title'] ?? '')) ?>" placeholder="Ex : Bannière Boutique Amani" required>
                <?php if (isset($errors['title'])): ?><span class="field-error"><?= e($errors['title']) ?></span><?php endif; ?>
            </div>

            <div class="form-field <?= isset($errors['image']) ? 'has-error' : '' ?>">
                <label for="image">Image *</label>
                <?php if (!empty($editing['image'])): ?>
                    <div class="admin-image-preview">
                        <img src="/market/<?= e((string) $editing['image']) ?>" alt="">
                        <label class="filter-toggle">
                            <input type="checkbox" name="remove_image" value="1">
                            <span>Supprimer l'image actuelle</span>
                        </label>
                    </div>
                <?php endif; ?>
                <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp,image/gif">
                <span class="char-count">JPG, PNG, WEBP ou GIF — 3 Mo max. Format bannière large recommandé.</span>
                <?php if (isset($errors['image'])): ?><span class="field-error"><?= e($errors['image']) ?></span><?php endif; ?>
            </div>

            <div class="form-field <?= isset($errors['link_url']) ? 'has-error' : '' ?>">
                <label for="link_url">Lien au clic *</label>
                <input type="text" id="link_url" name="link_url" value="<?= e((string) ($editing['link_url'] ?? '')) ?>" placeholder="/market/boutique.php?slug=... ou https://..." required>
                <?php if (isset($errors['link_url'])): ?><span class="field-error"><?= e($errors['link_url']) ?></span><?php endif; ?>
            </div>

            <div class="form-row">
                <div class="form-field <?= isset($errors['dates']) ? 'has-error' : '' ?>">
                    <label for="starts_at">Du *</label>
                    <input type="date" id="starts_at" name="starts_at" value="<?= e((string) ($editing['starts_at'] ?? '')) ?>" required>
                </div>
                <div class="form-field <?= isset($errors['dates']) ? 'has-error' : '' ?>">
                    <label for="ends_at">Au *</label>
                    <input type="date" id="ends_at" name="ends_at" value="<?= e((string) ($editing['ends_at'] ?? '')) ?>" required>
                    <?php if (isset($errors['dates'])): ?><span class="field-error"><?= e($errors['dates']) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label for="sort_order">Ordre d'affichage</label>
                    <input type="number" id="sort_order" name="sort_order" value="<?= e((string) ($editing['sort_order'] ?? 0)) ?>">
                </div>
                <div class="form-field">
                    <label class="filter-toggle" style="margin-top: 30px;">
                        <input type="checkbox" name="is_active" value="1" <?= !empty($editing['is_active']) || !isset($editing['id']) ? 'checked' : '' ?>>
                        <span>Activer cette publicité</span>
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Enregistrer</button>
        </form>
    </div>

<?php else:
    $ads = $db->query('SELECT * FROM advertisements ORDER BY starts_at DESC')->fetchAll();
    $today = date('Y-m-d');
?>

    <div class="card">
        <div class="admin-toolbar">
            <h2>Publicités (<?= count($ads) ?>)</h2>
            <a href="/market/admin/publicites.php?action=new" class="btn btn-primary btn-sm"><?= icon('plus', 14) ?> Nouvelle publicité</a>
        </div>

        <?php if (!$ads): ?>
            <p class="empty-state">Aucune publicité pour le moment.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th></th>
                            <th>Titre</th>
                            <th>Lien</th>
                            <th>Période</th>
                            <th>Statut</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ads as $ad):
                            $isLive = (bool) $ad['is_active'] && $today >= $ad['starts_at'] && $today <= $ad['ends_at'];
                            $isFuture = $today < $ad['starts_at'];
                        ?>
                            <tr>
                                <td><div class="product-thumb admin-table-thumb"><img src="/market/<?= e($ad['image']) ?>" alt=""></div></td>
                                <td><?= e($ad['title']) ?></td>
                                <td><?= $ad['link_url'] ? e($ad['link_url']) : '—' ?></td>
                                <td><?= e(date('d/m/Y', strtotime((string) $ad['starts_at']))) ?> – <?= e(date('d/m/Y', strtotime((string) $ad['ends_at']))) ?></td>
                                <td>
                                    <?php if (!$ad['is_active']): ?>
                                        <span class="tag tag-closed">Désactivée</span>
                                    <?php elseif ($isLive): ?>
                                        <span class="tag tag-green">Active maintenant</span>
                                    <?php elseif ($isFuture): ?>
                                        <span class="tag tag-pending">À venir</span>
                                    <?php else: ?>
                                        <span class="tag tag-closed">Expirée</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="admin-table-actions">
                                        <a href="/market/admin/publicite-detail.php?id=<?= (int) $ad['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a>
                                        <a href="/market/admin/publicites.php?action=edit&id=<?= (int) $ad['id'] ?>" class="btn btn-outline-primary btn-sm">Modifier</a>
                                        <form method="post" action="/market/admin/publicites.php" onsubmit="return confirm('Supprimer cette publicité ?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $ad['id'] ?>">
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
