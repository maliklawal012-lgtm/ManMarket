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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    $stmt = $db->prepare('UPDATE locations SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id');
    $stmt->execute(['id' => (int) $_POST['toggle_id']]);
    header('Location: /market/admin/localites.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    try {
        $stmt = $db->prepare('DELETE FROM locations WHERE id = :id');
        $stmt->execute(['id' => (int) $_POST['id']]);
        header('Location: /market/admin/localites.php');
        exit;
    } catch (PDOException $e) {
        $deleteError = "Impossible de supprimer cette localité.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $parentIdRaw = (int) ($_POST['parent_id'] ?? 0);
    $parentId = $parentIdRaw > 0 ? $parentIdRaw : null;
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);

    if ($name === '') {
        $errors['name'] = 'Veuillez indiquer un nom.';
    }
    if ($parentId === $id && $id > 0) {
        $errors['parent_id'] = 'Une localité ne peut pas être son propre quartier.';
    }

    if (!$errors) {
        if ($id > 0) {
            $stmt = $db->prepare('UPDATE locations SET name = :name, parent_id = :parent_id, is_active = :is_active, sort_order = :sort_order WHERE id = :id');
            $stmt->execute(['name' => $name, 'parent_id' => $parentId, 'is_active' => $isActive, 'sort_order' => $sortOrder, 'id' => $id]);
        } else {
            $stmt = $db->prepare('INSERT INTO locations (name, parent_id, is_active, sort_order) VALUES (:name, :parent_id, :is_active, :sort_order)');
            $stmt->execute(['name' => $name, 'parent_id' => $parentId, 'is_active' => $isActive, 'sort_order' => $sortOrder]);
        }
        header('Location: /market/admin/localites.php');
        exit;
    }
}

$cities = $db->query('SELECT * FROM locations WHERE parent_id IS NULL ORDER BY sort_order, name')->fetchAll();

$editing = null;
$formAction = (string) ($_GET['action'] ?? '');

