<?php
declare(strict_types=1);

$pageTitle = 'Vérification de l\'email';
require_once __DIR__ . '/includes/auth.php';

function verification_find_valid_token(string $token): ?array
{
    if ($token === '') {
        return null;
    }

    $stmt = get_db()->prepare('
        SELECT * FROM email_verifications
        WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW()
    ');
    $stmt->execute(['hash' => hash('sha256', $token)]);

    return $stmt->fetch() ?: null;
}

$token = trim((string) ($_GET['token'] ?? ''));
$verification = verification_find_valid_token($token);

if ($verification) {
    $db = get_db();
    $db->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = :id')->execute(['id' => $verification['user_id']]);
    $db->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = :id')->execute(['id' => $verification['id']]);
}

require_once __DIR__ . '/includes/header.php';
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <h1>Vérification de l'email</h1>
    </div>
</section>

<section class="container auth-page">
    <div class="card auth-card">
        <?php if ($verification): ?>
            <div class="alert alert-success">
                <?= icon('check-circle', 18) ?>
                <span>Votre adresse email a bien été confirmée. Merci !</span>
            </div>
        <?php else: ?>
            <div class="alert alert-error">
                <?= icon('x', 18) ?>
                <span>Ce lien de vérification est invalide ou a expiré.</span>
            </div>
        <?php endif; ?>

        <p class="auth-switch"><a href="/market/compte">Retour à mon compte</a></p>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
