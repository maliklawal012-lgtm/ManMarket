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
    $stmt = $db->prepare('DELETE FROM promotions WHERE id = :id');
    $stmt->execute(['id' => (int) $_POST['id']]);
    header('Location: /market/admin/promotions');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $discount = (int) ($_POST['discount_percent'] ?? 0);
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $startsAt = (string) ($_POST['starts_at'] ?? '');
    $endsAt = (string) ($_POST['ends_at'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        $errors['name'] = 'Veuillez indiquer un nom.';
    }
    if ($discount < 1 || $discount > 99) {
        $errors['discount_percent'] = 'La remise doit être comprise entre 1 et 99.';
    }
    if ($startsAt === '' || $endsAt === '') {
        $errors['dates'] = 'Veuillez indiquer une date de début et de fin.';
    } elseif ($endsAt < $startsAt) {
        $errors['dates'] = 'La date de fin doit être postérieure à la date de début.';
    }

    if (!$errors) {
        $scope = $categoryId > 0 ? 'category' : 'all';

        if ($id > 0) {
            $stmt = $db->prepare('
                UPDATE promotions
                SET name = :name, discount_percent = :discount, scope = :scope, category_id = :category_id,
                    starts_at = :starts_at, ends_at = :ends_at, is_active = :is_active
                WHERE id = :id
            ');
            $stmt->execute([
                'name' => $name, 'discount' => $discount, 'scope' => $scope,
                'category_id' => $scope === 'category' ? $categoryId : null,
                'starts_at' => $startsAt, 'ends_at' => $endsAt, 'is_active' => $isActive, 'id' => $id,
            ]);
        } else {
            $stmt = $db->prepare('
                INSERT INTO promotions (name, discount_percent, scope, category_id, starts_at, ends_at, is_active)
                VALUES (:name, :discount, :scope, :category_id, :starts_at, :ends_at, :is_active)
            ');
            $stmt->execute([
                'name' => $name, 'discount' => $discount, 'scope' => $scope,
                'category_id' => $scope === 'category' ? $categoryId : null,
                'starts_at' => $startsAt, 'ends_at' => $endsAt, 'is_active' => $isActive,
            ]);
        }

        header('Location: /market/admin/promotions');
        exit;
    }
}

$categories = $db->query('SELECT id, name FROM categories ORDER BY sort_order')->fetchAll();

$editing = null;
$formAction = (string) ($_GET['action'] ?? '');

if ($formAction === 'new') {
    $editing = [
        'id' => 0, 'name' => '', 'discount_percent' => '', 'category_id' => '',
        'starts_at' => date('Y-m-d'), 'ends_at' => date('Y-m-d', strtotime('+7 days')), 'is_active' => 1,
    ];
} elseif ($formAction === 'edit' && isset($_GET['id'])) {
    $stmt = $db->prepare('SELECT * FROM promotions WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['id']]);
    $editing = $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
    $editing = $_POST;
}

$pageTitle = $editing ? (($editing['id'] ?? 0) ? 'Modifier la promotion' : 'Nouvelle promotion') : 'Promotions';
require_once __DIR__ . '/../includes/admin_header.php';

if ($editing):
?>

    <div class="card">
        <div class="admin-toolbar">
            <h2><?= ($editing['id'] ?? 0) ? 'Modifier la promotion' : 'Nouvelle promotion' ?></h2>
            <a href="/market/admin/promotions" class="link-more"><?= icon('chevron-right', 14) ?> Retour à la liste</a>
        </div>

        <form method="post" action="/market/admin/promotions" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

            <div class="form-field <?= isset($errors['name']) ? 'has-error' : '' ?>">
                <label for="name">Nom de la promotion *</label>
                <input type="text" id="name" name="name" value="<?= e((string) ($editing['name'] ?? '')) ?>" placeholder="Ex : Soldes de rentrée" required>
                <?php if (isset($errors['name'])): ?><span class="field-error"><?= e($errors['name']) ?></span><?php endif; ?>
            </div>

            <div class="form-row">
                <div class="form-field <?= isset($errors['discount_percent']) ? 'has-error' : '' ?>">
                    <label for="discount_percent">Remise (%) *</label>
                    <input type="number" id="discount_percent" name="discount_percent" min="1" max="99" value="<?= e((string) ($editing['discount_percent'] ?? '')) ?>" required>
                    <?php if (isset($errors['discount_percent'])): ?><span class="field-error"><?= e($errors['discount_percent']) ?></span><?php endif; ?>
                </div>
                <div class="form-field">
                    <label for="category_id">Portée</label>
                    <select id="category_id" name="category_id">
                        <option value="">Tout le site</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int) $cat['id'] ?>" <?= (string) ($editing['category_id'] ?? '') === (string) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
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

            <div class="form-field">
                <label class="filter-toggle">
                    <input type="checkbox" name="is_active" value="1" <?= !empty($editing['is_active']) || !isset($editing['id']) ? 'checked' : '' ?>>
                    <span>Activer cette promotion</span>
                </label>
            </div>

            <button type="submit" class="btn btn-primary">Enregistrer</button>
        </form>
    </div>

<?php else:
    $promotions = $db->query('
        SELECT p.*, c.name AS category_name
        FROM promotions p
        LEFT JOIN categories c ON c.id = p.category_id
        ORDER BY p.starts_at DESC
    ')->fetchAll();
    $today = date('Y-m-d');
?>

    <div class="card">
        <div class="admin-toolbar">
            <h2>Promotions (<?= count($promotions) ?>)</h2>
            <a href="/market/admin/promotions?action=new" class="btn btn-primary btn-sm"><?= icon('plus', 14) ?> Nouvelle promotion</a>
        </div>

        <?php if (!$promotions): ?>
            <p class="empty-state">Aucune promotion pour le moment.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>Remise</th>
                            <th>Portée</th>
                            <th>Période</th>
                            <th>Statut</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($promotions as $promo):
                            $isLive = (bool) $promo['is_active'] && $today >= $promo['starts_at'] && $today <= $promo['ends_at'];
                            $isFuture = $today < $promo['starts_at'];
                        ?>
                            <tr>
                                <td><?= e($promo['name']) ?></td>
                                <td>-<?= (int) $promo['discount_percent'] ?>%</td>
                                <td><?= $promo['category_name'] ? e($promo['category_name']) : 'Tout le site' ?></td>
                                <td><?= e(date('d/m/Y', strtotime((string) $promo['starts_at']))) ?> – <?= e(date('d/m/Y', strtotime((string) $promo['ends_at']))) ?></td>
                                <td>
                                    <?php if (!$promo['is_active']): ?>
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
                                        <a href="/market/admin/promotion-detail?id=<?= (int) $promo['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a>
                                        <a href="/market/admin/promotions?action=edit&id=<?= (int) $promo['id'] ?>" class="btn btn-outline-primary btn-sm">Modifier</a>
                                        <form method="post" action="/market/admin/promotions" onsubmit="return confirm('Supprimer cette promotion ?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $promo['id'] ?>">
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
