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
$success = false;
$errors = [];

const SITE_UPLOAD_DIR = __DIR__ . '/../assets/uploads/site/';
const SITE_UPLOAD_WEB_PATH = 'assets/uploads/site/';

$fields = [
    'site_name' => ['label' => 'Nom du site', 'type' => 'text'],
    'site_address' => ['label' => 'Adresse', 'type' => 'text'],
    'site_phone' => ['label' => 'Téléphone affiché', 'type' => 'text'],
    'site_whatsapp' => ['label' => 'Numéro WhatsApp (format international, sans le +)', 'type' => 'text'],
    'site_email' => ['label' => 'Email de contact', 'type' => 'email'],
    'site_support_hours' => ['label' => 'Horaires du support', 'type' => 'text'],
    'site_hero_title_main' => ['label' => 'Titre principal (grand bandeau d\'accueil)', 'type' => 'text', 'default' => 'Le plus grand marché en ligne de'],
    'site_hero_title_accent' => ['label' => 'Titre accentué en vert (grand bandeau d\'accueil)', 'type' => 'text', 'default' => 'la ville de Man'],
    'site_hero_subtitle' => ['label' => 'Sous-titre (grand bandeau d\'accueil)', 'type' => 'text', 'default' => 'Achetez local, soutenez nos commerçants, faites-vous livrer partout à Man.'],
    'site_footer_tagline' => ['label' => 'Description (pied de page)', 'type' => 'text', 'default' => 'Le plus grand marché en ligne de la ville de Man. Achetez local, soutenez nos commerçants, faites-vous livrer partout à Man.'],
];

$imageFields = [
    'site_logo' => 'Logo du site (remplace le "M" dans l\'en-tête)',
    'hero_image' => 'Image de fond du grand bandeau d\'accueil',
];

