<?php
declare(strict_types=1);

$pageTitle = 'Contact';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rate_limit.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$loggedInUser = current_user();
$errors = [];
$success = false;
$old = [
    'name' => $loggedInUser['name'] ?? '',
    'email' => $loggedInUser['email'] ?? '',
    'phone' => $loggedInUser['phone'] ?? '',
    'subject' => trim((string) ($_GET['subject'] ?? '')),
    'message' => trim((string) ($_GET['message'] ?? '')),
    'shop_id' => (int) ($_GET['shop_id'] ?? 0),
];

$targetShop = null;
if ($old['shop_id'] > 0) {
    $stmt = get_db()->prepare('SELECT id, name FROM shops WHERE id = :id');
    $stmt->execute(['id' => $old['shop_id']]);
    $targetShop = $stmt->fetch() ?: null;
    if (!$targetShop) {
        $old['shop_id'] = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['name'] = trim((string) ($_POST['name'] ?? ''));
    $old['email'] = trim((string) ($_POST['email'] ?? ''));
    $old['phone'] = trim((string) ($_POST['phone'] ?? ''));
    $old['subject'] = trim((string) ($_POST['subject'] ?? ''));
    $old['message'] = trim((string) ($_POST['message'] ?? ''));
    $old['shop_id'] = (int) ($_POST['shop_id'] ?? 0);

    if ($old['shop_id'] > 0) {
        $stmt = get_db()->prepare('SELECT id, name FROM shops WHERE id = :id');
        $stmt->execute(['id' => $old['shop_id']]);
        $targetShop = $stmt->fetch() ?: null;
        if (!$targetShop) {
            $old['shop_id'] = 0;
        }
    }

    if (!rate_limit_check('contact:' . rate_limit_client_ip(), 5, 3600)) {
        $errors['message'] = 'Trop de messages envoyés. Veuillez réessayer dans quelques instants.';
    } else {
        if ($old['name'] === '') {
            $errors['name'] = 'Veuillez indiquer votre nom.';
        }
        if ($old['email'] === '' || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Veuillez indiquer une adresse email valide.';
        }
        if ($old['subject'] === '') {
            $errors['subject'] = 'Veuillez indiquer un sujet.';
        }
        if ($old['message'] === '' || mb_strlen($old['message']) < 10) {
            $errors['message'] = 'Votre message doit contenir au moins 10 caractères.';
        }
    }

    if (!$errors) {
        $db = get_db();
        $stmt = $db->prepare('INSERT INTO contact_messages (name, email, phone, user_id, shop_id, subject, message) VALUES (:name, :email, :phone, :user_id, :shop_id, :subject, :message)');
        $stmt->execute([
            'name' => $old['name'],
            'email' => $old['email'],
            'phone' => $old['phone'] !== '' ? $old['phone'] : null,
            'user_id' => $loggedInUser['id'] ?? null,
            'shop_id' => $old['shop_id'] > 0 ? $old['shop_id'] : null,
            'subject' => $old['subject'],
            'message' => $old['message'],
        ]);

        $success = true;
        $targetShop = null;
        $old = ['name' => $loggedInUser['name'] ?? '', 'email' => $loggedInUser['email'] ?? '', 'phone' => $loggedInUser['phone'] ?? '', 'subject' => '', 'message' => '', 'shop_id' => 0];
    }
}

require_once __DIR__ . '/includes/header.php';

$contactInfo = [
    ['icon' => 'map-pin', 'title' => 'Adresse', 'text' => get_setting('site_address')],
    ['icon' => 'phone', 'title' => 'Téléphone', 'text' => get_setting('site_phone')],
    ['icon' => 'send', 'title' => 'Email', 'text' => get_setting('site_email')],
    ['icon' => 'headset', 'title' => 'Support', 'text' => get_setting('site_support_hours')],
];
?>

<section class="page-banner">
    <div class="container page-banner-inner">
        <h1>Contactez-nous</h1>
        <p>Une question, une suggestion ? Notre équipe vous répond rapidement.</p>
    </div>
</section>

<section class="container contact-page">

    <div class="contact-info-grid">
        <?php foreach ($contactInfo as $info): ?>
            <div class="card contact-info-card">
                <span class="service-icon"><?= icon($info['icon'], 20) ?></span>
                <div>
                    <strong><?= e($info['title']) ?></strong>
                    <span><?= e($info['text']) ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card contact-form-card">
        <div class="card-header">
            <h2>Envoyez-nous un message</h2>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?= icon('check-circle', 18) ?>
                <span>Votre message a bien été envoyé. Nous vous répondrons très vite.</span>
            </div>
        <?php endif; ?>

        <?php if ($targetShop): ?>
            <div class="alert alert-info">
                <?= icon('store', 18) ?>
                <span>Ce message sera adressé à la boutique <strong><?= e($targetShop['name']) ?></strong>.</span>
            </div>
        <?php endif; ?>

        <form method="post" action="/market/contact#contact-form" id="contact-form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="shop_id" value="<?= (int) $old['shop_id'] ?>">
            <div class="form-row">
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
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label for="phone">Téléphone (optionnel)</label>
                    <input type="tel" id="phone" name="phone" value="<?= e($old['phone']) ?>">
                </div>
                <div class="form-field <?= isset($errors['subject']) ? 'has-error' : '' ?>">
                    <label for="subject">Sujet *</label>
                    <select id="subject" name="subject" required>
                        <option value="">Choisir un sujet...</option>
                        <?php foreach (['Question générale', 'Réclamation', 'Suggestion', 'Devenir vendeur', 'Autre'] as $opt): ?>
                            <option value="<?= e($opt) ?>" <?= $old['subject'] === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['subject'])): ?><span class="field-error"><?= e($errors['subject']) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="form-field <?= isset($errors['message']) ? 'has-error' : '' ?>">
                <label for="message">Message *</label>
                <textarea id="message" name="message" rows="5" required><?= e($old['message']) ?></textarea>
                <div class="field-footer">
                    <?php if (isset($errors['message'])): ?><span class="field-error"><?= e($errors['message']) ?></span><?php else: ?><span></span><?php endif; ?>
                    <span class="char-count" id="message-count">0 caractère(s)</span>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Envoyer le message <?= icon('send', 16) ?></button>
        </form>
    </div>

</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
