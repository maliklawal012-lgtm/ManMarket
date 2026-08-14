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
];

$imageFields = [
    'site_logo' => 'Logo du site (remplace le "M" dans l\'en-tête)',
    'hero_image' => 'Image de fond du grand bandeau d\'accueil',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        <?php foreach ($fields as $key => $meta): ?>
            <div class="form-field">
                <label for="<?= e($key) ?>"><?= e($meta['label']) ?></label>
                <input type="<?= e($meta['type']) ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e(get_setting($key)) ?>">
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

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
