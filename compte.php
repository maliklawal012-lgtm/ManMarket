<?php
declare(strict_types=1);

$pageTitle = 'Mon compte';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/wallet_bootstrap.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

const AVATAR_UPLOAD_DIR = __DIR__ . '/assets/uploads/avatars/';
const AVATAR_UPLOAD_WEB_PATH = 'assets/uploads/avatars/';

$profileErrors = [];
$passwordErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'profile') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $removeAvatar = isset($_POST['remove_avatar']);

    if ($name === '') {
        $profileErrors['name'] = 'Veuillez indiquer votre nom.';
    }
    if ($phone === '' || !validate_phone_number($phone)) {
        $profileErrors['phone'] = 'Veuillez indiquer un numéro de téléphone ivoirien valide (10 chiffres, ex : 07 00 00 00 00).';
    }

    $hasUpload = isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE;
    $newAvatarExt = null;
    if ($hasUpload) {
        $validation = validate_uploaded_image($_FILES['avatar']);
        if ($validation['error']) {
            $profileErrors['avatar'] = $validation['error'];
        } else {
            $newAvatarExt = $validation['ext'];
        }
    }

    if (!$profileErrors) {
        $currentAvatar = $user['avatar'] ?? null;
        if ($newAvatarExt) {
            $finalAvatar = store_uploaded_image($_FILES['avatar'], $newAvatarExt, AVATAR_UPLOAD_DIR, AVATAR_UPLOAD_WEB_PATH);
            delete_uploaded_image($currentAvatar);
        } elseif ($removeAvatar) {
            delete_uploaded_image($currentAvatar);
            $finalAvatar = null;
        } else {
            $finalAvatar = $currentAvatar;
        }

        $stmt = get_db()->prepare('UPDATE users SET name = :name, phone = :phone, avatar = :avatar WHERE id = :id');
        $stmt->execute([
            'name' => $name,
            'phone' => $phone,
            'avatar' => $finalAvatar,
            'id' => $user['id'],
        ]);
        header('Location: /market/compte?profile=ok');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'password') {
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

    $stmt = get_db()->prepare('SELECT password_hash FROM users WHERE id = :id');
    $stmt->execute(['id' => $user['id']]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
        $passwordErrors['current_password'] = 'Mot de passe actuel incorrect.';
    }
    if (mb_strlen($newPassword) < 8) {
        $passwordErrors['new_password'] = 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
    } elseif ($newPassword !== $newPasswordConfirm) {
        $passwordErrors['new_password_confirm'] = 'Les mots de passe ne correspondent pas.';
    }

    if (!$passwordErrors) {
        $stmt = get_db()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute([
            'hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => $user['id'],
        ]);
        security_log('Mot de passe change (auto-service)', ['user_id' => $user['id'], 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
        header('Location: /market/compte?password=ok');
        exit;
    }
}

$verificationError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'resend_verification' && !$user['email_verified_at']) {
    if (!rate_limit_check('resend_verification:' . $user['id'], 3, 3600)) {
        $verificationError = 'Trop de demandes. Veuillez réessayer dans quelques instants.';
    } else {
        $verifyToken = issue_email_verification_token((int) $user['id']);
        wallet_notification_service()->emailVerificationRequested((int) $user['id'], site_base_url() . '/verifier-email.php?token=' . $verifyToken);
        header('Location: /market/compte?verification_sent=1');
        exit;
    }
}

$profileSuccess = ($_GET['profile'] ?? '') === 'ok';
$passwordSuccess = ($_GET['password'] ?? '') === 'ok';
$verificationSent = ($_GET['verification_sent'] ?? '') === '1';

require_once __DIR__ . '/includes/header.php';
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <h1>Mon compte<?php if ($user['is_vendor']): ?> <span class="tag tag-green">Vendeur</span><?php endif; ?></h1>
        <p>Bienvenue, <?= e($user['name']) ?>.</p>
    </div>
</section>

<section class="container auth-page">
    <div class="auth-page-stack">

        <?php if (!$user['email_verified_at']): ?>
            <div class="alert alert-warning">
                <?= icon('headset', 18) ?>
                <span>
                    Votre adresse email n'est pas encore vérifiée.
                    <?php if ($verificationSent): ?>
                        Un nouveau lien de vérification vient de vous être envoyé.
                    <?php else: ?>
                        <form method="post" action="/market/compte" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="resend_verification">
                            <button type="submit" class="link-more" style="display:inline; background:none; border:none; padding:0; text-decoration:underline; cursor:pointer; color:inherit; font:inherit;">Renvoyer l'email de vérification</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($verificationError): ?><br><?= e($verificationError) ?><?php endif; ?>
                </span>
            </div>
        <?php endif; ?>

        <div class="card auth-card">
            <div class="card-header">
                <h2>Mes informations</h2>
            </div>

            <ul class="account-info-list">
                <li><span class="account-info-label"><?= icon('user', 16) ?> Nom</span><span><?= e($user['name']) ?></span></li>
                <li><span class="account-info-label"><?= icon('send', 16) ?> Email</span><span><?= e($user['email']) ?></span></li>
                <li><span class="account-info-label"><?= icon('phone', 16) ?> Téléphone</span><span><?= e($user['phone'] ?? 'Non renseigné') ?></span></li>
                <li><span class="account-info-label"><?= icon('clock', 16) ?> Membre depuis</span><span><?= e(date('d/m/Y', strtotime((string) $user['created_at']))) ?></span></li>
            </ul>

            <div class="account-actions">
                <?php if ($user['is_vendor']): ?>
                    <a href="/market/vendeur/index" class="btn btn-outline-primary"><?= icon('store', 16) ?> Mon espace vendeur</a>
                <?php else: ?>
                    <a href="/market/vendeur/demande-boutique" class="btn btn-outline-primary"><?= icon('store', 16) ?> Devenir vendeur</a>
                <?php endif; ?>
                <a href="/market/commandes" class="btn btn-outline-primary"><?= icon('clock', 16) ?> Mes commandes</a>
                <a href="/market/favoris" class="btn btn-outline-primary"><?= icon('heart', 16) ?> Mes favoris</a>
                <a href="/market/panier" class="btn btn-outline-primary"><?= icon('cart', 16) ?> Mon panier</a>
                <a href="/market/deconnexion" class="btn btn-primary">Se déconnecter</a>
            </div>
        </div>

        <div class="card auth-card">
            <div class="card-header">
                <h2>Modifier mes informations</h2>
            </div>

            <?php if ($profileSuccess): ?>
                <div class="alert alert-success"><?= icon('check-circle', 18) ?><span>Vos informations ont été mises à jour.</span></div>
            <?php endif; ?>

            <form method="post" action="/market/compte" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="profile">

                <div class="form-field <?= isset($profileErrors['avatar']) ? 'has-error' : '' ?>">
                    <label for="avatar">Photo de profil</label>
                    <?php if (!empty($user['avatar'])): ?>
                        <div class="admin-image-preview">
                            <img src="/market/<?= e($user['avatar']) ?>" alt="" style="border-radius:50%;">
                            <label class="filter-toggle">
                                <input type="checkbox" name="remove_avatar" value="1">
                                <span>Supprimer la photo actuelle</span>
                            </label>
                        </div>
                    <?php endif; ?>
                    <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif">
                    <span class="char-count">JPG, PNG, WEBP ou GIF — 3 Mo max.</span>
                    <?php if (isset($profileErrors['avatar'])): ?><span class="field-error"><?= e($profileErrors['avatar']) ?></span><?php endif; ?>
                </div>

                <div class="form-field <?= isset($profileErrors['name']) ? 'has-error' : '' ?>">
                    <label for="name">Nom complet *</label>
                    <input type="text" id="name" name="name" value="<?= e($_POST['name'] ?? $user['name']) ?>" required>
                    <?php if (isset($profileErrors['name'])): ?><span class="field-error"><?= e($profileErrors['name']) ?></span><?php endif; ?>
                </div>

                <div class="form-field <?= isset($profileErrors['phone']) ? 'has-error' : '' ?>">
                    <label for="phone">Téléphone *</label>
                    <input type="tel" id="phone" name="phone" value="<?= e($_POST['phone'] ?? ($user['phone'] ?? '')) ?>" placeholder="07 00 00 00 00" required>
                    <?php if (isset($profileErrors['phone'])): ?><span class="field-error"><?= e($profileErrors['phone']) ?></span><?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Enregistrer</button>
            </form>
        </div>

        <div class="card auth-card">
            <div class="card-header">
                <h2>Changer mon mot de passe</h2>
            </div>

            <?php if ($passwordSuccess): ?>
                <div class="alert alert-success"><?= icon('check-circle', 18) ?><span>Votre mot de passe a été modifié.</span></div>
            <?php endif; ?>

            <form method="post" action="/market/compte" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="password">

                <div class="form-field <?= isset($passwordErrors['current_password']) ? 'has-error' : '' ?>">
                    <label for="current_password">Mot de passe actuel *</label>
                    <input type="password" id="current_password" name="current_password" required>
                    <?php if (isset($passwordErrors['current_password'])): ?><span class="field-error"><?= e($passwordErrors['current_password']) ?></span><?php endif; ?>
                </div>

                <div class="form-row">
                    <div class="form-field <?= isset($passwordErrors['new_password']) ? 'has-error' : '' ?>">
                        <label for="new_password">Nouveau mot de passe *</label>
                        <input type="password" id="new_password" name="new_password" required>
                        <?php if (isset($passwordErrors['new_password'])): ?><span class="field-error"><?= e($passwordErrors['new_password']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-field <?= isset($passwordErrors['new_password_confirm']) ? 'has-error' : '' ?>">
                        <label for="new_password_confirm">Confirmer *</label>
                        <input type="password" id="new_password_confirm" name="new_password_confirm" required>
                        <?php if (isset($passwordErrors['new_password_confirm'])): ?><span class="field-error"><?= e($passwordErrors['new_password_confirm']) ?></span><?php endif; ?>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Changer le mot de passe</button>
            </form>
        </div>

    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
