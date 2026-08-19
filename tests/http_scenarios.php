<?php

declare(strict_types=1);

/**
 * Suite de test HTTP reelle (connexion, recherche, controle d'acces admin).
 *
 * Contrairement a wallet_scenarios.php (qui appelle les services en process,
 * directement contre la base), ces trois zones du site sont testees ici via
 * de VRAIES requetes HTTP (cURL, avec un pot de cookies par "acteur" simule,
 * exactement comme un navigateur) car leur comportement determinant —
 * redirections, blocage 403, session — repose sur header()/exit() qu'on ne
 * peut pas observer en appelant le code PHP directement dans ce process.
 *
 * PREREQUIS : le serveur local (WAMP) doit deja tourner sur http://localhost/market
 * — contrairement a wallet_scenarios.php, cette suite ne fonctionne PAS sans
 * serveur web actif.
 *
 * Usage CLI : php tests/http_scenarios.php
 *
 * Donnees jetables (prefixe email "httptest-"), entierement nettoyees a la
 * fin (succes ou echec) — y compris les compteurs rate_limit_hits crees
 * pour les besoins des tests.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI uniquement.');
}

require_once __DIR__ . '/../config/autoload.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

const BASE_URL = 'http://localhost/market';

$db = get_db();

// ----------------------------------------------------------------------
// Aides HTTP (un $ch = un "acteur"/navigateur isole avec son propre pot
// de cookies, exactement comme des onglets prives differents)
// ----------------------------------------------------------------------

/**
 * Si ADMIN_BASIC_AUTH_USER/ADMIN_BASIC_AUTH_PASS sont definis dans .env
 * (jamais commite), les requetes vers /admin/* incluent ces identifiants
 * HTTP — necessaire sur les environnements ou admin/.htaccess ajoute une
 * couche Basic Auth en amont de la page de connexion (voir DEPLOYMENT.md
 * §13). Absent sur un environnement sans cette protection : aucun effet.
 */
function http_actor(): \CurlHandle
{
    $ch = curl_init();
    $cookieFile = tempnam(sys_get_temp_dir(), 'httptest_cookie_');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        // 25s : admin/index.php appelle GeniusPay (compte marchand), dont le
        // propre timeout interne va jusqu'a 20s si l'API est injoignable/lente.
        CURLOPT_TIMEOUT => 25,
    ]);

    $basicAuthUser = env('ADMIN_BASIC_AUTH_USER');
    $basicAuthPass = env('ADMIN_BASIC_AUTH_PASS');
    if ($basicAuthUser !== null && $basicAuthPass !== null) {
        curl_setopt($ch, CURLOPT_USERPWD, "{$basicAuthUser}:{$basicAuthPass}");
    }

    return $ch;
}

function http_do(\CurlHandle $ch, string $method, string $url, array $post = []): array
{
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $method === 'POST' ? http_build_query($post) : null);
    $raw = curl_exec($ch);
    if ($raw === false) {
        return ['status' => 0, 'body' => '', 'location' => null, 'error' => curl_error($ch)];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $location = null;
    if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
        $location = trim($m[1]);
    }

    return ['status' => $status, 'body' => $body, 'location' => $location, 'error' => null];
}

function http_csrf(string $html): string
{
    return preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '';
}

// ----------------------------------------------------------------------
// Verification pre-vol : le serveur local doit repondre
// ----------------------------------------------------------------------

$ping = http_do(http_actor(), 'GET', BASE_URL . '/index.php');
if ($ping['status'] !== 200) {
    echo "ECHEC : le serveur local (" . BASE_URL . ") ne repond pas (http={$ping['status']}" . ($ping['error'] ? ", erreur: {$ping['error']}" : '') . ").\n";
    echo "Cette suite necessite WAMP demarre. Abandon.\n";
    exit(1);
}

// ----------------------------------------------------------------------
// Donnees jetables
// ----------------------------------------------------------------------

$created = ['users' => []];

