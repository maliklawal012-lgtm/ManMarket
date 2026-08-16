<?php
declare(strict_types=1);

$pageTitle = 'Inscription';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/wallet_bootstrap.php';

if (current_user()) {
    header('Location: /market/compte.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$isVendor = ($_GET['type'] ?? $_POST['type'] ?? '') === 'vendeur';
$errors = [];
$old = ['name' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['name'] = trim((string) ($_POST['name'] ?? ''));
    $old['email'] = trim((string) ($_POST['email'] ?? ''));
    $old['phone'] = trim((string) ($_POST['phone'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if (!rate_limit_check('register:' . rate_limit_client_ip(), 5, 3600)) {
        $errors['name'] = 'Trop de tentatives d\'inscription depuis cette connexion. Veuillez réessayer plus tard.';
    }
    if ($old['name'] === '' && !isset($errors['name'])) {
        $errors['name'] = 'Veuillez indiquer votre nom.';
    }
    if ($old['email'] === '' || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Veuillez indiquer une adresse email valide.';
    }
    if (mb_strlen($password) < 6) {
        $errors['password'] = 'Le mot de passe doit contenir au moins 6 caractères.';
    } elseif ($password !== $passwordConfirm) {
        $errors['password_confirm'] = 'Les mots de passe ne correspondent pas.';
    }

    if (!$errors) {
        $stmt = get_db()->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => $old['email']]);
        if ($stmt->fetch()) {
            $errors['email'] = 'Cette adresse email est déjà utilisée.';
        }
    }

    if (!$errors) {
        $stmt = get_db()->prepare('INSERT INTO users (name, email, phone, password_hash, is_vendor) VALUES (:name, :email, :phone, :password_hash, :is_vendor)');
        $stmt->execute([
            'name' => $old['name'],
            'email' => $old['email'],
            'phone' => $old['phone'] !== '' ? $old['phone'] : null,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'is_vendor' => $isVendor ? 1 : 0,
        ]);

        $newUserId = (int) get_db()->lastInsertId();
        $verifyToken = issue_email_verification_token($newUserId);
        wallet_notification_service()->emailVerificationRequested($newUserId, site_base_url() . '/verifier-email.php?token=' . $verifyToken);

        login_user($newUserId);
        header('Location: /market/compte.php');
        exit;
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <?php if ($isVendor): ?>
            <h1>Devenez vendeur sur ManMarket</h1>
            <p>Créez votre compte pour ouvrir votre boutique et vendre à des milliers de clients à Man.</p>
        <?php else: ?>
            <h1>Créer un compte</h1>
            <p>Rejoignez ManMarket pour suivre vos commandes et vos favoris.</p>
        <?php endif; ?>
    </div>
</section>

<section class="container auth-page">
    <div class="card auth-card">
        <div class="card-header">
            <h2><?= $isVendor ? 'Inscription vendeur' : "S'inscrire" ?></h2>
        </div>

        <?php if ($isVendor): ?>
            <p class="vendor-note"><?= icon('store', 16) ?> Après votre inscription, notre équipe vous contactera pour finaliser l'ouverture de votre boutique.</p>
        <?php endif; ?>

        <form method="post" action="/market/inscription.php" novalidate>
            <?= csrf_field() ?>
            <?php if ($isVendor): ?><input type="hidden" name="type" value="vendeur"><?php endif; ?>
            <div class="form-field <?= isset($errors['name']) ? 'has-error' : '' ?>">
                <label for="name">Nom complet *</label>
                <input type="text" id="name" name="name" value="<?= e($old['name']) ?>" required>
                <?php if (isset($errors['name'])): ?><span class="field-error"><?= e($errors['name']) ?></span><?php endif; ?>
            </div>

            <div class="form-field <?= isset($errors['email']) ? 'has-error' : '' ?>">
                <label for="email">Email *</label>
                <input type="email" id="email" name="email" value="<?= e($old['email']) ?>" required>
                <?php if (isset($errors['email'])): ?><span class="field-error"><?= e($errors['email']) ?></span><?php endif; ?>
            </div>

            <div class="form-field">
                <label for="phone">Téléphone (optionnel)</label>
                <input type="tel" id="phone" name="phone" value="<?= e($old['phone']) ?>">
            </div>

            <div class="form-row">
                <div class="form-field <?= isset($errors['password']) ? 'has-error' : '' ?>">
                    <label for="password">Mot de passe *</label>
                    <input type="password" id="password" name="password" required>
                    <?php if (isset($errors['password'])): ?><span class="field-error"><?= e($errors['password']) ?></span><?php endif; ?>
                </div>
                <div class="form-field <?= isset($errors['password_confirm']) ? 'has-error' : '' ?>">
                    <label for="password_confirm">Confirmer le mot de passe *</label>
                    <input type="password" id="password_confirm" name="password_confirm" required>
                    <?php if (isset($errors['password_confirm'])): ?><span class="field-error"><?= e($errors['password_confirm']) ?></span><?php endif; ?>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block"><?= $isVendor ? 'Créer mon compte vendeur' : 'Créer mon compte' ?></button>
        </form>

        <p class="auth-switch">Déjà inscrit ? <a href="/market/connexion.php">Se connecter</a></p>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
