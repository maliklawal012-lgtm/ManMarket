<?php
declare(strict_types=1);

$pageTitle = 'Connexion';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rate_limit.php';

if (current_user()) {
    header('Location: /market/compte');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$redirect = safe_redirect_target((string) ($_GET['redirect'] ?? $_POST['redirect'] ?? ''), '/market/compte');
$errors = [];
$old = ['email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['email'] = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!rate_limit_check('login:' . rate_limit_client_ip(), 10, 900)) {
        $errors['login'] = 'Trop de tentatives de connexion. Veuillez réessayer dans quelques minutes.';
    } elseif ($old['email'] === '' || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Veuillez indiquer une adresse email valide.';
    }
    if ($password === '' && !isset($errors['login'])) {
        $errors['password'] = 'Veuillez indiquer votre mot de passe.';
    }

    if (!$errors) {
        $stmt = get_db()->prepare('SELECT id, password_hash, is_admin, is_blocked, blocked_reason FROM users WHERE email = :email');
        $stmt->execute(['email' => $old['email']]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash']) || (int) $user['is_admin'] === 1) {
            $errors['login'] = 'Email ou mot de passe incorrect.';
        } elseif ((int) $user['is_blocked'] === 1) {
            $errors['login'] = 'Votre compte a été bloqué.' . ($user['blocked_reason'] ? ' Motif : ' . $user['blocked_reason'] . '.' : '') . ' Contactez l\'équipe ManMarket pour plus d\'informations.';
        } else {
            login_user((int) $user['id']);
            header('Location: ' . $redirect);
            exit;
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <h1>Connexion</h1>
        <p>Accédez à votre compte ManMarket.</p>
    </div>
</section>

<section class="container auth-page">
    <div class="card auth-card">
        <div class="card-header">
            <h2>Se connecter</h2>
        </div>

        <?php if (isset($errors['login'])): ?>
            <div class="alert alert-error">
                <?= icon('x', 18) ?>
                <span><?= e($errors['login']) ?></span>
            </div>
        <?php endif; ?>

        <?php if (($_GET['reset'] ?? '') === 'ok'): ?>
            <div class="alert alert-success">
                <?= icon('check-circle', 18) ?>
                <span>Votre mot de passe a été modifié. Connectez-vous avec votre nouveau mot de passe.</span>
            </div>
        <?php endif; ?>

        <form method="post" action="/market/connexion" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="redirect" value="<?= e($redirect) ?>">

            <div class="form-field <?= isset($errors['email']) ? 'has-error' : '' ?>">
                <label for="email">Email *</label>
                <input type="email" id="email" name="email" value="<?= e($old['email']) ?>" required>
                <?php if (isset($errors['email'])): ?><span class="field-error"><?= e($errors['email']) ?></span><?php endif; ?>
            </div>

            <div class="form-field <?= isset($errors['password']) ? 'has-error' : '' ?>">
                <label for="password">Mot de passe *</label>
                <input type="password" id="password" name="password" required>
                <?php if (isset($errors['password'])): ?><span class="field-error"><?= e($errors['password']) ?></span><?php endif; ?>
                <a href="/market/mot-de-passe-oublie" class="char-count">Mot de passe oublié ?</a>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Se connecter</button>
        </form>

        <p class="auth-switch">Pas encore de compte ? <a href="/market/inscription">Créer un compte</a></p>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
