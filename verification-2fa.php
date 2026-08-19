<?php
declare(strict_types=1);

$pageTitle = 'Vérification en deux étapes';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/wallet_bootstrap.php';

if (current_user()) {
    header('Location: /market/compte');
    exit;
}

$pendingUserId = (int) ($_SESSION['pending_2fa_user_id'] ?? 0);
if ($pendingUserId <= 0) {
    header('Location: /market/connexion');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$redirect = safe_redirect_target((string) ($_SESSION['pending_2fa_redirect'] ?? ''), '/market/compte');
$errors = [];
$resent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
    if (!rate_limit_check('login_2fa_resend:' . rate_limit_client_ip(), 5, 3600)) {
        $errors['code'] = 'Trop de renvois. Veuillez réessayer plus tard.';
    } else {
        $code = issue_login_2fa_code($pendingUserId);
        wallet_notification_service()->twoFactorCode($pendingUserId, $code);
        $resent = true;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCode = trim((string) ($_POST['code'] ?? ''));

    if (!rate_limit_check('login_2fa_verify:' . rate_limit_client_ip(), 10, 900)) {
        $errors['code'] = 'Trop de tentatives. Veuillez réessayer dans quelques minutes.';
    } elseif ($submittedCode === '') {
        $errors['code'] = 'Veuillez indiquer le code reçu par email.';
    } else {
        $db = get_db();
        $stmt = $db->prepare('SELECT * FROM login_2fa_codes WHERE user_id = :id AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1');
        $stmt->execute(['id' => $pendingUserId]);
        $row = $stmt->fetch();

        if ($row && hash_equals($row['code_hash'], hash('sha256', $submittedCode))) {
            $db->prepare('DELETE FROM login_2fa_codes WHERE user_id = :id')->execute(['id' => $pendingUserId]);
            unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_redirect']);
            login_user($pendingUserId);
            header('Location: ' . $redirect);
            exit;
        }

        if (!$row) {
            $errors['code'] = 'Ce code a expiré. Demandez-en un nouveau.';
        } else {
            $attempts = (int) $row['attempts'] + 1;
            if ($attempts >= 5) {
                $db->prepare('DELETE FROM login_2fa_codes WHERE id = :id')->execute(['id' => $row['id']]);
                $errors['code'] = 'Trop de tentatives incorrectes. Demandez un nouveau code.';
            } else {
                $db->prepare('UPDATE login_2fa_codes SET attempts = :attempts WHERE id = :id')->execute(['attempts' => $attempts, 'id' => $row['id']]);
                $errors['code'] = 'Code incorrect.';
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <h1>Vérification en deux étapes</h1>
        <p>Un code de connexion vous a été envoyé par email.</p>
    </div>
</section>

<section class="container auth-page">
    <div class="card auth-card">
        <div class="card-header">
            <h2>Saisir le code reçu</h2>
        </div>

        <?php if (isset($errors['code'])): ?>
            <div class="alert alert-error">
                <?= icon('x', 18) ?>
                <span><?= e($errors['code']) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($resent): ?>
            <div class="alert alert-success">
                <?= icon('check-circle', 18) ?>
                <span>Un nouveau code vient de vous être envoyé par email.</span>
            </div>
        <?php endif; ?>

        <form method="post" action="/market/verification-2fa" novalidate>
            <?= csrf_field() ?>

            <div class="form-field">
                <label for="code">Code de vérification *</label>
                <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" required autofocus>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Vérifier</button>
        </form>

        <form method="post" action="/market/verification-2fa" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="resend">
            <button type="submit" class="btn btn-outline-primary btn-block">Renvoyer le code</button>
        </form>

        <p class="auth-switch"><a href="/market/connexion">Retour à la connexion</a></p>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
