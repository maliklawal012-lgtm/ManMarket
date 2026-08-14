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
$payErrors = [];

/* ---------- CRUD plans ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_plan') {
    try {
        $stmt = $db->prepare('DELETE FROM subscription_plans WHERE id = :id');
        $stmt->execute(['id' => (int) $_POST['id']]);
    } catch (PDOException $e) {
        // des abonnements passes referencent encore ce plan (ON DELETE SET NULL) : rien a bloquer.
    }
    header('Location: /market/admin/abonnements.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_plan') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $durationMonths = (int) ($_POST['duration_months'] ?? 0);
    $price = (int) ($_POST['price'] ?? 0);
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        $errors['name'] = 'Veuillez indiquer un nom.';
    }
    if ($durationMonths <= 0) {
        $errors['duration_months'] = 'La durée doit être supérieure à 0.';
    }
    if ($price <= 0) {
        $errors['price'] = 'Le prix doit être supérieur à 0.';
    }

    if (!$errors) {
        if ($id > 0) {
            $stmt = $db->prepare('UPDATE subscription_plans SET name = :name, duration_months = :d, price = :price, is_active = :active WHERE id = :id');
            $stmt->execute(['name' => $name, 'd' => $durationMonths, 'price' => $price, 'active' => $isActive, 'id' => $id]);
        } else {
            $stmt = $db->prepare('INSERT INTO subscription_plans (name, duration_months, price, is_active) VALUES (:name, :d, :price, :active)');
            $stmt->execute(['name' => $name, 'd' => $durationMonths, 'price' => $price, 'active' => $isActive]);
        }
        header('Location: /market/admin/abonnements.php');
        exit;
    }
}

/* ---------- Enregistrer un paiement (renouvellement) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    $shopId = (int) ($_POST['shop_id'] ?? 0);
    $planId = (int) ($_POST['plan_id'] ?? 0);

    $stmt = $db->prepare('SELECT * FROM shops WHERE id = :id');
    $stmt->execute(['id' => $shopId]);
    $shop = $stmt->fetch();

    $stmt = $db->prepare('SELECT * FROM subscription_plans WHERE id = :id');
    $stmt->execute(['id' => $planId]);
    $plan = $stmt->fetch();

    if (!$shop) {
        $payErrors['shop_id'] = 'Boutique introuvable.';
    }
    if (!$plan) {
        $payErrors['plan_id'] = 'Veuillez choisir un plan.';
    }

    if (!$payErrors) {
        apply_subscription_payment($shopId, $plan, (int) $plan['price']);

        header('Location: /market/admin/abonnements.php?paid=1');
        exit;
    }
}

$paySuccess = ($_GET['paid'] ?? '') === '1';

$editing = null;
$formAction = (string) ($_GET['action'] ?? '');
if ($formAction === 'new_plan') {
    $editing = ['id' => 0, 'name' => '', 'duration_months' => 12, 'price' => '', 'is_active' => 1];
} elseif ($formAction === 'edit_plan' && isset($_GET['id'])) {
    $stmt = $db->prepare('SELECT * FROM subscription_plans WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['id']]);
    $editing = $stmt->fetch() ?: null;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
    $editing = $_POST;
}

$pageTitle = 'Abonnements';
require_once __DIR__ . '/../includes/admin_header.php';

$plans = $db->query('SELECT * FROM subscription_plans ORDER BY sort_order, duration_months')->fetchAll();

$shops = $db->query('SELECT * FROM shops ORDER BY name')->fetchAll();
foreach ($shops as &$s) {
    $s['subscription'] = get_shop_latest_subscription((int) $s['id']);
}
unset($s);
$today = date('Y-m-d');
?>

<?php if ($editing): ?>

    <div class="card">
        <div class="admin-toolbar">
            <h2><?= ($editing['id'] ?? 0) ? 'Modifier le plan' : 'Nouveau plan' ?></h2>
            <a href="/market/admin/abonnements.php" class="link-more"><?= icon('chevron-right', 14) ?> Retour</a>
        </div>

        <form method="post" action="/market/admin/abonnements.php" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_plan">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

            <div class="form-field <?= isset($errors['name']) ? 'has-error' : '' ?>">
                <label for="name">Nom du plan *</label>
                <input type="text" id="name" name="name" value="<?= e((string) ($editing['name'] ?? '')) ?>" placeholder="Ex : 1 an" required>
                <?php if (isset($errors['name'])): ?><span class="field-error"><?= e($errors['name']) ?></span><?php endif; ?>
            </div>

            <div class="form-row">
                <div class="form-field <?= isset($errors['duration_months']) ? 'has-error' : '' ?>">
                    <label for="duration_months">Durée (en mois) *</label>
                    <input type="number" id="duration_months" name="duration_months" min="1" value="<?= e((string) ($editing['duration_months'] ?? '')) ?>" required>
                    <?php if (isset($errors['duration_months'])): ?><span class="field-error"><?= e($errors['duration_months']) ?></span><?php endif; ?>
                </div>
                <div class="form-field <?= isset($errors['price']) ? 'has-error' : '' ?>">
                    <label for="price">Prix (FCFA) *</label>
                    <input type="number" id="price" name="price" min="1" value="<?= e((string) ($editing['price'] ?? '')) ?>" required>
                    <?php if (isset($errors['price'])): ?><span class="field-error"><?= e($errors['price']) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="form-field">
                <label class="filter-toggle">
                    <input type="checkbox" name="is_active" value="1" <?= !empty($editing['is_active']) || !isset($editing['id']) ? 'checked' : '' ?>>
                    <span>Plan proposable (visible lors de l'enregistrement d'un paiement)</span>
                </label>
            </div>

            <button type="submit" class="btn btn-primary">Enregistrer</button>
        </form>
    </div>

<?php else: ?>

    <div class="card" style="margin-bottom: var(--gap);">
        <div class="admin-toolbar">
            <h2>Plans d'abonnement (<?= count($plans) ?>)</h2>
            <a href="/market/admin/abonnements.php?action=new_plan" class="btn btn-primary btn-sm"><?= icon('plus', 14) ?> Nouveau plan</a>
        </div>

        <?php if (!$plans): ?>
            <p class="empty-state">Aucun plan pour le moment.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Plan</th>
                            <th>Durée</th>
                            <th>Prix</th>
                            <th>Statut</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($plans as $plan): ?>
                            <tr>
                                <td><?= e($plan['name']) ?></td>
                                <td><?= (int) $plan['duration_months'] ?> mois</td>
                                <td><?= format_price((int) $plan['price']) ?></td>
                                <td><span class="tag <?= $plan['is_active'] ? 'tag-open' : 'tag-closed' ?>"><?= $plan['is_active'] ? 'Actif' : 'Désactivé' ?></span></td>
                                <td>
                                    <div class="admin-table-actions">
                                        <a href="/market/admin/abonnements.php?action=edit_plan&id=<?= (int) $plan['id'] ?>" class="btn btn-outline-primary btn-sm">Modifier</a>
                                        <form method="post" action="/market/admin/abonnements.php" onsubmit="return confirm('Supprimer ce plan ?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_plan">
                                            <input type="hidden" name="id" value="<?= (int) $plan['id'] ?>">
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

    <div class="card">
        <div class="admin-toolbar">
            <h2>Boutiques & abonnements (<?= count($shops) ?>)</h2>
        </div>

        <?php if ($paySuccess): ?>
            <div class="alert alert-success"><?= icon('check-circle', 18) ?><span>Paiement enregistré, l'abonnement a été mis à jour.</span></div>
        <?php endif; ?>
        <?php if ($payErrors): ?>
            <div class="alert alert-error"><?= icon('x', 18) ?><span><?= e(implode(' ', $payErrors)) ?></span></div>
        <?php endif; ?>

        <?php if (!$plans): ?>
            <p class="empty-state">Créez au moins un plan avant de pouvoir enregistrer un paiement.</p>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Boutique</th>
                        <th>Abonnement</th>
                        <th>Jusqu'au</th>
                        <th>Statut</th>
                        <th></th>
                        <?php if ($plans): ?><th>Enregistrer un paiement</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($shops as $shop): $sub = $shop['subscription']; ?>
                        <tr>
                            <td><?= e($shop['name']) ?></td>
                            <td><?= $sub ? e($sub['plan_name']) : '—' ?></td>
                            <td><?= $sub ? e(date('d/m/Y', strtotime((string) $sub['ends_at']))) : '—' ?></td>
                            <td>
                                <?php
                                $daysLeft = $sub ? (int) floor((strtotime((string) $sub['ends_at']) - strtotime($today)) / 86400) : null;
                                ?>
                                <?php if ($sub && $daysLeft >= 0 && $daysLeft <= 7): ?>
                                    <span class="tag tag-pending">Expire bientôt</span>
                                <?php elseif ($sub && $sub['ends_at'] >= $today): ?>
                                    <span class="tag tag-open">Actif</span>
                                <?php elseif ($sub): ?>
                                    <span class="tag tag-closed">Expiré</span>
                                <?php else: ?>
                                    <span class="tag">Aucun abonnement</span>
                                <?php endif; ?>
                            </td>
                            <td><?php if ($sub): ?><a href="/market/admin/abonnement-detail.php?id=<?= (int) $sub['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a><?php endif; ?></td>
                            <?php if ($plans): ?>
                                <td>
                                    <form method="post" action="/market/admin/abonnements.php" class="admin-inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="pay">
                                        <input type="hidden" name="shop_id" value="<?= (int) $shop['id'] ?>">
                                        <select name="plan_id" class="admin-inline-select">
                                            <?php foreach ($plans as $plan): ?>
                                                <?php if ($plan['is_active']): ?>
                                                    <option value="<?= (int) $plan['id'] ?>"><?= e($plan['name']) ?> — <?= format_price((int) $plan['price']) ?></option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-outline-primary btn-sm">Enregistrer le paiement</button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="char-count">Le paiement est reçu hors-ligne (Mobile Money, espèces...) puis enregistré ici. Un nouveau paiement prolonge l'abonnement existant s'il est encore actif, ou en démarre un nouveau à partir d'aujourd'hui.</p>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