if ($formAction === 'new') {
    $editing = ['id' => 0, 'name' => '', 'parent_id' => (int) ($_GET['parent_id'] ?? 0), 'is_active' => 1, 'sort_order' => 0];
} elseif ($formAction === 'edit' && isset($_GET['id'])) {
    $stmt = $db->prepare('SELECT * FROM locations WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['id']]);
    $editing = $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
    $editing = $_POST;
}

$pageTitle = $editing ? (($editing['id'] ?? 0) ? 'Modifier la localité' : 'Nouvelle localité') : 'Lieux de livraison';
require_once __DIR__ . '/../includes/admin_header.php';

if ($editing):
?>

    <div class="card">
        <div class="admin-toolbar">
            <h2><?= ($editing['id'] ?? 0) ? 'Modifier la localité' : 'Nouvelle localité' ?></h2>
            <a href="/market/admin/localites.php" class="link-more"><?= icon('chevron-right', 14) ?> Retour à la liste</a>
        </div>

        <form method="post" action="/market/admin/localites.php" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

            <div class="form-field <?= isset($errors['name']) ? 'has-error' : '' ?>">
                <label for="name">Nom *</label>
                <input type="text" id="name" name="name" value="<?= e((string) ($editing['name'] ?? '')) ?>" placeholder="Ex : Abidjan, ou Madina (quartier)" required>
                <?php if (isset($errors['name'])): ?><span class="field-error"><?= e($errors['name']) ?></span><?php endif; ?>
            </div>

            <div class="form-field <?= isset($errors['parent_id']) ? 'has-error' : '' ?>">
                <label for="parent_id">Type</label>
                <select id="parent_id" name="parent_id">
                    <option value="0" <?= (string) ($editing['parent_id'] ?? '0') === '0' ? 'selected' : '' ?>>Ville (localité principale)</option>
                    <?php foreach ($cities as $city): ?>
                        <?php if ((int) $city['id'] === (int) ($editing['id'] ?? 0)) continue; ?>
                        <option value="<?= (int) $city['id'] ?>" <?= (string) ($editing['parent_id'] ?? '') === (string) $city['id'] ? 'selected' : '' ?>>Quartier de <?= e($city['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['parent_id'])): ?><span class="field-error"><?= e($errors['parent_id']) ?></span><?php endif; ?>
            </div>

            <div class="form-field">
                <label for="sort_order">Ordre d'affichage</label>
                <input type="number" id="sort_order" name="sort_order" value="<?= e((string) ($editing['sort_order'] ?? 0)) ?>">
            </div>

            <div class="form-field">
                <label class="filter-toggle">
                    <input type="checkbox" name="is_active" value="1" <?= !empty($editing['is_active']) || !isset($editing['id']) ? 'checked' : '' ?>>
                    <span>Proposée aux clients lors de la commande</span>
                </label>
            </div>

            <button type="submit" class="btn btn-primary">Enregistrer</button>
        </form>
    </div>

<?php else: ?>

    <div class="card">
        <div class="admin-toolbar">
            <h2>Lieux de livraison</h2>
            <a href="/market/admin/localites.php?action=new" class="btn btn-primary btn-sm"><?= icon('plus', 14) ?> Nouvelle ville</a>
        </div>

        <?php if ($deleteError): ?>
            <div class="alert alert-error"><?= icon('x', 18) ?><span><?= e($deleteError) ?></span></div>
        <?php endif; ?>

        <p class="char-count">Les clients choisissent une ville lors de la commande ; si elle a des quartiers actifs, ils doivent aussi en choisir un.</p>

        <?php if (!$cities): ?>
            <p class="empty-state">Aucune ville pour le moment.</p>
        <?php else: ?>
            <?php foreach ($cities as $city): ?>
                <?php
                $stmt = $db->prepare('SELECT * FROM locations WHERE parent_id = :id ORDER BY sort_order, name');
                $stmt->execute(['id' => $city['id']]);
                $neighborhoods = $stmt->fetchAll();
                ?>
                <div class="card" style="margin-bottom: var(--gap); box-shadow:none; border:1px solid var(--border);">
                    <div class="admin-toolbar">
                        <h2>
                            <?= e($city['name']) ?>
                            <span class="tag <?= $city['is_active'] ? 'tag-open' : 'tag-closed' ?>"><?= $city['is_active'] ? 'Active' : 'Désactivée' ?></span>
                        </h2>
                        <div class="admin-table-actions">
                            <a href="/market/admin/localite-detail.php?id=<?= (int) $city['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a>
                            <a href="/market/admin/localites.php?action=new&parent_id=<?= (int) $city['id'] ?>" class="btn btn-outline-primary btn-sm"><?= icon('plus', 14) ?> Quartier</a>
                            <form method="post" action="/market/admin/localites.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="toggle_id" value="<?= (int) $city['id'] ?>">
                                <button type="submit" class="btn btn-outline-primary btn-sm"><?= $city['is_active'] ? 'Désactiver' : 'Activer' ?></button>
                            </form>
                            <a href="/market/admin/localites.php?action=edit&id=<?= (int) $city['id'] ?>" class="btn btn-outline-primary btn-sm">Modifier</a>
                            <form method="post" action="/market/admin/localites.php" onsubmit="return confirm('Supprimer cette ville et ses quartiers ?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $city['id'] ?>">
                                <button type="submit" class="btn btn-outline-primary btn-sm">Supprimer</button>
                            </form>
                        </div>
                    </div>

                    <?php if ($neighborhoods): ?>
                        <div class="table-responsive">
                            <table class="admin-table">
                                <tbody>
                                    <?php foreach ($neighborhoods as $n): ?>
                                        <tr>
                                            <td><?= e($n['name']) ?></td>
                                            <td><span class="tag <?= $n['is_active'] ? 'tag-open' : 'tag-closed' ?>"><?= $n['is_active'] ? 'Actif' : 'Désactivé' ?></span></td>
                                            <td>
                                                <div class="admin-table-actions">
                                                    <a href="/market/admin/localite-detail.php?id=<?= (int) $n['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a>
                                                    <form method="post" action="/market/admin/localites.php">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="toggle_id" value="<?= (int) $n['id'] ?>">
                                                        <button type="submit" class="btn btn-outline-primary btn-sm"><?= $n['is_active'] ? 'Désactiver' : 'Activer' ?></button>
                                                    </form>
                                                    <a href="/market/admin/localites.php?action=edit&id=<?= (int) $n['id'] ?>" class="btn btn-outline-primary btn-sm">Modifier</a>
                                                    <form method="post" action="/market/admin/localites.php" onsubmit="return confirm('Supprimer ce quartier ?');">
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
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
