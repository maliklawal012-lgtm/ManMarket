<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

$vendorUser = require_vendor();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}
$vendorShop = current_vendor_shop((int) $vendorUser['id']);

if (!$vendorShop) {
    header('Location: /market/vendeur/index.php');
    exit;
}

const SHOP_LOGO_UPLOAD_DIR = __DIR__ . '/../assets/uploads/shops/';
const SHOP_LOGO_UPLOAD_WEB_PATH = 'assets/uploads/shops/';

$db = get_db();
$shopId = (int) $vendorShop['id'];
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $neighborhood = trim((string) ($_POST['neighborhood'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $whatsapp = trim((string) ($_POST['whatsapp'] ?? ''));
    $latRaw = trim((string) ($_POST['lat'] ?? ''));
    $lngRaw = trim((string) ($_POST['lng'] ?? ''));
    $lat = $latRaw !== '' ? (float) $latRaw : null;
    $lng = $lngRaw !== '' ? (float) $lngRaw : null;
    $color = trim((string) ($_POST['color'] ?? '')) ?: '#16a34a';
    $logoLetter = mb_strtoupper(trim((string) ($_POST['logo_letter'] ?? '')));
    $fastDelivery = isset($_POST['fast_delivery']) ? 1 : 0;
    $isOpen = isset($_POST['is_open']) ? 1 : 0;
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
    if ($lat !== null && ($lat < -90 || $lat > 90)) {
        $errors['lat'] = 'Latitude invalide (doit être entre -90 et 90).';
    }
    if ($lng !== null && ($lng < -180 || $lng > 180)) {
        $errors['lng'] = 'Longitude invalide (doit être entre -180 et 180).';
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
            $stmt->execute(['slug' => $slug, 'id' => $shopId]);
            if (!$stmt->fetch()) {
                break;
            }
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        $currentLogo = $vendorShop['logo'] ?? null;
        if ($newLogoExt) {
            $finalLogo = store_uploaded_image($_FILES['logo'], $newLogoExt, SHOP_LOGO_UPLOAD_DIR, SHOP_LOGO_UPLOAD_WEB_PATH);
            delete_uploaded_image($currentLogo);
        } elseif ($removeLogo) {
            delete_uploaded_image($currentLogo);
            $finalLogo = null;
        } else {
            $finalLogo = $currentLogo;
        }

        $stmt = $db->prepare('
            UPDATE shops
            SET name = :name, slug = :slug, neighborhood = :neighborhood, phone = :phone, whatsapp = :whatsapp, color = :color,
                logo_letter = :logo_letter, logo = :logo, fast_delivery = :fast_delivery, is_open = :is_open, lat = :lat, lng = :lng
            WHERE id = :id
        ');
        $stmt->execute([
            'name' => $name, 'slug' => $slug, 'neighborhood' => $neighborhood, 'phone' => $phone !== '' ? $phone : null, 'whatsapp' => $whatsapp !== '' ? $whatsapp : null, 'color' => $color,
            'logo_letter' => $logoLetter, 'logo' => $finalLogo, 'fast_delivery' => $fastDelivery, 'is_open' => $isOpen, 'lat' => $lat, 'lng' => $lng, 'id' => $shopId,
        ]);

        header('Location: /market/vendeur/parametres.php?saved=1');
        exit;
    }

    $vendorShop = array_merge($vendorShop, [
        'name' => $name, 'neighborhood' => $neighborhood, 'phone' => $phone, 'whatsapp' => $whatsapp, 'color' => $color,
        'logo_letter' => $logoLetter, 'fast_delivery' => $fastDelivery, 'is_open' => $isOpen, 'lat' => $latRaw, 'lng' => $lngRaw,
    ]);
}

$success = ($_GET['saved'] ?? '') === '1';

$pageTitle = 'Paramètres de la boutique';
require_once __DIR__ . '/../includes/vendor_header.php';
?>

<div class="card">
    <div class="admin-toolbar">
        <h2>Informations de la boutique</h2>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= icon('check-circle', 18) ?><span>Les informations de votre boutique ont été mises à jour.</span></div>
    <?php endif; ?>

    <form method="post" action="/market/vendeur/parametres.php" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <div class="form-field <?= isset($errors['name']) ? 'has-error' : '' ?>">
            <label for="name">Nom de la boutique *</label>
            <input type="text" id="name" name="name" value="<?= e((string) $vendorShop['name']) ?>" required>
            <?php if (isset($errors['name'])): ?><span class="field-error"><?= e($errors['name']) ?></span><?php endif; ?>
        </div>

        <div class="form-field <?= isset($errors['logo']) ? 'has-error' : '' ?>">
            <label for="logo">Logo de la boutique (optionnel)</label>
            <div style="display:flex; align-items:center; gap:12px;">
                <div class="shop-logo shop-logo-lg" style="background:<?= e((string) $vendorShop['color']) ?>"><?= shop_logo_html($vendorShop) ?></div>
                <input type="file" id="logo" name="logo" accept="image/*">
            </div>
            <?php if (isset($errors['logo'])): ?><span class="field-error"><?= e($errors['logo']) ?></span><?php endif; ?>
            <?php if (!empty($vendorShop['logo'])): ?>
                <label class="filter-toggle" style="margin-top:6px;">
                    <input type="checkbox" name="remove_logo" value="1">
                    <span>Supprimer le logo actuel (revenir au sigle par défaut)</span>
                </label>
            <?php endif; ?>
            <span class="char-count">Le logo sera visible sur votre page boutique et partout où votre boutique apparaît sur le site.</span>
        </div>

        <div class="form-row">
            <div class="form-field <?= isset($errors['neighborhood']) ? 'has-error' : '' ?>">
                <label for="neighborhood">Quartier *</label>
                <input type="text" id="neighborhood" name="neighborhood" value="<?= e((string) $vendorShop['neighborhood']) ?>" placeholder="Ex : Madina, Man" required>
                <?php if (isset($errors['neighborhood'])): ?><span class="field-error"><?= e($errors['neighborhood']) ?></span><?php endif; ?>
            </div>
            <div class="form-field">
                <label for="logo_letter">Sigle (2 lettres, si pas de logo)</label>
                <input type="text" id="logo_letter" name="logo_letter" value="<?= e((string) $vendorShop['logo_letter']) ?>" maxlength="2" placeholder="Auto si vide">
            </div>
        </div>

        <div class="form-row">
            <div class="form-field <?= isset($errors['phone']) ? 'has-error' : '' ?>">
                <label for="phone">Téléphone de la boutique *</label>
                <input type="text" id="phone" name="phone" value="<?= e((string) ($vendorShop['phone'] ?? '')) ?>" placeholder="+225 07 00 00 00 00" required>
                <?php if (isset($errors['phone'])): ?><span class="field-error"><?= e($errors['phone']) ?></span><?php endif; ?>
            </div>
            <div class="form-field">
                <label for="whatsapp">WhatsApp (optionnel, format international sans le +)</label>
                <input type="text" id="whatsapp" name="whatsapp" value="<?= e((string) ($vendorShop['whatsapp'] ?? '')) ?>" placeholder="225070000000">
                <span class="char-count">Les clients pourront vous écrire directement sur WhatsApp depuis la page de votre boutique.</span>
            </div>
        </div>

        <div class="form-field <?= isset($errors['color']) ? 'has-error' : '' ?>">
            <label for="color">Couleur</label>
            <input type="color" id="color" name="color" value="<?= e((string) $vendorShop['color']) ?>" style="height:44px; padding:4px;">
            <?php if (isset($errors['color'])): ?><span class="field-error"><?= e($errors['color']) ?></span><?php endif; ?>
        </div>

        <div class="form-row">
            <div class="form-field <?= isset($errors['lat']) ? 'has-error' : '' ?>">
                <label for="lat">Latitude (optionnel, position précise sur Google Maps)</label>
                <input type="number" id="lat" name="lat" step="any" min="-90" max="90" value="<?= e((string) ($vendorShop['lat'] ?? '')) ?>" placeholder="Ex : 7.4125">
                <?php if (isset($errors['lat'])): ?><span class="field-error"><?= e($errors['lat']) ?></span><?php endif; ?>
            </div>
            <div class="form-field <?= isset($errors['lng']) ? 'has-error' : '' ?>">
                <label for="lng">Longitude (optionnel)</label>
                <input type="number" id="lng" name="lng" step="any" min="-180" max="180" value="<?= e((string) ($vendorShop['lng'] ?? '')) ?>" placeholder="Ex : -7.5536">
                <?php if (isset($errors['lng'])): ?><span class="field-error"><?= e($errors['lng']) ?></span><?php endif; ?>
            </div>
        </div>
        <p class="char-count">Sans coordonnées, le bouton "Localiser sur Google Maps" de votre boutique fera une recherche par nom et quartier — pour un repérage précis, ouvrez Google Maps, faites un clic droit sur l'emplacement exact de votre boutique, puis cliquez sur les coordonnées affichées pour les copier ici.</p>

        <div class="form-field">
            <label class="filter-toggle">
                <input type="checkbox" name="fast_delivery" value="1" <?= !empty($vendorShop['fast_delivery']) ? 'checked' : '' ?>>
                <span>Livraison rapide</span>
            </label>
        </div>
        <div class="form-field">
            <label class="filter-toggle">
                <input type="checkbox" name="is_open" value="1" <?= !empty($vendorShop['is_open']) ? 'checked' : '' ?>>
                <span>Boutique ouverte</span>
            </label>
        </div>

        <button type="submit" class="btn btn-primary">Enregistrer</button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
