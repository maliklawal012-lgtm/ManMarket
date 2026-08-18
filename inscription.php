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
    $consent = isset($_POST['consent_data_processing']);

    if (!rate_limit_check('register:' . rate_limit_client_ip(), 5, 3600)) {
        $errors['name'] = 'Trop de tentatives d\'inscription depuis cette connexion. Veuillez réessayer plus tard.';
    }
    if ($old['name'] === '' && !isset($errors['name'])) {
        $errors['name'] = 'Veuillez indiquer votre nom.';
    }
    if ($old['email'] === '' || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Veuillez indiquer une adresse email valide.';
    }
    if ($old['phone'] === '' || !validate_phone_number($old['phone'])) {
        $errors['phone'] = 'Veuillez indiquer un numéro de téléphone ivoirien valide (10 chiffres, ex : 07 00 00 00 00).';
    }
    if (mb_strlen($password) < 8) {
        $errors['password'] = 'Le mot de passe doit contenir au moins 8 caractères.';
    } elseif ($password !== $passwordConfirm) {
        $errors['password_confirm'] = 'Les mots de passe ne correspondent pas.';
    }
    if (!$consent) {
        $errors['consent'] = 'Vous devez accepter le traitement de vos données personnelles pour créer votre compte.';
    }

    if (!$errors) {
        $stmt = get_db()->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => $old['email']]);
        if ($stmt->fetch()) {
            $errors['email'] = 'Impossible de créer un compte avec ces informations. Si vous avez déjà un compte, connectez-vous.';
        }
    }

    if (!$errors) {
        try {
            $stmt = get_db()->prepare('
                INSERT INTO users (name, email, phone, password_hash, is_vendor, consent_data_processing_at, consent_privacy_policy_version)
                VALUES (:name, :email, :phone, :password_hash, :is_vendor, NOW(), :policy_version)
            ');
            $stmt->execute([
                'name' => $old['name'],
                'email' => $old['email'],
                'phone' => $old['phone'],
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'is_vendor' => $isVendor ? 1 : 0,
                'policy_version' => PRIVACY_POLICY_VERSION,
            ]);

            $newUserId = (int) get_db()->lastInsertId();
            $verifyToken = issue_email_verification_token($newUserId);
            wallet_notification_service()->emailVerificationRequested($newUserId, site_base_url() . '/verifier-email.php?token=' . $verifyToken);

            login_user($newUserId);
            header('Location: /market/compte.php');
            exit;
        } catch (PDOException $e) {
            // Deux inscriptions simultanees avec le meme email : la verification
            // SELECT ci-dessus ne peut pas empecher cette course, seule la
            // contrainte UNIQUE en base le peut (code SQLSTATE 23000).
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            $errors['email'] = 'Impossible de créer un compte avec ces informations. Si vous avez déjà un compte, connectez-vous.';
        }
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

            <div class="form-field <?= isset($errors['phone']) ? 'has-error' : '' ?>">
                <label for="phone">Téléphone *</label>
                <input type="tel" id="phone" name="phone" value="<?= e($old['phone']) ?>" placeholder="07 00 00 00 00" required>
                <?php if (isset($errors['phone'])): ?><span class="field-error"><?= e($errors['phone']) ?></span><?php endif; ?>
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

            <div class="form-field <?= isset($errors['consent']) ? 'has-error' : '' ?>">
                <label class="filter-toggle consent-toggle">
                    <input type="checkbox" id="consent_data_processing" name="consent_data_processing" value="1" required>
                    <span>J'accepte que mes données personnelles soient collectées et utilisées par <?= e(get_setting('site_name') ?: 'ManMarket') ?> pour créer mon compte, gérer mes commandes, assurer la livraison et fournir les services proposés. Je reconnais avoir pris connaissance de la <a href="/market/politique-confidentialite.php" target="_blank" rel="noopener">Politique de confidentialité</a>.</span>
                </label>
                <?php if (isset($errors['consent'])): ?><span class="field-error"><?= e($errors['consent']) ?></span><?php endif; ?>
            </div>

            <button type="submit" id="register-submit" class="btn btn-primary btn-block"><?= $isVendor ? 'Créer mon compte vendeur' : 'Créer mon compte' ?></button>
        </form>

        <script>
        (function () {
            var form = document.querySelector('form[action*="inscription.php"]');
            var consent = document.getElementById('consent_data_processing');
            if (!form || !consent) return;
            form.addEventListener('submit', function (event) {
                if (!consent.checked) {
                    event.preventDefault();
                    consent.closest('.form-field').classList.add('has-error');
                    consent.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        })();
        </script>

        <p class="auth-switch">Déjà inscrit ? <a href="/market/connexion.php">Se connecter</a></p>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
