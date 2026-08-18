<?php
declare(strict_types=1);

$pageTitle = 'Créer ma boutique';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

const VENDOR_SHOP_LOGO_UPLOAD_DIR = __DIR__ . '/../assets/uploads/shops/';
const VENDOR_SHOP_LOGO_UPLOAD_WEB_PATH = 'assets/uploads/shops/';

$db = get_db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'become_vendor') {
    $db->prepare('UPDATE users SET is_vendor = 1 WHERE id = :id')->execute(['id' => $user['id']]);
    header('Location: /market/vendeur/demande-boutique.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save' && $user['is_vendor']) {
    $existing = current_vendor_shop_request((int) $user['id']);

    $name = trim((string) ($_POST['name'] ?? ''));
    $neighborhood = trim((string) ($_POST['neighborhood'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $whatsapp = trim((string) ($_POST['whatsapp'] ?? ''));
    $color = trim((string) ($_POST['color'] ?? '')) ?: '#16a34a';
    $logoLetter = mb_strtoupper(trim((string) ($_POST['logo_letter'] ?? '')));
    $removeLogo = isset($_POST['remove_logo']);

    if ($name === '') {
        $errors['name'] = 'Veuillez indiquer un nom.';
    }
    if ($neighborhood === '') {
        $errors['neighborhood'] = 'Veuillez indiquer un quartier.';
    }
    if ($phone === '' || !validate_phone_number($phone)) {
        $errors['phone'] = 'Veuillez indiquer un numéro de téléphone ivoirien valide (10 chiffres, ex : 07 00 00 00 00).';
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
        $excludeId = $existing['id'] ?? 0;
        while (true) {
            $stmt = $db->prepare('SELECT id FROM shops WHERE slug = :slug AND id != :id');
            $stmt->execute(['slug' => $slug, 'id' => $excludeId]);
            if (!$stmt->fetch()) {
                break;
            }
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        // Meme logique que admin/boutiques.php : une entite vendors est requise
        // par l'architecture portefeuille, creee ici au moment ou le vendeur
        // demande sa premiere boutique.
        $stmt = $db->prepare('SELECT id FROM vendors WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $user['id']]);
        $vendorId = $stmt->fetchColumn();
        if ($vendorId === false) {
            $db->prepare('INSERT INTO vendors (user_id, business_name, status) VALUES (:user_id, :business_name, "active")')->execute([
                'user_id' => $user['id'],
                'business_name' => $name,
            ]);
            $vendorId = (int) $db->lastInsertId();
        } else {
            $vendorId = (int) $vendorId;
        }

        $currentLogo = $existing['logo'] ?? null;
        if ($newLogoExt) {
            $finalLogo = store_uploaded_image($_FILES['logo'], $newLogoExt, VENDOR_SHOP_LOGO_UPLOAD_DIR, VENDOR_SHOP_LOGO_UPLOAD_WEB_PATH);
            delete_uploaded_image($currentLogo);
        } elseif ($removeLogo) {
            delete_uploaded_image($currentLogo);
            $finalLogo = null;
        } else {
            $finalLogo = $currentLogo;
        }

        if ($existing) {
            // Demande refusee, corrigee et resoumise : repasse en attente.
            $stmt = $db->prepare('
                UPDATE shops
                SET name = :name, slug = :slug, neighborhood = :neighborhood, phone = :phone, whatsapp = :whatsapp, color = :color,
                    logo_letter = :logo_letter, logo = :logo, vendor_id = :vendor_id, approval_status = "pending", rejection_reason = NULL
                WHERE id = :id
            ');
            $stmt->execute([
                'name' => $name, 'slug' => $slug, 'neighborhood' => $neighborhood, 'phone' => $phone !== '' ? $phone : null, 'whatsapp' => $whatsapp !== '' ? $whatsapp : null, 'color' => $color,
                'logo_letter' => $logoLetter, 'logo' => $finalLogo, 'vendor_id' => $vendorId, 'id' => $existing['id'],
            ]);
        } else {
            $stmt = $db->prepare('
                INSERT INTO shops (name, slug, neighborhood, phone, whatsapp, color, logo_letter, logo, is_open, owner_id, vendor_id, approval_status, rating, review_count)
                VALUES (:name, :slug, :neighborhood, :phone, :whatsapp, :color, :logo_letter, :logo, 1, :owner_id, :vendor_id, "pending", 5.0, 0)
            ');
            $stmt->execute([
                'name' => $name, 'slug' => $slug, 'neighborhood' => $neighborhood, 'phone' => $phone !== '' ? $phone : null, 'whatsapp' => $whatsapp !== '' ? $whatsapp : null, 'color' => $color,
                'logo_letter' => $logoLetter, 'logo' => $finalLogo, 'owner_id' => $user['id'], 'vendor_id' => $vendorId,
            ]);
        }

        header('Location: /market/vendeur/demande-boutique.php?submitted=1');
        exit;
    }
}

$shopRequest = $user['is_vendor'] ? current_vendor_shop_request((int) $user['id']) : null;
$submitted = ($_GET['submitted'] ?? '') === '1';

if ($shopRequest && $shopRequest['approval_status'] === 'approved') {
    header('Location: /market/vendeur/index.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <h1>Créer ma boutique</h1>
        <p>Ouvrez votre boutique sur ManMarket et gérez-la vous-même.</p>
    </div>
</section>

<section class="container auth-page">
    <div class="card auth-card">
        <?php if (!$user['is_vendor']): ?>
            <div class="card-header">
                <h2>Devenir vendeur</h2>
            </div>
            <p class="char-count">Pour créer une boutique, votre compte doit d'abord être un compte vendeur.</p>
            <form method="post" action="/market/vendeur/demande-boutique.php" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="become_vendor">
                <button type="submit" class="btn btn-primary btn-block">Devenir vendeur</button>
            </form>

        <?php elseif ($shopRequest && $shopRequest['approval_status'] === 'pending'): ?>
            <div class="card-header">
                <h2>Demande en cours d'examen</h2>
            </div>
            <div class="alert alert-success">
                <?= icon('check-circle', 18) ?>
                <span>Votre demande pour "<?= e($shopRequest['name']) ?>" a bien été reçue. Notre équipe l'examine et vous préviendra par email.</span>
            </div>

        <?php else: ?>
            <div class="card-header">
                <h2><?= $shopRequest ? 'Modifier et resoumettre ma demande' : 'Ma boutique' ?></h2>
            </div>

            <?php if ($submitted): ?>
                <div class="alert alert-success"><?= icon('check-circle', 18) ?><span>Votre demande a été envoyée.</span></div>
            <?php endif; ?>

            <?php if ($shopRequest && $shopRequest['approval_status'] === 'rejected'): ?>
                <div class="alert alert-error">
                    <?= icon('x', 18) ?>
                    <span>Votre précédente demande a été refusée<?= $shopRequest['rejection_reason'] ? ' : ' . e($shopRequest['rejection_reason']) : '.' ?> Vous pouvez corriger et resoumettre ci-dessous.</span>
                </div>
            <?php endif; ?>

            <form method="post" action="/market/vendeur/demande-boutique.php" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">

                <div class="form-field <?= isset($errors['name']) ? 'has-error' : '' ?>">
                    <label for="name">Nom de la boutique *</label>
                    <input type="text" id="name" name="name" value="<?= e((string) ($shopRequest['name'] ?? $_POST['name'] ?? '')) ?>" required>
                    <?php if (isset($errors['name'])): ?><span class="field-error"><?= e($errors['name']) ?></span><?php endif; ?>
                </div>

                <div class="form-row">
                    <div class="form-field <?= isset($errors['neighborhood']) ? 'has-error' : '' ?>">
                        <label for="neighborhood">Quartier *</label>
                        <input type="text" id="neighborhood" name="neighborhood" value="<?= e((string) ($shopRequest['neighborhood'] ?? $_POST['neighborhood'] ?? '')) ?>" placeholder="Ex : Madina, Man" required>
                        <?php if (isset($errors['neighborhood'])): ?><span class="field-error"><?= e($errors['neighborhood']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-field">
                        <label for="logo_letter">Sigle (2 lettres, si pas de logo)</label>
                        <input type="text" id="logo_letter" name="logo_letter" value="<?= e((string) ($shopRequest['logo_letter'] ?? '')) ?>" maxlength="2" placeholder="Auto si vide">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field <?= isset($errors['phone']) ? 'has-error' : '' ?>">
                        <label for="phone">Téléphone *</label>
                        <input type="text" id="phone" name="phone" value="<?= e((string) ($shopRequest['phone'] ?? '')) ?>" placeholder="+225 07 00 00 00 00" required>
                        <?php if (isset($errors['phone'])): ?><span class="field-error"><?= e($errors['phone']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-field">
                        <label for="whatsapp">WhatsApp (optionnel, sans le +)</label>
                        <input type="text" id="whatsapp" name="whatsapp" value="<?= e((string) ($shopRequest['whatsapp'] ?? '')) ?>" placeholder="225070000000">
                    </div>
                </div>

                <div class="form-field <?= isset($errors['color']) ? 'has-error' : '' ?>">
                    <label for="color">Couleur</label>
                    <input type="color" id="color" name="color" value="<?= e((string) ($shopRequest['color'] ?? '#16a34a')) ?>" style="height:44px; padding:4px;">
                    <?php if (isset($errors['color'])): ?><span class="field-error"><?= e($errors['color']) ?></span><?php endif; ?>
                </div>

                <div class="form-field <?= isset($errors['logo']) ? 'has-error' : '' ?>">
                    <label for="logo">Logo (optionnel)</label>
                    <input type="file" id="logo" name="logo" accept="image/jpeg,image/png,image/webp,image/gif">
                    <?php if (isset($errors['logo'])): ?><span class="field-error"><?= e($errors['logo']) ?></span><?php endif; ?>
                </div>

                <p class="char-count">Votre boutique sera examinée par l'équipe ManMarket avant d'être activée. Une fois approuvée, vous pourrez payer votre abonnement en ligne pour la rendre visible sur le site.</p>

                <button type="submit" class="btn btn-primary btn-block"><?= $shopRequest ? 'Resoumettre ma demande' : 'Envoyer ma demande' ?></button>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
