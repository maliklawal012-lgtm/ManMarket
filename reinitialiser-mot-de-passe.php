<?php
declare(strict_types=1);

$pageTitle = 'Nouveau mot de passe';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

function reset_find_valid_token(string $token): ?array
{
    if ($token === '') {
        return null;
    }

    $stmt = get_db()->prepare('
        SELECT * FROM password_resets
        WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW()
    ');
    $stmt->execute(['hash' => hash('sha256', $token)]);

    return $stmt->fetch() ?: null;
}

$token = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
$reset = reset_find_valid_token($token);
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reset) {
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

    if (mb_strlen($newPassword) < 8) {
        $errors['new_password'] = 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
    } elseif ($newPassword !== $newPasswordConfirm) {
        $errors['new_password_confirm'] = 'Les mots de passe ne correspondent pas.';
    }

    if (!$errors) {
        $db = get_db();
        $db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')->execute([
            'hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => $reset['user_id'],
        ]);
        $db->prepare('DELETE FROM password_resets WHERE user_id = :id')->execute(['id' => $reset['user_id']]);

        $stmt = $db->prepare('SELECT is_admin FROM users WHERE id = :id');
        $stmt->execute(['id' => $reset['user_id']]);
        $isAdmin = (int) $stmt->fetchColumn() === 1;

        header('Location: ' . ($isAdmin ? '/market/admin/connexion.php?reset=ok' : '/market/connexion.php?reset=ok'));
        exit;
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <h1>Nouveau mot de passe</h1>
        <p>Choisissez un nouveau mot de passe pour votre compte ManMarket.</p>
    </div>
</section>

<section class="container auth-page">
    <div class="card auth-card">
        <div class="card-header">
            <h2>Réinitialiser mon mot de passe</h2>
        </div>

        <?php if (!$reset): ?>
            <div class="alert alert-error">
                <?= icon('x', 18) ?>
                <span>Ce lien de réinitialisation est invalide ou a expiré.</span>
            </div>
            <p class="auth-switch"><a href="/market/mot-de-passe-oublie.php">Demander un nouveau lien</a></p>
        <?php else: ?>
            <form method="post" action="/market/reinitialiser-mot-de-passe.php" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">

                <div class="form-field <?= isset($errors['new_password']) ? 'has-error' : '' ?>">
                    <label for="new_password">Nouveau mot de passe *</label>
                    <input type="password" id="new_password" name="new_password" required>
                    <?php if (isset($errors['new_password'])): ?><span class="field-error"><?= e($errors['new_password']) ?></span><?php endif; ?>
                </div>

                <div class="form-field <?= isset($errors['new_password_confirm']) ? 'has-error' : '' ?>">
                    <label for="new_password_confirm">Confirmer le mot de passe *</label>
                    <input type="password" id="new_password_confirm" name="new_password_confirm" required>
                    <?php if (isset($errors['new_password_confirm'])): ?><span class="field-error"><?= e($errors['new_password_confirm']) ?></span><?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Changer le mot de passe</button>
            </form>
        <?php endif; ?>

        <p class="auth-switch"><a href="/market/connexion.php">Retour à la connexion</a></p>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
