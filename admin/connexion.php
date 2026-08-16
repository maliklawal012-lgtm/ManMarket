<?php
declare(strict_types=1);

$pageTitle = 'Connexion administrateur';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/wallet_bootstrap.php';

$currentUser = current_user();
if ($currentUser) {
    header('Location: ' . ((int) $currentUser['is_admin'] === 1 ? '/market/admin/index.php' : '/market/compte.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$redirect = safe_redirect_target((string) ($_GET['redirect'] ?? $_POST['redirect'] ?? ''), '/market/admin/index.php');
$errors = [];
$old = ['email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['email'] = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!rate_limit_check('admin_login:' . rate_limit_client_ip(), 10, 900)) {
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

        if (!$user || !password_verify($password, $user['password_hash']) || (int) $user['is_admin'] !== 1) {
            $errors['login'] = 'Email ou mot de passe incorrect.';
        } elseif ((int) $user['is_blocked'] === 1) {
            $errors['login'] = 'Votre compte a été bloqué.' . ($user['blocked_reason'] ? ' Motif : ' . $user['blocked_reason'] . '.' : '') . ' Contactez l\'équipe ManMarket pour plus d\'informations.';
        } else {
            $code = issue_login_2fa_code((int) $user['id']);
            wallet_notification_service()->twoFactorCode((int) $user['id'], $code);

            session_regenerate_id(true);
            $_SESSION['pending_2fa_user_id'] = (int) $user['id'];
            $_SESSION['pending_2fa_redirect'] = $redirect;

            header('Location: /market/verification-2fa.php');
            exit;
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <h1>Connexion administrateur</h1>
        <p>Accès réservé à l'équipe ManMarket.</p>
    </div>
</section>

<section class="container auth-page">
    <div class="card auth-card">
        <div class="card-header">
            <h2>Se connecter (administrateur)</h2>
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

        <form method="post" action="/market/admin/connexion.php" novalidate>
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
                <a href="/market/mot-de-passe-oublie.php" class="char-count">Mot de passe oublié ?</a>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Se connecter</button>
        </form>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
