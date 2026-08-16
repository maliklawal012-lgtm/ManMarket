<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    // Secure=true UNIQUEMENT si la requete est deja en HTTPS : force ce flag
    // sans condition casserait la connexion en local (WAMP sert en HTTP).
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_user(): ?array
{
    static $user = null;

    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    if ($user === null) {
        $stmt = get_db()->prepare('
            SELECT id, name, email, phone, avatar, is_vendor, is_admin, is_blocked, blocked_reason,
                payment_restricted, email_verified_at, last_login_at, last_activity_at, created_at
            FROM users WHERE id = :id
        ');
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $user = $stmt->fetch() ?: false;

        if ($user && (int) $user['is_blocked'] === 1) {
            logout_user();
            $user = false;
        } elseif ($user) {
            $db = get_db();
            $stmt = $db->prepare("
                UPDATE users SET last_activity_at = CURRENT_TIMESTAMP
                WHERE id = :id AND (last_activity_at IS NULL OR last_activity_at < NOW() - INTERVAL 60 SECOND)
            ");
            $stmt->execute(['id' => $user['id']]);
        }
    }

    return $user ?: null;
}

function require_login(): array
{
    $user = current_user();

    if (!$user) {
        header('Location: /market/connexion.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }

    return $user;
}

function require_admin(): array
{
    $user = current_user();

    if (!$user) {
        header('Location: /market/admin/connexion.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }

    if (!$user['is_admin']) {
        http_response_code(403);
        require __DIR__ . '/../admin/403.php';
        exit;
    }

    return $user;
}

function require_vendor(): array
{
    $user = require_login();

    if (!$user['is_vendor']) {
        http_response_code(403);
        require __DIR__ . '/../vendeur/403.php';
        exit;
    }

    // Suspension au niveau de l'entite `vendors` (architecture portefeuille) :
    // distincte du flag users.is_vendor (droit d'acces general). Un vendeur
    // suspendu par un admin (voir VendorAdminService::suspend) perd tout
    // acces a son espace, pas seulement aux retraits (deja bloques separement
    // dans WithdrawalService, mais la coupure doit etre totale).
    $stmt = get_db()->prepare('SELECT status, suspended_reason FROM vendors WHERE user_id = :id');
    $stmt->execute(['id' => $user['id']]);
    $vendorEntity = $stmt->fetch();

    if ($vendorEntity && $vendorEntity['status'] === 'suspended') {
        http_response_code(403);
        $suspendedReason = $vendorEntity['suspended_reason'];
        require __DIR__ . '/../vendeur/suspendu.php';
        exit;
    }

    return $user;
}

function current_vendor_shop(int $userId): ?array
{
    $stmt = get_db()->prepare("SELECT * FROM shops WHERE owner_id = :id AND approval_status = 'approved'");
    $stmt->execute(['id' => $userId]);

    return $stmt->fetch() ?: null;
}

/**
 * Comme current_vendor_shop() mais sans filtre de statut — utilisee par
 * includes/vendor_header.php (etat "pas de boutique operationnelle") et
 * vendeur/demande-boutique.php pour savoir POURQUOI il n'y a pas de
 * boutique operationnelle (aucune demande / en attente / refusee) et
 * afficher le bon message. Toutes les autres pages vendeur doivent
 * continuer a utiliser current_vendor_shop().
 */
function current_vendor_shop_request(int $userId): ?array
{
    $stmt = get_db()->prepare('SELECT * FROM shops WHERE owner_id = :id');
    $stmt->execute(['id' => $userId]);

    return $stmt->fetch() ?: null;
}

function login_user(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $db = get_db();
    $db->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP, last_activity_at = CURRENT_TIMESTAMP WHERE id = :id')
        ->execute(['id' => $userId]);
    $db->prepare('INSERT INTO login_history (user_id, ip_address) VALUES (:id, :ip)')
        ->execute(['id' => $userId, 'ip' => $ip]);
}

function logout_user(): void
{
    $_SESSION = [];
    session_destroy();
}

function safe_redirect_target(?string $target, string $default): string
{
    if ($target === null || $target === '' || $target[0] !== '/' || str_starts_with($target, '//')) {
        return $default;
    }

    return $target;
}