function ht_make_user(PDO $db, array &$created, string $label, string $password, bool $isAdmin = false, bool $isBlocked = false): array
{
    $email = 'httptest-' . $label . '-' . bin2hex(random_bytes(4)) . '@example.com';
    $db->prepare('INSERT INTO users (name, email, password_hash, is_admin, is_blocked, blocked_reason) VALUES (:n, :e, :p, :a, :b, :r)')
        ->execute([
            'n' => "HTTP Test $label", 'e' => $email, 'p' => password_hash($password, PASSWORD_DEFAULT),
            'a' => $isAdmin ? 1 : 0, 'b' => $isBlocked ? 1 : 0, 'r' => $isBlocked ? 'Test suspension' : null,
        ]);
    $userId = (int) $db->lastInsertId();
    $created['users'][] = $userId;

    return ['id' => $userId, 'email' => $email, 'password' => $password];
}

$results = [];
$discoveredClientIp = null;

function ht_run(array &$results, string $name, callable $fn): void
{
    try {
        [$ok, $message] = $fn();
    } catch (\Throwable $e) {
        $ok = false;
        $message = 'Exception non geree : ' . $e->getMessage();
    }
    $results[] = ['name' => $name, 'ok' => $ok, 'message' => $message];
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ' — ' . $message . "\n";
}

// ========================================================================
// GROUPE 1 : Connexion
// ========================================================================

$userCorrect = ht_make_user($db, $created, 'login', 'Test1234!');
$userBlocked = ht_make_user($db, $created, 'blocked', 'Test1234!', false, true);

ht_run($results, '1. Connexion — mot de passe incorrect', function () use ($userCorrect) {
    $ch = http_actor();
    $get = http_do($ch, 'GET', BASE_URL . '/connexion.php');
    $token = http_csrf($get['body']);

    $post = http_do($ch, 'POST', BASE_URL . '/connexion.php', [
        '_csrf' => $token, 'email' => $userCorrect['email'], 'password' => 'MauvaisMotDePasse',
    ]);

    $ok = $post['status'] === 200 && str_contains($post['body'], 'Email ou mot de passe incorrect');
    return [$ok, "http={$post['status']}, message d'erreur present=" . (str_contains($post['body'], 'incorrect') ? 'oui' : 'non')];
});

ht_run($results, '2. Connexion — identifiants corrects', function () use ($userCorrect) {
    $ch = http_actor();
    $get = http_do($ch, 'GET', BASE_URL . '/connexion.php');
    $token = http_csrf($get['body']);

    $post = http_do($ch, 'POST', BASE_URL . '/connexion.php', [
        '_csrf' => $token, 'email' => $userCorrect['email'], 'password' => $userCorrect['password'],
    ]);

    if ($post['status'] !== 302 || $post['location'] !== '/market/compte.php') {
        return [false, "redirection attendue vers /market/compte.php, obtenu status={$post['status']} location=" . ($post['location'] ?? 'aucune')];
    }

    $compte = http_do($ch, 'GET', BASE_URL . '/compte.php');
    $ok = $compte['status'] === 200 && str_contains($compte['body'], 'HTTP Test login');

    return [$ok, "apres connexion, /compte.php http={$compte['status']}, nom affiche=" . (str_contains($compte['body'], 'HTTP Test login') ? 'oui' : 'non')];
});

ht_run($results, '3. Connexion — compte bloque', function () use ($userBlocked) {
    $ch = http_actor();
    $get = http_do($ch, 'GET', BASE_URL . '/connexion.php');
    $token = http_csrf($get['body']);

    $post = http_do($ch, 'POST', BASE_URL . '/connexion.php', [
        '_csrf' => $token, 'email' => $userBlocked['email'], 'password' => $userBlocked['password'],
    ]);

    $ok = $post['status'] === 200 && str_contains($post['body'], 'compte a été bloqué');
    return [$ok, "http={$post['status']}, message de blocage present=" . (str_contains($post['body'], 'bloqué') ? 'oui' : 'non')];
});

