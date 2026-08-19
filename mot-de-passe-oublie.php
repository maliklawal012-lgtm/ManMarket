<?php
declare(strict_types=1);

$pageTitle = 'Mot de passe oublié';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/wallet_bootstrap.php';

if (current_user()) {
    header('Location: /market/compte');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$errors = [];
$old = ['email' => ''];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['email'] = trim((string) ($_POST['email'] ?? ''));

    if (!rate_limit_check('password_reset:' . rate_limit_client_ip(), 5, 3600)) {
        $errors['email'] = 'Trop de tentatives. Veuillez réessayer dans quelques minutes.';
    } elseif ($old['email'] === '' || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Veuillez indiquer une adresse email valide.';
    }

    if (!$errors) {
        $db = get_db();
        $stmt = $db->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => $old['email']]);
        $user = $stmt->fetch();

        if ($user) {
            $userId = (int) $user['id'];
            $db->prepare('DELETE FROM password_resets WHERE user_id = :id')->execute(['id' => $userId]);

            $token = bin2hex(random_bytes(32));
            $stmt = $db->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR))');
            $stmt->execute(['user_id' => $userId, 'token_hash' => hash('sha256', $token)]);

            $resetUrl = site_base_url() . '/reinitialiser-mot-de-passe.php?token=' . $token;
            wallet_notification_service()->passwordResetRequested($userId, $resetUrl);
        }

        $success = true;
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <h1>Mot de passe oublié</h1>
        <p>Recevez un lien pour choisir un nouveau mot de passe.</p>
    </div>
</section>

<section class="container auth-page">
    <div class="card auth-card">
        <div class="card-header">
            <h2>Réinitialiser mon mot de passe</h2>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?= icon('check-circle', 18) ?>
                <span>Si un compte existe avec cette adresse, un lien de réinitialisation vient de lui être envoyé. Vérifiez votre boîte de réception.</span>
            </div>
        <?php else: ?>
            <form method="post" action="/market/mot-de-passe-oublie" novalidate>
                <?= csrf_field() ?>

                <div class="form-field <?= isset($errors['email']) ? 'has-error' : '' ?>">
                    <label for="email">Email *</label>
                    <input type="email" id="email" name="email" value="<?= e($old['email']) ?>" required>
                    <?php if (isset($errors['email'])): ?><span class="field-error"><?= e($errors['email']) ?></span><?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Envoyer le lien de réinitialisation</button>
            </form>
        <?php endif; ?>

        <p class="auth-switch"><a href="/market/connexion">Retour à la connexion</a></p>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