$socialNetworks = [
    'facebook' => 'Facebook',
    'instagram' => 'Instagram',
    'twitter' => 'X (Twitter)',
    'tiktok' => 'TikTok',
    'youtube' => 'YouTube',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'site_info') {
    foreach ($imageFields as $key => $label) {
        $hasUpload = isset($_FILES[$key]) && $_FILES[$key]['error'] !== UPLOAD_ERR_NO_FILE;
        if ($hasUpload) {
            $validation = validate_uploaded_image($_FILES[$key]);
            if ($validation['error']) {
                $errors[$key] = $validation['error'];
            }
        }
    }

    if (!$errors) {
        $stmt = $db->prepare('INSERT INTO settings (`key`, value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE value = :value2');
        foreach ($fields as $key => $meta) {
            $value = trim((string) ($_POST[$key] ?? ''));
            $stmt->execute(['key' => $key, 'value' => $value, 'value2' => $value]);
        }

        foreach ($imageFields as $key => $label) {
            $currentImage = get_setting($key);
            $hasUpload = isset($_FILES[$key]) && $_FILES[$key]['error'] !== UPLOAD_ERR_NO_FILE;
            $removeImage = isset($_POST['remove_' . $key]);

            if ($hasUpload) {
                $validation = validate_uploaded_image($_FILES[$key]);
                $newImage = store_uploaded_image($_FILES[$key], $validation['ext'], SITE_UPLOAD_DIR, SITE_UPLOAD_WEB_PATH);
                if ($currentImage) {
                    delete_uploaded_image($currentImage);
                }
                $stmt->execute(['key' => $key, 'value' => $newImage, 'value2' => $newImage]);
            } elseif ($removeImage && $currentImage) {
                delete_uploaded_image($currentImage);
                $stmt->execute(['key' => $key, 'value' => '', 'value2' => '']);
            }
        }

        header('Location: /market/admin/parametres.php?saved=1');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'social') {
    foreach ($socialNetworks as $key => $label) {
        $url = trim((string) ($_POST['social_' . $key . '_url'] ?? ''));
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            $errors['social_' . $key] = 'URL invalide.';
        }
    }

    if (!$errors) {
        $stmt = $db->prepare('INSERT INTO settings (`key`, value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE value = :value2');
        foreach ($socialNetworks as $key => $label) {
            $url = trim((string) ($_POST['social_' . $key . '_url'] ?? ''));
            $enabled = isset($_POST['social_' . $key . '_enabled']) ? '1' : '0';
            $stmt->execute(['key' => 'social_' . $key . '_url', 'value' => $url, 'value2' => $url]);
            $stmt->execute(['key' => 'social_' . $key . '_enabled', 'value' => $enabled, 'value2' => $enabled]);
        }

        header('Location: /market/admin/parametres.php?saved=1');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'payment_fee') {
    $rateRaw = trim((string) ($_POST['online_payment_fee_rate'] ?? ''));
    $rate = is_numeric($rateRaw) ? (float) $rateRaw : -1;

    if ($rate < 0 || $rate > 100) {
        $errors['online_payment_fee_rate'] = 'Veuillez indiquer un pourcentage entre 0 et 100.';
    }

    if (!$errors) {
        $value = number_format($rate, 2, '.', '');
        $db->prepare('INSERT INTO settings (`key`, value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE value = :value2')
            ->execute(['key' => 'online_payment_fee_rate', 'value' => $value, 'value2' => $value]);

        header('Location: /market/admin/parametres.php?saved=1');
        exit;
    }
}

$success = ($_GET['saved'] ?? '') === '1';

$pageTitle = 'Paramètres';
require_once __DIR__ . '/../includes/admin_header.php';
?>

<div class="card" style="max-width:640px;">
    <div class="admin-toolbar">
        <h2>Informations du site</h2>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= icon('check-circle', 18) ?><span>Paramètres enregistrés.</span></div>
    <?php endif; ?>

    <form method="post" action="/market/admin/parametres.php" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="site_info">
        <?php foreach ($fields as $key => $meta): ?>
            <div class="form-field">
                <label for="<?= e($key) ?>"><?= e($meta['label']) ?></label>
                <input type="<?= e($meta['type']) ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e(get_setting($key, $meta['default'] ?? '')) ?>">
            </div>
        <?php endforeach; ?>

        <?php foreach ($imageFields as $key => $label): ?>
            <div class="form-field <?= isset($errors[$key]) ? 'has-error' : '' ?>">
                <label for="<?= e($key) ?>"><?= e($label) ?></label>
                <?php $currentImage = get_setting($key); ?>
                <?php if ($currentImage): ?>
                    <div class="admin-image-preview">
                        <img src="/market/<?= e($currentImage) ?>" alt="">
                        <label class="filter-toggle">
                            <input type="checkbox" name="remove_<?= e($key) ?>" value="1">
                            <span>Supprimer l'image actuelle</span>
                        </label>
                    </div>
                <?php endif; ?>
                <input type="file" id="<?= e($key) ?>" name="<?= e($key) ?>" accept="image/jpeg,image/png,image/webp,image/gif">
                <span class="char-count">JPG, PNG, WEBP ou GIF — 3 Mo max.</span>
                <?php if (isset($errors[$key])): ?><span class="field-error"><?= e($errors[$key]) ?></span><?php endif; ?>
            </div>
        <?php endforeach; ?>

        <button type="submit" class="btn btn-primary">Enregistrer</button>
    </form>
</div>

<div class="card" style="max-width:640px;">
    <div class="admin-toolbar">
        <h2>Paiement en ligne</h2>
    </div>
    <p class="char-count">Ce pourcentage est ajouté au total du client (en plus du sous-total et de la livraison — jamais mélangé au prix du produit) uniquement s'il choisit de payer en ligne, pour couvrir les frais prélevés par Genius Pay.</p>

    <?php $paymentFeePosted = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'payment_fee'; ?>
    <form method="post" action="/market/admin/parametres.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="payment_fee">
        <div class="form-field <?= isset($errors['online_payment_fee_rate']) ? 'has-error' : '' ?>">
            <label for="online_payment_fee_rate">Taux des frais de paiement en ligne (%)</label>
            <input type="number" id="online_payment_fee_rate" name="online_payment_fee_rate" step="0.01" min="0" max="100" value="<?= e($paymentFeePosted ? (string) ($_POST['online_payment_fee_rate'] ?? '') : get_setting('online_payment_fee_rate', '0.00')) ?>">
            <?php if (isset($errors['online_payment_fee_rate'])): ?><span class="field-error"><?= e($errors['online_payment_fee_rate']) ?></span><?php endif; ?>
        </div>
        <button type="submit" class="btn btn-primary">Enregistrer</button>
    </form>
</div>

<div class="card" style="max-width:640px;">
    <div class="admin-toolbar">
        <h2>Réseaux sociaux</h2>
    </div>
    <p class="char-count">Un réseau ne s'affiche dans le pied de page que s'il est actif ET qu'une URL est renseignée.</p>

    <?php $wasPosted = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'social'; ?>
    <form method="post" action="/market/admin/parametres.php" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="social">
        <?php foreach ($socialNetworks as $key => $label): ?>
            <div class="form-field <?= isset($errors['social_' . $key]) ? 'has-error' : '' ?>">
                <label for="social_<?= e($key) ?>_url"><?= icon($key, 15) ?> <?= e($label) ?></label>
                <div class="form-row" style="align-items:center;">
                    <input type="url" id="social_<?= e($key) ?>_url" name="social_<?= e($key) ?>_url" value="<?= e($wasPosted ? (string) ($_POST['social_' . $key . '_url'] ?? '') : get_setting('social_' . $key . '_url')) ?>" placeholder="https://...">
                    <label class="filter-toggle" style="flex:0 0 auto;">
                        <input type="checkbox" name="social_<?= e($key) ?>_enabled" value="1" <?= ($wasPosted ? isset($_POST['social_' . $key . '_enabled']) : get_setting('social_' . $key . '_enabled') === '1') ? 'checked' : '' ?>>
                        <span>Actif</span>
                    </label>
                </div>
                <?php if (isset($errors['social_' . $key])): ?><span class="field-error"><?= e($errors['social_' . $key]) ?></span><?php endif; ?>
            </div>
        <?php endforeach; ?>

        <button type="submit" class="btn btn-primary">Enregistrer</button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