ht_run($results, '4. Connexion — limite de tentatives (10/900s)', function () use ($db, $userCorrect, &$discoveredClientIp) {
    $ch = http_actor();

    // Un premier essai reel (rate au mot de passe volontairement, peu importe)
    // sert uniquement a decouvrir la vraie cle rl_key utilisee par le serveur
    // pour CE client (son REMOTE_ADDR reel : "localhost" peut resoudre en
    // IPv4 ou IPv6 selon la machine, on ne le suppose jamais).
    $get = http_do($ch, 'GET', BASE_URL . '/connexion.php');
    $token = http_csrf($get['body']);
    http_do($ch, 'POST', BASE_URL . '/connexion.php', ['_csrf' => $token, 'email' => $userCorrect['email'], 'password' => 'peu-importe']);

    $key = $db->query("SELECT rl_key FROM rate_limit_hits WHERE rl_key LIKE 'login:%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    if (!$key) {
        return [false, "impossible de determiner la cle rate_limit_hits reelle"];
    }
    $discoveredClientIp = substr($key, strlen('login:'));

    $db->prepare('DELETE FROM rate_limit_hits WHERE rl_key = :k')->execute(['k' => $key]);
    for ($i = 0; $i < 10; $i++) {
        $db->prepare('INSERT INTO rate_limit_hits (rl_key) VALUES (:k)')->execute(['k' => $key]);
    }

    // Meme avec les VRAIS bons identifiants, cette tentative doit etre bloquee.
    $get2 = http_do($ch, 'GET', BASE_URL . '/connexion.php');
    $token2 = http_csrf($get2['body']);
    $post = http_do($ch, 'POST', BASE_URL . '/connexion.php', [
        '_csrf' => $token2, 'email' => $userCorrect['email'], 'password' => $userCorrect['password'],
    ]);

    $db->prepare('DELETE FROM rate_limit_hits WHERE rl_key = :k')->execute(['k' => $key]);

    $ok = $post['status'] === 200 && str_contains($post['body'], 'Trop de tentatives');
    return [$ok, "cle={$key}, http={$post['status']}, message limite present=" . (str_contains($post['body'], 'Trop de tentatives') ? 'oui' : 'non')];
});

ht_run($results, '5. Connexion — page protegee inaccessible sans session', function () {
    $ch = http_actor();
    $get = http_do($ch, 'GET', BASE_URL . '/compte.php');

    $ok = $get['status'] === 302 && str_starts_with((string) $get['location'], '/market/connexion.php');
    return [$ok, "http={$get['status']}, location=" . ($get['location'] ?? 'aucune')];
});

// ========================================================================
// GROUPE 2 : Recherche
// ========================================================================

$catId = (int) $db->query('SELECT id FROM categories LIMIT 1')->fetchColumn();

$db->prepare("INSERT INTO shops (name, slug, neighborhood, logo_letter, color, is_open, approval_status, rating, review_count)
    VALUES ('HTTP Test Shop Visible', 'httptest-shop-visible', 'Q', 'HV', '#16a34a', 1, 'approved', 5.0, 0)")->execute();
$visibleShopId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO shop_subscriptions (shop_id, plan_id, plan_name, price_paid, starts_at, ends_at) VALUES (:s, 1, "Test", 0, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR))')
    ->execute(['s' => $visibleShopId]);
