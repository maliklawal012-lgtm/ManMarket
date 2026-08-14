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
$deleteError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    try {
        $stmt = $db->prepare('DELETE FROM categories WHERE id = :id');
        $stmt->execute(['id' => (int) $_POST['id']]);
        header('Location: /market/admin/categories.php');
        exit;
    } catch (PDOException $e) {
        $deleteError = 'Impossible de supprimer cette catégorie : des produits y sont encore associés.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $categoryIcon = trim((string) ($_POST['icon'] ?? '')) ?: 'shopping-basket';
    $color = trim((string) ($_POST['color'] ?? '')) ?: '#16a34a';
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);

    if ($name === '') {
        $errors['name'] = 'Veuillez indiquer un nom.';
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $errors['color'] = 'Couleur invalide.';
    }

    if (!$errors) {
        $baseSlug = slugify($name) ?: 'categorie';
        $slug = $baseSlug;
        $suffix = 2;
        while (true) {
            $stmt = $db->prepare('SELECT id FROM categories WHERE slug = :slug AND id != :id');
            $stmt->execute(['slug' => $slug, 'id' => $id]);
            if (!$stmt->fetch()) {
                break;
            }
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        if ($id > 0) {
            $stmt = $db->prepare('
                UPDATE categories SET name = :name, slug = :slug, icon = :icon, color = :color, sort_order = :sort_order
                WHERE id = :id
            ');
            $stmt->execute([
                'name' => $name, 'slug' => $slug, 'icon' => $categoryIcon, 'color' => $color, 'sort_order' => $sortOrder, 'id' => $id,
            ]);
        } else {
            $stmt = $db->prepare('
                INSERT INTO categories (name, slug, icon, color, sort_order)
                VALUES (:name, :slug, :icon, :color, :sort_order)
            ');
            $stmt->execute([
                'name' => $name, 'slug' => $slug, 'icon' => $categoryIcon, 'color' => $color, 'sort_order' => $sortOrder,
            ]);
        }

        header('Location: /market/admin/categories.php');
        exit;
    }
}

$editing = null;
$formAction = (string) ($_GET['action'] ?? '');

if ($formAction === 'new') {
    $editing = ['id' => 0, 'name' => '', 'icon' => '', 'color' => '#16a34a', 'sort_order' => 0];
} elseif ($formAction === 'edit' && isset($_GET['id'])) {
    $stmt = $db->prepare('SELECT * FROM categories WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['id']]);
    $editing = $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
    $editing = $_POST;
}

$pageTitle = $editing ? (($editing['id'] ?? 0) ? 'Modifier la catégorie' : 'Nouvelle catégorie') : 'Catégories';
require_once __DIR__ . '/../includes/admin_header.php';

if ($editing):
?>

    <div class="card">
        <div class="admin-toolbar">
            <h2><?= ($editing['id'] ?? 0) ? 'Modifier la catégorie' : 'Nouvelle catégorie' ?></h2>
            <a href="/market/admin/categories.php" class="link-more"><?= icon('chevron-right', 14) ?> Retour à la liste</a>
        </div>

        <form method="post" action="/market/admin/categories.php" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

            <div class="form-field <?= isset($errors['name']) ? 'has-error' : '' ?>">
                <label for="name">Nom de la catégorie *</label>
                <input type="text" id="name" name="name" value="<?= e((string) ($editing['name'] ?? '')) ?>" required>
                <?php if (isset($errors['name'])): ?><span class="field-error"><?= e($errors['name']) ?></span><?php endif; ?>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label for="icon">Icône</label>
                    <input type="text" id="icon" name="icon" value="<?= e((string) ($editing['icon'] ?? '')) ?>" placeholder="shopping-basket, shirt, smartphone...">
                </div>
                <div class="form-field <?= isset($errors['color']) ? 'has-error' : '' ?>">
                    <label for="color">Couleur</label>
                    <input type="color" id="color" name="color" value="<?= e((string) ($editing['color'] ?? '#16a34a')) ?>" style="height:44px; padding:4px;">
                    <?php if (isset($errors['color'])): ?><span class="field-error"><?= e($errors['color']) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="form-field">
                <label for="sort_order">Ordre d'affichage</label>
                <input type="number" id="sort_order" name="sort_order" value="<?= e((string) ($editing['sort_order'] ?? 0)) ?>">
            </div>

            <button type="submit" class="btn btn-primary">Enregistrer</button>
        </form>
    </div>

<?php else:
    $categories = $db->query('
        SELECT c.*, COUNT(p.id) AS product_count
        FROM categories c
        LEFT JOIN products p ON p.category_id = c.id
        GROUP BY c.id
        ORDER BY c.sort_order
    ')->fetchAll();
?>

    <div class="card">
        <div class="admin-toolbar">
            <h2>Catégories (<?= count($categories) ?>)</h2>
            <a href="/market/admin/categories.php?action=new" class="btn btn-primary btn-sm"><?= icon('plus', 14) ?> Nouvelle catégorie</a>
        </div>

        <?php if ($deleteError): ?>
            <div class="alert alert-error"><?= icon('x', 18) ?><span><?= e($deleteError) ?></span></div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Catégorie</th>
                        <th>Produits</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td>
                                <span class="admin-stat-icon" style="width:28px; height:28px; display:inline-flex; vertical-align:middle; margin-right:8px; background:<?= e($cat['color']) ?>1a; color:<?= e($cat['color']) ?>;"><?= icon($cat['icon'], 15) ?></span>
                                <?= e($cat['name']) ?>
                            </td>
                            <td><?= (int) $cat['product_count'] ?></td>
                            <td>
                                <div class="admin-table-actions">
                                    <a href="/market/admin/categorie-detail.php?id=<?= (int) $cat['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a>
                                    <a href="/market/admin/categories.php?action=edit&id=<?= (int) $cat['id'] ?>" class="btn btn-outline-primary btn-sm">Modifier</a>
                                    <form method="post" action="/market/admin/categories.php" onsubmit="return confirm('Supprimer cette catégorie ?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
                                        <button type="submit" class="btn btn-outline-primary btn-sm">Supprimer</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
