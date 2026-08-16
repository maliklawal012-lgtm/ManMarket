<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/wallet_bootstrap.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

const SHOP_LOGO_UPLOAD_DIR = __DIR__ . '/../assets/uploads/shops/';
const SHOP_LOGO_UPLOAD_WEB_PATH = 'assets/uploads/shops/';

$db = get_db();
$errors = [];
$deleteError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    $stmt = $db->prepare('UPDATE shops SET is_open = IF(is_open = 1, 0, 1) WHERE id = :id');
    $stmt->execute(['id' => (int) $_POST['toggle_id']]);
    header('Location: /market/admin/boutiques.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    try {
        $stmt = $db->prepare('SELECT logo FROM shops WHERE id = :id');
        $stmt->execute(['id' => (int) $_POST['id']]);
        $logoToDelete = $stmt->fetchColumn();

        $stmt = $db->prepare('DELETE FROM shops WHERE id = :id');
        $stmt->execute(['id' => (int) $_POST['id']]);
        if ($logoToDelete) {
            delete_uploaded_image($logoToDelete);
        }
        header('Location: /market/admin/boutiques.php');
        exit;
    } catch (PDOException $e) {
        $deleteError = "Impossible de supprimer cette boutique : des produits y sont encore associés.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_request') {
    $requestId = (int) ($_POST['id'] ?? 0);
    $stmt = $db->prepare("UPDATE shops SET approval_status = 'approved', rejection_reason = NULL WHERE id = :id AND approval_status = 'pending'");
    $stmt->execute(['id' => $requestId]);
    if ($stmt->rowCount() > 0) {
        wallet_notification_service()->shopApprovalDecision($requestId, true, null);
    }
    header('Location: /market/admin/boutiques.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject_request') {
    $requestId = (int) ($_POST['id'] ?? 0);
    $reason = trim((string) ($_POST['reason'] ?? '')) ?: null;
    $stmt = $db->prepare("UPDATE shops SET approval_status = 'rejected', rejection_reason = :reason WHERE id = :id AND approval_status = 'pending'");
    $stmt->execute(['reason' => $reason, 'id' => $requestId]);
    if ($stmt->rowCount() > 0) {
        wallet_notification_service()->shopApprovalDecision($requestId, false, $reason);
    }
    header('Location: /market/admin/boutiques.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $neighborhood = trim((string) ($_POST['neighborhood'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $whatsapp = trim((string) ($_POST['whatsapp'] ?? ''));
    $color = trim((string) ($_POST['color'] ?? '')) ?: '#16a34a';
    $logoLetter = mb_strtoupper(trim((string) ($_POST['logo_letter'] ?? '')));
    $fastDelivery = isset($_POST['fast_delivery']) ? 1 : 0;
    $isOpen = isset($_POST['is_open']) ? 1 : 0;
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);
    $ownerIdRaw = (int) ($_POST['owner_id'] ?? 0);
    $ownerId = $ownerIdRaw > 0 ? $ownerIdRaw : null;
    $removeLogo = isset($_POST['remove_logo']);

    if ($name === '') {
        $errors['name'] = 'Veuillez indiquer un nom.';
    }
    if ($neighborhood === '') {
        $errors['neighborhood'] = 'Veuillez indiquer un quartier.';
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $errors['color'] = 'Couleur invalide.';
    }
    if ($logoLetter === '') {
        $logoLetter = mb_substr($name, 0, 2);
    }
    $logoLetter = mb_substr($logoLetter, 0, 2);

    $hasLogoUpload = isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE;
    $newLogoExt = null;
    if ($hasLogoUpload) {
        $validation = validate_uploaded_image($_FILES['logo']);
        if ($validation['error']) {
            $errors['logo'] = $validation['error'];
        } else {
            $newLogoExt = $validation['ext'];
        }
    }

    if (!$errors) {
        $baseSlug = slugify($name) ?: 'boutique';
        $slug = $baseSlug;
        $suffix = 2;
        while (true) {
            $stmt = $db->prepare('SELECT id FROM shops WHERE slug = :slug AND id != :id');
            $stmt->execute(['slug' => $slug, 'id' => $id]);
            if (!$stmt->fetch()) {
                break;
            }
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        // La nouvelle architecture portefeuille (commandes/wallets) exige une ligne
        // `vendors` liee au proprietaire de la boutique. On la cree ici, au moment ou
        // l'admin assigne explicitement un proprietaire — jamais implicitement pendant
        // une commande client (voir OrderService::resolveVendorId).
        $vendorId = null;
        if ($ownerId !== null) {
            $stmt = $db->prepare('SELECT id FROM vendors WHERE user_id = :user_id');
            $stmt->execute(['user_id' => $ownerId]);
            $vendorId = $stmt->fetchColumn();
            if ($vendorId === false) {
                $db->prepare('INSERT INTO vendors (user_id, business_name, status) VALUES (:user_id, :business_name, "active")')->execute([
                    'user_id' => $ownerId,
                    'business_name' => $name,
                ]);
                $vendorId = (int) $db->lastInsertId();
            } else {
                $vendorId = (int) $vendorId;
            }
        }

        $currentLogo = null;
        if ($id > 0) {
            $stmt = $db->prepare('SELECT logo FROM shops WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $currentLogo = $stmt->fetchColumn() ?: null;
        }
        if ($newLogoExt) {
            $finalLogo = store_uploaded_image($_FILES['logo'], $newLogoExt, SHOP_LOGO_UPLOAD_DIR, SHOP_LOGO_UPLOAD_WEB_PATH);
            delete_uploaded_image($currentLogo);
        } elseif ($removeLogo) {
            delete_uploaded_image($currentLogo);
            $finalLogo = null;
        } else {
            $finalLogo = $currentLogo;
        }

        if ($id > 0) {
            $stmt = $db->prepare('
                UPDATE shops
                SET name = :name, slug = :slug, neighborhood = :neighborhood, phone = :phone, whatsapp = :whatsapp, color = :color,
                    logo_letter = :logo_letter, logo = :logo, fast_delivery = :fast_delivery, is_open = :is_open, sort_order = :sort_order,
                    owner_id = :owner_id, vendor_id = :vendor_id
                WHERE id = :id
            ');
            $stmt->execute([
                'name' => $name, 'slug' => $slug, 'neighborhood' => $neighborhood, 'phone' => $phone !== '' ? $phone : null, 'whatsapp' => $whatsapp !== '' ? $whatsapp : null, 'color' => $color,
                'logo_letter' => $logoLetter, 'logo' => $finalLogo, 'fast_delivery' => $fastDelivery, 'is_open' => $isOpen, 'sort_order' => $sortOrder,
                'owner_id' => $ownerId, 'vendor_id' => $vendorId, 'id' => $id,
            ]);
        } else {
            $stmt = $db->prepare('
                INSERT INTO shops (name, slug, neighborhood, phone, whatsapp, color, logo_letter, logo, fast_delivery, is_open, sort_order, owner_id, vendor_id, rating, review_count)
                VALUES (:name, :slug, :neighborhood, :phone, :whatsapp, :color, :logo_letter, :logo, :fast_delivery, :is_open, :sort_order, :owner_id, :vendor_id, 5.0, 0)
            ');
            $stmt->execute([
                'name' => $name, 'slug' => $slug, 'neighborhood' => $neighborhood, 'phone' => $phone !== '' ? $phone : null, 'whatsapp' => $whatsapp !== '' ? $whatsapp : null, 'color' => $color,
                'logo_letter' => $logoLetter, 'logo' => $finalLogo, 'fast_delivery' => $fastDelivery, 'is_open' => $isOpen, 'sort_order' => $sortOrder,
                'owner_id' => $ownerId, 'vendor_id' => $vendorId,
            ]);
        }

        header('Location: /market/admin/boutiques.php');
        exit;
    }
}

$vendors = $db->query("SELECT id, name, email FROM users WHERE is_vendor = 1 ORDER BY name")->fetchAll();

$editing = null;
$formAction = (string) ($_GET['action'] ?? '');

if ($formAction === 'new') {
    $editing = [
        'id' => 0, 'name' => '', 'neighborhood' => '', 'phone' => '', 'whatsapp' => '', 'color' => '#16a34a',
        'logo_letter' => '', 'fast_delivery' => 0, 'is_open' => 1, 'sort_order' => 0, 'owner_id' => '',
    ];
} elseif ($formAction === 'edit' && isset($_GET['id'])) {
    $stmt = $db->prepare('SELECT * FROM shops WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['id']]);
    $editing = $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
    $editing = $_POST;
}

$pageTitle = $editing ? (($editing['id'] ?? 0) ? 'Modifier la boutique' : 'Nouvelle boutique') : 'Boutiques';
require_once __DIR__ . '/../includes/admin_header.php';

if ($editing):
?>

    <div class="card">
        <div class="admin-toolbar">
            <h2><?= ($editing['id'] ?? 0) ? 'Modifier la boutique' : 'Nouvelle boutique' ?></h2>
            <a href="/market/admin/boutiques.php" class="link-more"><?= icon('chevron-right', 14) ?> Retour à la liste</a>
        </div>

        <form method="post" action="/market/admin/boutiques.php" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

            <div class="form-field <?= isset($errors['name']) ? 'has-error' : '' ?>">
                <label for="name">Nom de la boutique *</label>
                <input type="text" id="name" name="name" value="<?= e((string) ($editing['name'] ?? '')) ?>" required>
                <?php if (isset($errors['name'])): ?><span class="field-error"><?= e($errors['name']) ?></span><?php endif; ?>
            </div>

            <div class="form-row">
                <div class="form-field <?= isset($errors['neighborhood']) ? 'has-error' : '' ?>">
                    <label for="neighborhood">Quartier *</label>
                    <input type="text" id="neighborhood" name="neighborhood" value="<?= e((string) ($editing['neighborhood'] ?? '')) ?>" placeholder="Ex : Madina, Man" required>
                    <?php if (isset($errors['neighborhood'])): ?><span class="field-error"><?= e($errors['neighborhood']) ?></span><?php endif; ?>
                </div>
                <div class="form-field">
                    <label for="logo_letter">Sigle (2 lettres, optionnel)</label>
                    <input type="text" id="logo_letter" name="logo_letter" value="<?= e((string) ($editing['logo_letter'] ?? '')) ?>" maxlength="2" placeholder="Auto si vide">
                </div>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label for="phone">Téléphone de la boutique (optionnel)</label>
                    <input type="text" id="phone" name="phone" value="<?= e((string) ($editing['phone'] ?? '')) ?>" placeholder="+225 07 00 00 00 00">
                </div>
                <div class="form-field">
                    <label for="whatsapp">WhatsApp (optionnel, format international sans le +)</label>
                    <input type="text" id="whatsapp" name="whatsapp" value="<?= e((string) ($editing['whatsapp'] ?? '')) ?>" placeholder="225070000000">
                </div>
            </div>

            <div class="form-row">
                <div class="form-field <?= isset($errors['color']) ? 'has-error' : '' ?>">
                    <label for="color">Couleur</label>
                    <input type="color" id="color" name="color" value="<?= e((string) ($editing['color'] ?? '#16a34a')) ?>" style="height:44px; padding:4px;">
                    <?php if (isset($errors['color'])): ?><span class="field-error"><?= e($errors['color']) ?></span><?php endif; ?>
                </div>
                <div class="form-field">
                    <label for="sort_order">Ordre d'affichage</label>
                    <input type="number" id="sort_order" name="sort_order" value="<?= e((string) ($editing['sort_order'] ?? 0)) ?>">
                </div>
            </div>

            <div class="form-field">
                <label for="owner_id">Propriétaire (compte vendeur)</label>
                <select id="owner_id" name="owner_id">
                    <option value="">Aucun (gérée par l'administration)</option>
                    <?php foreach ($vendors as $vendor): ?>
                        <option value="<?= (int) $vendor['id'] ?>" <?= (string) ($editing['owner_id'] ?? '') === (string) $vendor['id'] ? 'selected' : '' ?>><?= e($vendor['name']) ?> (<?= e($vendor['email']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <span class="char-count">Seuls les comptes "Vendeur" (Utilisateurs → Commerçants) apparaissent ici. Le propriétaire pourra gérer les produits de cette boutique depuis son espace vendeur.</span>
            </div>

            <div class="form-field">
                <label class="filter-toggle">
                    <input type="checkbox" name="fast_delivery" value="1" <?= !empty($editing['fast_delivery']) ? 'checked' : '' ?>>
                    <span>Livraison rapide</span>
                </label>
            </div>
            <div class="form-field">
                <label class="filter-toggle">
                    <input type="checkbox" name="is_open" value="1" <?= !empty($editing['is_open']) || !isset($editing['id']) ? 'checked' : '' ?>>
                    <span>Boutique ouverte</span>
                </label>
            </div>

            <button type="submit" class="btn btn-primary">Enregistrer</button>
        </form>
    </div>

<?php else:
    $shops = $db->query('
        SELECT s.*, COUNT(DISTINCT p.id) AS product_count, u.name AS owner_name
        FROM shops s
        LEFT JOIN products p ON p.shop_id = s.id
        LEFT JOIN users u ON u.id = s.owner_id
        GROUP BY s.id
        ORDER BY s.sort_order
    ')->fetchAll();
    $shops = attach_live_shop_ratings($shops);

    $pendingRequests = $db->query("
        SELECT s.*, u.name AS owner_name, u.email AS owner_email
        FROM shops s
        JOIN users u ON u.id = s.owner_id
        WHERE s.approval_status = 'pending'
        ORDER BY s.id
    ")->fetchAll();
?>

    <?php if ($pendingRequests): ?>
    <div class="card">
        <div class="admin-toolbar">
            <h2>Demandes de boutique en attente (<?= count($pendingRequests) ?>)</h2>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Boutique</th>
                        <th>Quartier</th>
                        <th>Demandeur</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingRequests as $req): ?>
                        <tr>
                            <td>
                                <div class="shop-logo" style="background:<?= e($req['color']) ?>; width:32px; height:32px; font-size:.72rem; display:inline-flex; vertical-align:middle; margin-right:8px;"><?= shop_logo_html($req) ?></div>
                                <?= e($req['name']) ?>
                            </td>
                            <td><?= e($req['neighborhood']) ?></td>
                            <td><?= e($req['owner_name']) ?> (<?= e($req['owner_email']) ?>)</td>
                            <td>
                                <div class="admin-table-actions">
                                    <form method="post" action="/market/admin/boutiques.php" onsubmit="return confirm('Approuver cette demande de boutique ?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="approve_request">
                                        <input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
                                        <button type="submit" class="btn btn-primary btn-sm">Approuver</button>
                                    </form>
                                    <details>
                                        <summary class="btn btn-outline-primary btn-sm" style="display:inline-block; cursor:pointer;">Refuser</summary>
                                        <form method="post" action="/market/admin/boutiques.php" style="margin-top:8px; min-width:220px;" onsubmit="return confirm('Refuser cette demande de boutique ?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="reject_request">
                                            <input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
                                            <div class="form-field">
                                                <label>Motif</label>
                                                <input type="text" name="reason" required>
                                            </div>
                                            <button type="submit" class="btn btn-primary btn-sm">Confirmer le refus</button>
                                        </form>
                                    </details>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="admin-toolbar">
            <h2>Boutiques (<?= count($shops) ?>)</h2>
            <div class="admin-table-actions">
                <a href="/market/admin/abonnements.php" class="link-more"><?= icon('clock', 14) ?> Gérer les abonnements</a>
                <a href="/market/admin/boutiques.php?action=new" class="btn btn-primary btn-sm"><?= icon('plus', 14) ?> Nouvelle boutique</a>
            </div>
        </div>

        <?php if ($deleteError): ?>
            <div class="alert alert-error"><?= icon('x', 18) ?><span><?= e($deleteError) ?></span></div>
        <?php endif; ?>

        <?php if (!$shops): ?>
            <p class="empty-state">Aucune boutique pour le moment.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Boutique</th>
                            <th>Quartier</th>
                            <th>Propriétaire</th>
                            <th>Note</th>
                            <th>Produits</th>
                            <th>Statut</th>
                            <th>Visible sur le site</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shops as $shop): $shopSub = get_shop_active_subscription((int) $shop['id']); ?>
                            <tr>
                                <td>
                                    <div class="shop-logo" style="background:<?= e($shop['color']) ?>; width:32px; height:32px; font-size:.72rem; display:inline-flex; vertical-align:middle; margin-right:8px;"><?= shop_logo_html($shop) ?></div>
                                    <?= e($shop['name']) ?>
                                </td>
                                <td><?= e($shop['neighborhood']) ?></td>
                                <td><?= $shop['owner_name'] ? e($shop['owner_name']) : '—' ?></td>
                                <td><?= $shop['review_count'] > 0 ? number_format((float) $shop['rating'], 1) . ' (' . (int) $shop['review_count'] . ')' : '—' ?></td>
                                <td><?= (int) $shop['product_count'] ?></td>
                                <td><span class="tag <?= $shop['is_open'] ? 'tag-open' : 'tag-closed' ?>"><?= $shop['is_open'] ? 'Ouvert' : 'Fermé' ?></span></td>
                                <td>
                                    <?php $isVisible = $shopSub && $shop['approval_status'] === 'approved'; ?>
                                    <span class="tag <?= $isVisible ? 'tag-open' : 'tag-closed' ?>">
                                        <?php if ($shop['approval_status'] === 'pending'): ?>Invisible (en attente d'approbation)
                                        <?php elseif ($shop['approval_status'] === 'rejected'): ?>Invisible (demande refusée)
                                        <?php elseif ($shopSub): ?>Visible (abonnement actif)
                                        <?php else: ?>Invisible (sans abonnement)
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="admin-table-actions">
                                        <a href="/market/admin/boutique-detail.php?id=<?= (int) $shop['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a>
                                        <form method="post" action="/market/admin/boutiques.php">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="toggle_id" value="<?= (int) $shop['id'] ?>">
                                            <button type="submit" class="btn btn-outline-primary btn-sm"><?= $shop['is_open'] ? 'Fermer' : 'Ouvrir' ?></button>
                                        </form>
                                        <a href="/market/admin/boutiques.php?action=edit&id=<?= (int) $shop['id'] ?>" class="btn btn-outline-primary btn-sm">Modifier</a>
                                        <form method="post" action="/market/admin/boutiques.php" onsubmit="return confirm('Supprimer cette boutique ?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $shop['id'] ?>">
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