$db->prepare("INSERT INTO products (name, slug, category_id, shop_id, price, stock, size_type, sort_order)
    VALUES ('Zzargle Produit Visible', 'httptest-product-visible', :c, :s, 1000, 5, 'none', 0)")
    ->execute(['c' => $catId, 's' => $visibleShopId]);
$visibleProductId = (int) $db->lastInsertId();

$db->prepare("INSERT INTO shops (name, slug, neighborhood, logo_letter, color, is_open, approval_status, rating, review_count)
    VALUES ('HTTP Test Shop Invisible', 'httptest-shop-invisible', 'Q', 'HI', '#16a34a', 1, 'approved', 5.0, 0)")->execute();
$invisibleShopId = (int) $db->lastInsertId();
// Pas d'abonnement actif pour cette boutique : ne doit apparaitre nulle part cote public.
$db->prepare("INSERT INTO products (name, slug, category_id, shop_id, price, stock, size_type, sort_order)
    VALUES ('Zzargle Produit Invisible', 'httptest-product-invisible', :c, :s, 1000, 5, 'none', 0)")
    ->execute(['c' => $catId, 's' => $invisibleShopId]);

ht_run($results, '6. Recherche — requete vide', function () {
    $get = http_do(http_actor(), 'GET', BASE_URL . '/recherche.php');
    $ok = $get['status'] === 200 && str_contains($get['body'], 'Saisissez un mot-clé');
    return [$ok, "http={$get['status']}"];
});

ht_run($results, '7. Recherche — aucun resultat', function () {
    $get = http_do(http_actor(), 'GET', BASE_URL . '/recherche.php?q=' . urlencode('xyzzynoexiste123'));
    $ok = $get['status'] === 200 && str_contains($get['body'], 'Aucun résultat');
    return [$ok, "http={$get['status']}"];
});

ht_run($results, '8. Recherche — trouve un produit reel', function () {
    $get = http_do(http_actor(), 'GET', BASE_URL . '/recherche.php?q=' . urlencode('Zzargle Produit Visible'));
    $ok = $get['status'] === 200 && str_contains($get['body'], 'httptest-product-visible');
    return [$ok, "http={$get['status']}, produit present dans les resultats=" . (str_contains($get['body'], 'httptest-product-visible') ? 'oui' : 'non')];
});

ht_run($results, '9. Recherche — respecte la visibilite (boutique sans abonnement absente)', function () {
    $get = http_do(http_actor(), 'GET', BASE_URL . '/recherche.php?q=' . urlencode('Zzargle Produit Invisible'));
    $ok = $get['status'] === 200 && str_contains($get['body'], 'Aucun résultat') && !str_contains($get['body'], 'httptest-product-invisible');
    return [$ok, "http={$get['status']}, correctement absent=" . (!str_contains($get['body'], 'httptest-product-invisible') ? 'oui' : 'NON (fuite de visibilite)')];
});

ht_run($results, "10. Recherche — entree exotique (guillemets/quotes) ne casse rien", function () {
    $get = http_do(http_actor(), 'GET', BASE_URL . '/recherche.php?q=' . urlencode("' OR '1'='1"));
    $ok = $get['status'] === 200;
    return [$ok, "http={$get['status']} (doit rester 200, jamais d'erreur serveur)"];
});

// ========================================================================
// GROUPE 3 : Controle d'acces admin
// ========================================================================

$userRegular = ht_make_user($db, $created, 'regular', 'Test1234!');
$userAdmin = ht_make_user($db, $created, 'admin', 'Test1234!', true);

ht_run($results, '11. Admin — anonyme redirige vers la connexion admin', function () {
    $get = http_do(http_actor(), 'GET', BASE_URL . '/admin/index.php');
    $ok = $get['status'] === 302 && str_starts_with((string) $get['location'], '/market/admin/connexion.php');
    return [$ok, "http={$get['status']}, location=" . ($get['location'] ?? 'aucune')];
});

ht_run($results, '12. Admin — utilisateur non-admin bloque (403)', function () use ($userRegular) {
    $ch = http_actor();
    $get = http_do($ch, 'GET', BASE_URL . '/connexion.php');
    $token = http_csrf($get['body']);
    http_do($ch, 'POST', BASE_URL . '/connexion.php', ['_csrf' => $token, 'email' => $userRegular['email'], 'password' => $userRegular['password']]);

    $adminPage = http_do($ch, 'GET', BASE_URL . '/admin/index.php');
    $ok = $adminPage['status'] === 403;
    return [$ok, "http={$adminPage['status']} (attendu 403)"];
});

ht_run($results, '13. Admin — connexion 2FA complete donne acces', function () use ($db, $userAdmin) {
    $ch = http_actor();
    $get = http_do($ch, 'GET', BASE_URL . '/admin/connexion.php');
    $token = http_csrf($get['body']);
    $login = http_do($ch, 'POST', BASE_URL . '/admin/connexion.php', ['_csrf' => $token, 'email' => $userAdmin['email'], 'password' => $userAdmin['password']]);

    if ($login['status'] !== 302 || $login['location'] !== '/market/verification-2fa.php') {
        return [false, "apres mot de passe, redirection 2FA attendue, obtenu status={$login['status']} location=" . ($login['location'] ?? 'aucune')];
    }

    // Le code reel n'est jamais visible en HTTP (envoye par email) : on le
    // regenere directement via la meme fonction que l'application, comme le
    // reste de cette session de travail le fait pour les tests 2FA.
    $code = issue_login_2fa_code($userAdmin['id']);

    $twofa = http_do($ch, 'GET', BASE_URL . '/verification-2fa.php');
    $token2 = http_csrf($twofa['body']);
    $verify = http_do($ch, 'POST', BASE_URL . '/verification-2fa.php', ['_csrf' => $token2, 'code' => $code]);

    if ($verify['status'] !== 302 || $verify['location'] !== '/market/admin/index.php') {
        return [false, "apres code 2FA, redirection admin attendue, obtenu status={$verify['status']} location=" . ($verify['location'] ?? 'aucune')];
    }

    $adminPage = http_do($ch, 'GET', BASE_URL . '/admin/index.php');
    $ok = $adminPage['status'] === 200 && str_contains($adminPage['body'], 'HTTP Test admin');

    return [$ok, "acces admin/index.php apres connexion complete : http={$adminPage['status']}" . ($adminPage['error'] ? " erreur cURL: {$adminPage['error']}" : '')];
});

// ----------------------------------------------------------------------
// Nettoyage
// ----------------------------------------------------------------------

function ht_cleanup_step(string $label, callable $fn): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        echo "[NETTOYAGE] echec sur '{$label}' : {$e->getMessage()}\n";
    }
}

ht_cleanup_step('rate_limit_hits residuels', function () use ($db, $discoveredClientIp) {
    $ip = $discoveredClientIp ?? '127.0.0.1';
    $db->prepare("DELETE FROM rate_limit_hits WHERE rl_key IN (:k1, :k2, :k3, :k4)")->execute([
        'k1' => 'login:' . $ip, 'k2' => 'admin_login:' . $ip, 'k3' => 'login_2fa_verify:' . $ip, 'k4' => 'login_2fa_resend:' . $ip,
    ]);
});
ht_cleanup_step('login_2fa_codes+login_history', function () use ($db, $created) {
    foreach ($created['users'] as $userId) {
        $db->exec("DELETE FROM login_2fa_codes WHERE user_id = $userId");
        $db->exec("DELETE FROM login_history WHERE user_id = $userId");
    }
});
ht_cleanup_step('products+shops', function () use ($db, $visibleProductId, $visibleShopId, $invisibleShopId) {
    $db->exec("DELETE FROM products WHERE slug IN ('httptest-product-visible', 'httptest-product-invisible')");
    $db->exec("DELETE FROM shop_subscriptions WHERE shop_id = $visibleShopId");
    $db->exec("DELETE FROM shops WHERE slug IN ('httptest-shop-visible', 'httptest-shop-invisible')");
});
ht_cleanup_step('users', function () use ($db, $created) {
    foreach ($created['users'] as $userId) {
        $db->exec("DELETE FROM users WHERE id = $userId");
    }
});

// ----------------------------------------------------------------------
// Rapport
// ----------------------------------------------------------------------

$passed = count(array_filter($results, fn ($r) => $r['ok']));
$total = count($results);

echo "\n============================================\n";
echo "RESULTAT : {$passed}/{$total} scenarios reussis\n";
echo "============================================\n";

exit($passed === $total ? 0 : 1);
