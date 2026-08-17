<?php
declare(strict_types=1);

/**
 * Listes canoniques de tailles pour les produits size_type='clothing'/'shoe'.
 * Reutilisees partout ou une taille est proposee/validee (formulaire
 * admin/vendeur, validation serveur dans OrderService) pour eviter toute
 * divergence entre ce qui est propose au client et ce qui est accepte.
 */
const CLOTHING_SIZES = ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL', '4XL', '5XL'];

function shoe_sizes(): array
{
    return array_map('strval', range(20, 50));
}

function product_size_options(string $sizeType): array
{
    return match ($sizeType) {
        'clothing' => CLOTHING_SIZES,
        'shoe' => shoe_sizes(),
        default => [],
    };
}

/**
 * Genere et enregistre un nouveau code de verification en deux etapes
 * (connexion admin). Invalide tout code precedent pour cet utilisateur.
 * Seul son hash SHA-256 est stocke ; le code en clair n'est renvoye que
 * pour permettre son envoi immediat par email (jamais persiste ailleurs).
 */
function issue_login_2fa_code(int $userId): string
{
    $db = get_db();
    $db->prepare('DELETE FROM login_2fa_codes WHERE user_id = :id')->execute(['id' => $userId]);

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $stmt = $db->prepare('INSERT INTO login_2fa_codes (user_id, code_hash, expires_at) VALUES (:user_id, :hash, DATE_ADD(NOW(), INTERVAL 10 MINUTE))');
    $stmt->execute(['user_id' => $userId, 'hash' => hash('sha256', $code)]);

    return $code;
}

function issue_email_verification_token(int $userId): string
{
    $db = get_db();
    $db->prepare('DELETE FROM email_verifications WHERE user_id = :id')->execute(['id' => $userId]);

    $token = bin2hex(random_bytes(32));
    $stmt = $db->prepare('INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (:user_id, :hash, DATE_ADD(NOW(), INTERVAL 24 HOUR))');
    $stmt->execute(['user_id' => $userId, 'hash' => hash('sha256', $token)]);

    return $token;
}

function format_price(int $amount): string
{
    return number_format($amount, 0, ',', ' ') . ' FCFA';
}

function discount_percent(int $price, ?int $originalPrice): ?int
{
    if (!$originalPrice || $originalPrice <= $price) {
        return null;
    }

    return (int) round((1 - $price / $originalPrice) * 100);
}

/**
 * Calcule la page courante, l'offset SQL et le nombre total de pages
 * a partir du nombre total d'elements et de $_GET['page'].
 */
function paginate(int $totalItems, int $perPage): array
{
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $page = max(1, min((int) ($_GET['page'] ?? 1), $totalPages));

    return [
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
        'total_items' => $totalItems,
        'offset' => ($page - 1) * $perPage,
    ];
}

/**
 * Rendu HTML de la navigation de pagination. $extraParams sont les
 * autres parametres GET a preserver (recherche, filtres...).
 */
function pagination_html(int $page, int $totalPages, string $baseUrl, array $extraParams = []): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $urlFor = static function (int $p) use ($baseUrl, $extraParams): string {
        return $baseUrl . '?' . http_build_query(array_merge($extraParams, ['page' => $p]));
    };

    $html = '<nav class="pagination" aria-label="Pagination">';

    $html .= $page > 1
        ? '<a href="' . e($urlFor($page - 1)) . '" class="pagination-btn">&lsaquo; Précédent</a>'
        : '<span class="pagination-btn is-disabled">&lsaquo; Précédent</span>';

    $windowStart = max(1, $page - 2);
    $windowEnd = min($totalPages, $page + 2);

    if ($windowStart > 1) {
        $html .= '<a href="' . e($urlFor(1)) . '" class="pagination-page">1</a>';
        if ($windowStart > 2) {
            $html .= '<span class="pagination-ellipsis">…</span>';
        }
    }

    for ($i = $windowStart; $i <= $windowEnd; $i++) {
        $html .= $i === $page
            ? '<span class="pagination-page is-current">' . $i . '</span>'
            : '<a href="' . e($urlFor($i)) . '" class="pagination-page">' . $i . '</a>';
    }

    if ($windowEnd < $totalPages) {
        if ($windowEnd < $totalPages - 1) {
            $html .= '<span class="pagination-ellipsis">…</span>';
        }
        $html .= '<a href="' . e($urlFor($totalPages)) . '" class="pagination-page">' . $totalPages . '</a>';
    }

    $html .= $page < $totalPages
        ? '<a href="' . e($urlFor($page + 1)) . '" class="pagination-btn">Suivant &rsaquo;</a>'
        : '<span class="pagination-btn is-disabled">Suivant &rsaquo;</span>';

    $html .= '</nav>';

    return $html;
}

/**
 * Sous-requete SQL reutilisable : IDs des boutiques ayant un abonnement
 * actif aujourd'hui. Toujours une syntaxe valide (meme si aucune ligne),
 * a utiliser dans un "WHERE shop_id IN (...)" / "WHERE s.id IN (...)".
 */
function active_subscription_shops_subquery(): string
{
    return "(SELECT DISTINCT ss.shop_id FROM shop_subscriptions ss JOIN shops s ON s.id = ss.shop_id WHERE CURDATE() BETWEEN ss.starts_at AND ss.ends_at AND s.approval_status = 'approved')";
}

function get_shop_active_subscription(int $shopId): ?array
{
    $stmt = get_db()->prepare('
        SELECT * FROM shop_subscriptions
        WHERE shop_id = :id AND CURDATE() BETWEEN starts_at AND ends_at
        ORDER BY ends_at DESC LIMIT 1
    ');
    $stmt->execute(['id' => $shopId]);

    return $stmt->fetch() ?: null;
}

function get_shop_latest_subscription(int $shopId): ?array
{
    $stmt = get_db()->prepare('SELECT * FROM shop_subscriptions WHERE shop_id = :id ORDER BY ends_at DESC LIMIT 1');
    $stmt->execute(['id' => $shopId]);

    return $stmt->fetch() ?: null;
}

function apply_subscription_payment(int $shopId, array $plan, int $pricePaid): void
{
    $latest = get_shop_latest_subscription($shopId);
    $today = new DateTimeImmutable('today');
    $startsAt = $today;
    if ($latest && new DateTimeImmutable((string) $latest['ends_at']) >= $today) {
        $startsAt = (new DateTimeImmutable((string) $latest['ends_at']))->modify('+1 day');
    }
    $endsAt = $startsAt->modify('+' . (int) $plan['duration_months'] . ' months');

    $stmt = get_db()->prepare('
        INSERT INTO shop_subscriptions (shop_id, plan_id, plan_name, price_paid, starts_at, ends_at)
        VALUES (:shop_id, :plan_id, :plan_name, :price_paid, :starts_at, :ends_at)
    ');
    $stmt->execute([
        'shop_id' => $shopId,
        'plan_id' => $plan['id'],
        'plan_name' => $plan['name'],
        'price_paid' => $pricePaid,
        'starts_at' => $startsAt->format('Y-m-d'),
        'ends_at' => $endsAt->format('Y-m-d'),
    ]);
}

function get_active_promotions(): array
{
    static $promotions = null;

    if ($promotions === null) {
        $promotions = get_db()->query("
            SELECT * FROM promotions
            WHERE is_active = 1 AND CURDATE() BETWEEN starts_at AND ends_at
        ")->fetchAll();
    }

    return $promotions;
}

function get_active_advertisements(): array
{
    static $ads = null;

    if ($ads === null) {
        $ads = get_db()->query("
            SELECT * FROM advertisements
            WHERE is_active = 1 AND CURDATE() BETWEEN starts_at AND ends_at
            ORDER BY sort_order
        ")->fetchAll();
    }

    return $ads;
}

/**
 * Determine le prix effectif d'un produit : la meilleure promotion active
 * (par categorie ou site entier) prend le pas sur la remise permanente du
 * produit (price/original_price), pour eviter d'empiler deux remises.
 */
function get_product_price(array $product): array
{
    $price = (int) $product['price'];
    $originalPrice = $product['original_price'] !== null ? (int) $product['original_price'] : null;

    $bestDiscount = 0;
    $promotionName = null;

    foreach (get_active_promotions() as $promo) {
        $applies = $promo['scope'] === 'all'
            || (int) $promo['category_id'] === (int) ($product['category_id'] ?? 0);

        if ($applies && (int) $promo['discount_percent'] > $bestDiscount) {
            $bestDiscount = (int) $promo['discount_percent'];
            $promotionName = $promo['name'];
        }
    }

    if ($bestDiscount > 0) {
        return [
            'price' => (int) round($price * (1 - $bestDiscount / 100)),
            'original_price' => $price,
            'discount_percent' => $bestDiscount,
            'promotion_name' => $promotionName,
        ];
    }

    return [
        'price' => $price,
        'original_price' => $originalPrice,
        'discount_percent' => discount_percent($price, $originalPrice),
        'promotion_name' => null,
    ];
}

/**
 * Valide un fichier uploade ($_FILES[...]) et retourne son extension
 * (jpg/png/webp/gif) determinee par le contenu reel du fichier, ou un
 * message d'erreur en francais si la validation echoue.
 */
function validate_uploaded_image(array $file): array
{
    $maxBytes = 3 * 1024 * 1024;
    $mimeExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ext' => null, 'error' => "Une erreur s'est produite pendant l'envoi de l'image."];
    }
    if ($file['size'] > $maxBytes) {
        return ['ext' => null, 'error' => "L'image ne doit pas dépasser 3 Mo."];
    }

    $info = @getimagesize($file['tmp_name']);
    $mime = $info['mime'] ?? null;
    if (!$info || !isset($mimeExt[$mime])) {
        return ['ext' => null, 'error' => "Format d'image non supporté (JPG, PNG, WEBP ou GIF uniquement)."];
    }

    return ['ext' => $mimeExt[$mime], 'error' => null];
}

function store_uploaded_image(array $file, string $ext, string $uploadDirAbsolute, string $webPathPrefix): string
{
    if (!is_dir($uploadDirAbsolute)) {
        mkdir($uploadDirAbsolute, 0777, true);
    }

    $filename = bin2hex(random_bytes(8)) . '.' . $ext;
    move_uploaded_file($file['tmp_name'], rtrim($uploadDirAbsolute, '/') . '/' . $filename);

    return rtrim($webPathPrefix, '/') . '/' . $filename;
}

function delete_uploaded_image(?string $relativePath): void
{
    if (!$relativePath) {
        return;
    }
    $path = __DIR__ . '/../' . $relativePath;
    if (is_file($path)) {
        unlink($path);
    }
}

function order_status_label(string $status): string
{
    return [
        'pending' => 'En attente',
        'processing' => 'En préparation',
        'shipping' => 'En livraison',
        'delivered' => 'Livrée',
        'cancelled' => 'Annulée',
        'not_collected' => 'Non retirée',
        'processed' => 'Traitée',
    ][$status] ?? ucfirst($status);
}

function order_status_tag_class(string $status): string
{
    return [
        'pending' => 'tag-pending',
        'processing' => 'tag-processing',
        'shipping' => 'tag-shipping',
        'delivered' => 'tag-green',
        'cancelled' => 'tag-closed',
        'not_collected' => 'tag-closed',
        'processed' => 'tag-green',
    ][$status] ?? '';
}

/**
 * Comptabilise un non-retrait pour un client connecte. Au 2e non-retrait
 * depuis la derniere remise a zero, le client doit payer en ligne pour sa
 * prochaine commande (voir commander.php).
 */
function record_failed_pickup(int $userId): void
{
    $db = get_db();
    $stmt = $db->prepare('SELECT failed_pickup_count FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $count = (int) $stmt->fetchColumn() + 1;

    if ($count >= 2) {
        $db->prepare('UPDATE users SET payment_restricted = 1, failed_pickup_count = 0 WHERE id = :id')->execute(['id' => $userId]);
    } else {
        $db->prepare('UPDATE users SET failed_pickup_count = :count WHERE id = :id')->execute(['count' => $count, 'id' => $userId]);
    }
}

/**
 * Recredite le stock d'UN produit (et, s'il est vendu par taille, de la
 * taille concernee) suite a une annulation de commande ou un remboursement.
 * Symetrique de la decrementation faite sous verrou dans
 * OrderService::resolveCartItems(). $productId peut correspondre a un
 * produit depuis supprime (order_items.product_id passe alors a NULL,
 * voir ON DELETE SET NULL) : dans ce cas l'appelant ne doit pas appeler
 * cette fonction (rien a recrediter).
 */
function restore_product_stock(int $productId, ?string $size, int $quantity): void
{
    if ($quantity <= 0) {
        return;
    }

    $db = get_db();
    $db->prepare('UPDATE products SET stock = stock + :qty WHERE id = :id')->execute(['qty' => $quantity, 'id' => $productId]);
    if ($size !== null) {
        $db->prepare('UPDATE product_sizes SET stock = stock + :qty WHERE product_id = :product_id AND size = :size')
            ->execute(['qty' => $quantity, 'product_id' => $productId, 'size' => $size]);
    }
}

/**
 * Recredite le stock de TOUTES les lignes d'une commande annulee, moins ce
 * qui a deja ete rembourse individuellement (deja recredite par
 * restore_product_stock() au moment du remboursement, ne pas compter deux
 * fois). A appeler UNE SEULE FOIS, uniquement au moment ou le statut de la
 * commande passe A 'cancelled' (l'appelant doit verifier que ce n'etait
 * pas deja son statut avant d'appeler cette fonction).
 */
function restore_order_stock(int $orderId): void
{
    $db = get_db();
    $stmt = $db->prepare('SELECT product_id, size, quantity, refunded_quantity FROM order_items WHERE order_id = :id');
    $stmt->execute(['id' => $orderId]);

    foreach ($stmt->fetchAll() as $item) {
        if ($item['product_id'] === null) {
            continue;
        }
        $qty = (int) $item['quantity'] - (int) $item['refunded_quantity'];
        if ($qty > 0) {
            restore_product_stock((int) $item['product_id'], $item['size'], $qty);
        }
    }
}

/**
 * Recredite le stock des articles d'UNE boutique dans une commande qui ne
 * sont pas deja marques 'rejected' (evite un recredit en double si le
 * vendeur re-soumet 'rejected' plusieurs fois). Symetrique de
 * restore_order_stock() mais limitee aux articles de cette boutique,
 * utilisee quand un vendeur rejette sa part d'une commande multi-boutiques
 * sans que la commande entiere ne soit annulee.
 */
function restore_rejected_order_items_stock(int $orderId, int $shopId): void
{
    $db = get_db();
    $stmt = $db->prepare("
        SELECT product_id, size, quantity, refunded_quantity
        FROM order_items
        WHERE order_id = :order_id AND shop_id = :shop_id AND fulfillment_status != 'rejected'
    ");
    $stmt->execute(['order_id' => $orderId, 'shop_id' => $shopId]);

    foreach ($stmt->fetchAll() as $item) {
        if ($item['product_id'] === null) {
            continue;
        }
        $qty = (int) $item['quantity'] - (int) $item['refunded_quantity'];
        if ($qty > 0) {
            restore_product_stock((int) $item['product_id'], $item['size'], $qty);
        }
    }
}

/**
 * Comptabilise un paiement en ligne reussi pour un client actuellement
 * restreint (payment_restricted=1). Apres 2 paiements en ligne reussis
 * consecutifs, le client retrouve le choix du paiement a la livraison.
 * Sans effet si le client n'est pas restreint (le compteur ne sert qu'a
 * un rachat en cours).
 */
function record_successful_online_payment(int $userId): void
{
    $db = get_db();
    $stmt = $db->prepare('SELECT payment_restricted, online_payment_streak FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();

    if (!$row || (int) $row['payment_restricted'] !== 1) {
        return;
    }

    $streak = (int) $row['online_payment_streak'] + 1;

    if ($streak >= 2) {
        $db->prepare('UPDATE users SET payment_restricted = 0, online_payment_streak = 0 WHERE id = :id')->execute(['id' => $userId]);
    } else {
        $db->prepare('UPDATE users SET online_payment_streak = :streak WHERE id = :id')->execute(['streak' => $streak, 'id' => $userId]);
    }
}

function vendor_item_status_label(string $status): string
{
    return [
        'pending' => 'En attente',
        'confirmed' => 'Confirmée',
        'rejected' => 'Rejetée',
        'shipped' => 'Expédiée',
        'delivered' => 'Livrée',
        'cancelled' => 'Annulée',
    ][$status] ?? ucfirst($status);
}

function vendor_item_status_tag_class(string $status): string
{
    return [
        'pending' => 'tag-pending',
        'confirmed' => 'tag-green',
        'rejected' => 'tag-closed',
        'shipped' => 'tag-processing',
        'delivered' => 'tag-green',
        'cancelled' => 'tag-closed',
    ][$status] ?? '';
}

function commission_status_label(string $status): string
{
    return [
        'pending' => 'En attente',
        'applied' => 'Appliquée',
        'partially_reversed' => 'Partiellement annulée',
        'reversed' => 'Annulée',
    ][$status] ?? ucfirst($status);
}

function commission_status_tag_class(string $status): string
{
    return [
        'pending' => 'tag-pending',
        'applied' => 'tag-green',
        'partially_reversed' => 'tag-processing',
        'reversed' => 'tag-closed',
    ][$status] ?? '';
}

function withdrawal_status_label(string $status): string
{
    return [
        'pending' => 'En attente',
        'approved' => 'Approuvée',
        'rejected' => 'Rejetée',
        'paid' => 'Payée',
    ][$status] ?? ucfirst($status);
}

function withdrawal_status_tag_class(string $status): string
{
    return [
        'pending' => 'tag-pending',
        'approved' => 'tag-processing',
        'rejected' => 'tag-closed',
        'paid' => 'tag-green',
    ][$status] ?? '';
}

function wallet_withdrawal_status_label(string $status): string
{
    return [
        'PENDING' => 'En attente',
        'APPROVED' => 'Approuvé',
        'PROCESSING' => 'En cours de paiement',
        'COMPLETED' => 'Payé',
        'REJECTED' => 'Rejeté',
        'FAILED' => 'Échoué',
        'CANCELLED' => 'Annulé',
    ][$status] ?? ucfirst($status);
}

function wallet_withdrawal_status_tag_class(string $status): string
{
    return [
        'PENDING' => 'tag-pending',
        'APPROVED' => 'tag-processing',
        'PROCESSING' => 'tag-processing',
        'COMPLETED' => 'tag-green',
        'REJECTED' => 'tag-closed',
        'FAILED' => 'tag-closed',
        'CANCELLED' => 'tag-closed',
    ][$status] ?? '';
}

function wallet_transaction_type_label(string $type): string
{
    return [
        'SALE' => 'Vente',
        'COMMISSION' => 'Commission',
        'REFUND' => 'Remboursement',
        'WITHDRAWAL' => 'Retrait',
        'WITHDRAWAL_REVERSAL' => 'Annulation de retrait',
        'ADJUSTMENT' => 'Ajustement',
    ][$type] ?? ucfirst($type);
}

function site_base_url(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host . '/market';
}

function get_order_payment(int $orderId): ?array
{
    $stmt = get_db()->prepare('SELECT * FROM legacy_payments WHERE order_id = :order_id ORDER BY id DESC LIMIT 1');
    $stmt->execute(['order_id' => $orderId]);

    return $stmt->fetch() ?: null;
}

function payment_status_label(string $status): string
{
    return [
        'pending' => 'En attente de paiement',
        'processing' => 'Paiement en cours',
        'completed' => 'Payé',
        'failed' => 'Échec du paiement',
        'cancelled' => 'Paiement annulé',
        'expired' => 'Lien de paiement expiré',
        'refunded' => 'Remboursé',
    ][$status] ?? ucfirst($status);
}

function payment_status_tag_class(string $status): string
{
    return [
        'pending' => 'tag-pending',
        'processing' => 'tag-processing',
        'completed' => 'tag-green',
        'failed' => 'tag-closed',
        'cancelled' => 'tag-closed',
        'expired' => 'tag-closed',
        'refunded' => 'tag-pending',
    ][$status] ?? '';
}

function connection_status_html(?string $lastActivity, ?string $lastLogin): string
{
    if ($lastActivity && strtotime($lastActivity) >= time() - 300) {
        return '<span class="tag tag-open">En ligne</span>';
    }
    $reference = $lastActivity ?: $lastLogin;
    if (!$reference) {
        return '<span class="char-count">Jamais connecté</span>';
    }

    return '<span class="char-count">Vu le ' . e(date('d/m/Y à H:i', strtotime($reference))) . '</span>';
}

function order_tracker_html(string $status): string
{
    if ($status === 'cancelled') {
        return '<div class="order-tracker order-tracker-cancelled">' . icon('x', 16) . '<span>Cette commande a été annulée.</span></div>';
    }

    $steps = [
        'pending' => 'Reçue',
        'processing' => 'En préparation',
        'shipping' => 'En livraison',
        'delivered' => 'Livrée',
    ];
    $order = array_keys($steps);
    $currentIndex = array_search($status, $order, true);
    if ($currentIndex === false) {
        $currentIndex = 0;
    }

    $isDelivered = $status === 'delivered';

    $html = '<div class="order-tracker">';
    $i = 0;
    foreach ($steps as $key => $label) {
        $done = $i < $currentIndex || ($isDelivered && $i === $currentIndex);
        $state = $done ? 'is-done' : ($i === $currentIndex ? 'is-current' : '');
        $html .= '<div class="order-tracker-step ' . $state . '">'
            . '<span class="order-tracker-dot">' . ($done ? icon('check', 12) : '') . '</span>'
            . '<span class="order-tracker-label">' . e($label) . '</span>'
            . '</div>';
        $i++;
    }
    $html .= '</div>';

    return $html;
}

function render_stars(float $rating): string
{
    $full = (int) floor($rating);
    $half = ($rating - $full) >= 0.5 ? 1 : 0;
    $empty = 5 - $full - $half;
    $html = '';

    for ($i = 0; $i < $full; $i++) {
        $html .= icon('star-filled', 14);
    }
    if ($half) {
        $html .= icon('star-half', 14);
    }
    for ($i = 0; $i < $empty; $i++) {
        $html .= icon('star-empty', 14);
    }

    return $html;
}

function product_rating_html(array $product): string
{
    $reviewCount = (int) $product['review_count'];

    if ($reviewCount === 0) {
        return '<div class="rating-row rating-row-empty">Pas encore d\'avis</div>';
    }

    return '<div class="rating-row">' . render_stars((float) $product['rating'])
        . '<span>(' . $reviewCount . ')</span></div>';
}

function shop_is_open_value(array $product): bool
{
    return !array_key_exists('shop_is_open', $product) || (int) $product['shop_is_open'] === 1;
}

function stock_badge_html(array $product): string
{
    if (!shop_is_open_value($product)) {
        return '<span class="tag tag-closed">Boutique fermée</span>';
    }

    $stock = (int) ($product['stock'] ?? 0);

    if ($stock <= 0) {
        return '<span class="tag tag-closed">Rupture de stock</span>';
    }
    if ($stock <= 5) {
        return '<span class="tag tag-urgent">Vite, il ne reste que ' . $stock . ' en stock !</span>';
    }

    return '<span class="tag tag-open">En stock</span>';
}

function add_to_cart_button_html(array $product, string $sizeClass = 'btn-sm btn-block'): string
{
    if (!shop_is_open_value($product)) {
        return '<button type="button" class="btn btn-outline-primary ' . $sizeClass . '" disabled>Boutique fermée</button>';
    }

    $stock = (int) ($product['stock'] ?? 0);

    if ($stock <= 0) {
        return '<button type="button" class="btn btn-outline-primary ' . $sizeClass . '" disabled>Rupture de stock</button>';
    }

    if (($product['size_type'] ?? 'none') !== 'none') {
        return '<a href="/market/produit.php?slug=' . e($product['slug']) . '" class="btn btn-primary ' . $sizeClass . '">Voir le produit</a>';
    }

    return '<button type="button" class="btn btn-primary ' . $sizeClass . ' add-cart-btn" data-id="product-' . (int) $product['id'] . '" data-name="' . e($product['name']) . '">Ajouter au panier</button>';
}

/**
 * Remplace rating/review_count (colonnes figees a la creation) par la
 * vraie moyenne calculee depuis les avis des produits de chaque boutique.
 */
function attach_live_shop_ratings(array $shops): array
{
    if (!$shops) {
        return $shops;
    }

    $ids = array_map(static fn($shop) => (int) $shop['id'], $shops);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = get_db()->prepare("
        SELECT p.shop_id, COUNT(r.id) AS review_count, AVG(r.rating) AS avg_rating
        FROM products p
        JOIN reviews r ON r.product_id = p.id
        WHERE p.shop_id IN ($placeholders)
        GROUP BY p.shop_id
    ");
    $stmt->execute($ids);

    $stats = [];
    foreach ($stmt->fetchAll() as $row) {
        $stats[(int) $row['shop_id']] = ['review_count' => (int) $row['review_count'], 'rating' => (float) $row['avg_rating']];
    }

    foreach ($shops as &$shop) {
        $s = $stats[(int) $shop['id']] ?? ['review_count' => 0, 'rating' => 0.0];
        $shop['rating'] = $s['rating'];
        $shop['review_count'] = $s['review_count'];
    }
    unset($shop);

    return $shops;
}

function shop_rating_html(array $shop): string
{
    $reviewCount = (int) $shop['review_count'];

    if ($reviewCount === 0) {
        return '<div class="rating-row rating-row-empty">Pas encore d\'avis</div>';
    }

    return '<div class="rating-row">' . render_stars((float) $shop['rating'])
        . '<strong>' . number_format((float) $shop['rating'], 1) . '</strong>'
        . '<span>(' . $reviewCount . ' avis)</span></div>';
}

/**
 * Bibliotheque d'icones SVG inline (style feather), pour rester
 * autonome sans dependance a une CDN.
 */
function icon(string $name, int $size = 20): string
{
    $paths = [
        'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'heart' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/>',
        'cart' => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/>',
        'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'chevron-down' => '<polyline points="6 9 12 15 18 9"/>',
        'chevron-right' => '<polyline points="9 18 15 12 9 6"/>',
        'menu' => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'x' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'map-pin' => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'phone' => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.1-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .3 2 .7 3a2 2 0 0 1-.4 2.1L8 10.3a16 16 0 0 0 5.7 5.7l1.5-1.4a2 2 0 0 1 2.1-.4c1 .4 2 .6 3 .7a2 2 0 0 1 1.7 2.1z"/>',
        'check' => '<polyline points="20 6 9 17 4 12"/>',
        'check-circle' => '<path d="M22 11.1V12a10 10 0 1 1-5.9-9.1"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'truck' => '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
        'shield' => '<path d="M12 2 4 5v6c0 5.5 3.4 10.7 8 12 4.6-1.3 8-6.5 8-12V5z"/>',
        'headset' => '<path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/>',
        'zap' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        'refresh' => '<polyline points="1 4 1 10 7 10"/><polyline points="23 20 23 14 17 14"/><path d="M3.5 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.65 4.36A9 9 0 0 0 20.5 15"/>',
        'plus' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'shopping-basket' => '<path d="M5 10l1.5-5h11L19 10"/><path d="M3 10h18l-1.5 9h-15z"/><path d="M8 14h8"/>',
        'shirt' => '<path d="M16 3l4 4-3 3-2-2v11H9V8L7 10 4 7l4-4 4 2z"/>',
        'smartphone' => '<rect x="6" y="2" width="12" height="20" rx="2"/><line x1="10" y1="19" x2="14" y2="19"/>',
        'sofa' => '<path d="M4 13V8a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v5"/><path d="M2 13h20v5H2z"/><path d="M4 18v2M20 18v2"/>',
        'cross' => '<path d="M12 3v18M3 12h18" stroke-width="3"/>',
        'wheat' => '<path d="M12 22V8"/><path d="M8 6l4-4 4 4M8 12l4-4 4 4M9 18h6"/>',
        'droplet' => '<path d="M12 2s7 8 7 13a7 7 0 0 1-14 0c0-5 7-13 7-13z"/>',
        'footprints' => '<circle cx="8" cy="8" r="2"/><circle cx="16" cy="16" r="2"/><path d="M6 12v6M18 6v6"/>',
        'spray-can' => '<path d="M3 22V10h6v12z"/><path d="M6 10V6a2 2 0 0 1 2-2h1"/><path d="M13 4h2M13 8h4M13 12h5"/>',
        'store' => '<path d="M3 9l1-5h16l1 5"/><path d="M3 9h18v11H3z"/><path d="M9 20v-6h6v6"/>',
        'drama' => '<circle cx="9" cy="10" r="6"/><circle cx="15" cy="14" r="6"/>',
        'road' => '<path d="M6 3 3 21M18 3l3 18M11 3h2l-1 6-1-6zM10.5 13h3l-1 8-1-8z"/>',
        'building-2' => '<path d="M4 22V4a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v18"/><path d="M14 9h5a1 1 0 0 1 1 1v12"/><path d="M7 7h1M7 11h1M7 15h1M11 7h1M11 11h1M11 15h1"/>',
        'star-filled' => '<polygon fill="currentColor" points="12 2 15.1 8.3 22 9.3 17 14.1 18.2 21 12 17.8 5.8 21 7 14.1 2 9.3 8.9 8.3"/>',
        'star-half' => '<defs><linearGradient id="halfgrad"><stop offset="50%" stop-color="currentColor"/><stop offset="50%" stop-color="transparent"/></linearGradient></defs><polygon fill="url(#halfgrad)" stroke="currentColor" points="12 2 15.1 8.3 22 9.3 17 14.1 18.2 21 12 17.8 5.8 21 7 14.1 2 9.3 8.9 8.3"/>',
        'star-empty' => '<polygon fill="none" stroke="currentColor" points="12 2 15.1 8.3 22 9.3 17 14.1 18.2 21 12 17.8 5.8 21 7 14.1 2 9.3 8.9 8.3"/>',
        'facebook' => '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>',
        'instagram' => '<rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><line x1="17.5" y1="6.5" x2="17.5" y2="6.5"/>',
        'twitter' => '<path d="M23 3a10.9 10.9 0 0 1-3.1 1.5 4.5 4.5 0 0 0-7.9 3v1A10.7 10.7 0 0 1 3 4s-4 9 5 13a11.6 11.6 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.1-1A7.7 7.7 0 0 0 23 3z"/>',
        'whatsapp' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
        'tiktok' => '<path d="M14 3v11.5a3.5 3.5 0 1 1-3-3.46"/><path d="M14 7a4 4 0 0 0 4 4"/>',
        'youtube' => '<path d="M22.5 12s0-3.2-.4-4.7a2.5 2.5 0 0 0-1.8-1.8C18.8 5 12 5 12 5s-6.8 0-8.3.5a2.5 2.5 0 0 0-1.8 1.8C1.5 8.8 1.5 12 1.5 12s0 3.2.4 4.7a2.5 2.5 0 0 0 1.8 1.8c1.5.5 8.3.5 8.3.5s6.8 0 8.3-.5a2.5 2.5 0 0 0 1.8-1.8c.4-1.5.4-4.7.4-4.7z"/><polygon points="10 9 16 12 10 15" fill="currentColor" stroke="none"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'bar-chart' => '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>',
        'send' => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
    ];

    $body = $paths[$name] ?? $paths['check'];
    $strokeFill = str_contains($name, 'star') ? '' : 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';

    return '<svg class="icon icon-' . htmlspecialchars($name) . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" ' . $strokeFill . ' aria-hidden="true">' . $body . '</svg>';
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Ajoute un parametre ?v=<date de modification> a un asset statique
 * (CSS/JS) pour forcer le navigateur a recharger la derniere version des
 * qu'elle change, sans avoir a demander aux visiteurs de vider leur cache
 * a chaque mise a jour du site.
 */
function asset_url(string $webPath): string
{
    $absolutePath = __DIR__ . '/../' . ltrim($webPath, '/');
    $version = is_file($absolutePath) ? (string) filemtime($absolutePath) : '1';

    return '/market/' . ltrim($webPath, '/') . '?v=' . $version;
}

function slugify(string $value): string
{
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

    return trim($value, '-');
}

function get_setting(string $key, string $default = ''): string
{
    static $settings = null;

    if ($settings === null) {
        $settings = get_db()->query('SELECT `key`, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    return $settings[$key] ?? $default;
}

/**
 * Reseaux sociaux configurables depuis admin/parametres.php (settings
 * social_{cle}_url / social_{cle}_enabled). Un reseau n'apparait que s'il
 * est actif ET qu'une URL a ete renseignee — evite les liens "#" morts
 * qui existaient avant en dur dans includes/footer.php.
 */
function social_networks(): array
{
    $networks = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'twitter' => 'X (Twitter)',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
    ];

    $result = [];
    foreach ($networks as $key => $label) {
        $url = get_setting('social_' . $key . '_url');
        $enabled = get_setting('social_' . $key . '_enabled') === '1';
        if ($enabled && $url !== '') {
            $result[$key] = ['label' => $label, 'url' => $url];
        }
    }

    return $result;
}

/**
 * Traduit le code d'action stocke dans audit_logs.action en phrase lisible
 * (a la 3e personne, prefixee par le nom de l'admin dans get_recent_activity()).
 * Couvre a la fois les actions historiques (vendor_suspended, vendor_reactivated,
 * wallet_adjustment - deja enregistrees par VendorAdminService) et les
 * nouvelles actions journalisees ici.
 */
function audit_action_label(string $action): string
{
    return [
        'vendor_suspended' => 'a suspendu le vendeur',
        'vendor_reactivated' => 'a réactivé le vendeur',
        'wallet_adjustment' => 'a ajusté manuellement le portefeuille du vendeur',
        'shop_approved' => 'a approuvé la boutique',
        'shop_rejected' => 'a refusé la demande de boutique',
        'refund_processed' => 'a traité un remboursement pour la commande',
        'withdrawal_approved' => 'a approuvé le retrait',
        'withdrawal_rejected' => 'a rejeté le retrait',
        'withdrawal_processing' => 'a marqué le retrait en cours de paiement',
        'withdrawal_completed' => 'a marqué le retrait comme payé',
        'withdrawal_failed' => 'a marqué le retrait comme échoué',
        'withdrawal_reversed' => 'a annulé/recrédité le retrait',
        'order_processing' => 'a mis la commande en préparation',
        'order_shipping' => 'a mis la commande en livraison',
        'order_delivered' => 'a marqué la commande comme livrée',
        'order_cancelled' => 'a annulé la commande',
        'order_not_collected' => 'a marqué la commande comme non retirée',
        'order_pending' => 'a remis la commande en attente',
        'user_blocked' => "a bloqué l'utilisateur",
        'user_unblocked' => "a débloqué l'utilisateur",
    ][$action] ?? ('a effectué "' . $action . '" sur');
}

/**
 * Nom lisible de l'entite ciblee par une ligne audit_logs, pour l'affichage
 * dans le journal d'activite (plutot qu'un simple identifiant numerique).
 * Repli sur "#id" si l'entite a ete supprimee depuis ou si le type est
 * inconnu — ne doit jamais faire echouer l'affichage du journal.
 */
function audit_entity_label(string $entityType, int $entityId): string
{
    $db = get_db();
    $name = match ($entityType) {
        'shop' => (function () use ($db, $entityId) {
            $stmt = $db->prepare('SELECT name FROM shops WHERE id = :id');
            $stmt->execute(['id' => $entityId]);
            return $stmt->fetchColumn();
        })(),
        'vendor' => (function () use ($db, $entityId) {
            $stmt = $db->prepare('SELECT business_name FROM vendors WHERE id = :id');
            $stmt->execute(['id' => $entityId]);
            return $stmt->fetchColumn();
        })(),
        'user' => (function () use ($db, $entityId) {
            $stmt = $db->prepare('SELECT name FROM users WHERE id = :id');
            $stmt->execute(['id' => $entityId]);
            return $stmt->fetchColumn();
        })(),
        default => false,
    };

    return $name !== false && $name !== null ? (string) $name . " (#{$entityId})" : "#{$entityId}";
}

function get_recent_activity(int $perTypeLimit = 10): array
{
    $db = get_db();
    $activity = [];

    $stmt = $db->prepare("SELECT name, created_at FROM contact_messages WHERE subject = 'Commande' ORDER BY created_at DESC LIMIT :n");
    $stmt->bindValue('n', $perTypeLimit, PDO::PARAM_INT);
    $stmt->execute();
    foreach ($stmt as $row) {
        $activity[] = ['type' => 'commande', 'icon' => 'cart', 'text' => 'Nouvelle commande de ' . $row['name'], 'time' => $row['created_at']];
    }

    $stmt = $db->prepare("SELECT name, created_at FROM contact_messages WHERE subject != 'Commande' ORDER BY created_at DESC LIMIT :n");
    $stmt->bindValue('n', $perTypeLimit, PDO::PARAM_INT);
    $stmt->execute();
    foreach ($stmt as $row) {
        $activity[] = ['type' => 'message', 'icon' => 'send', 'text' => 'Message reçu de ' . $row['name'], 'time' => $row['created_at']];
    }

    $stmt = $db->prepare('SELECT name, created_at FROM users ORDER BY created_at DESC LIMIT :n');
    $stmt->bindValue('n', $perTypeLimit, PDO::PARAM_INT);
    $stmt->execute();
    foreach ($stmt as $row) {
        $activity[] = ['type' => 'utilisateur', 'icon' => 'user', 'text' => 'Nouvel utilisateur : ' . $row['name'], 'time' => $row['created_at']];
    }

    $stmt = $db->prepare('SELECT name, created_at FROM products ORDER BY id DESC LIMIT :n');
    $stmt->bindValue('n', $perTypeLimit, PDO::PARAM_INT);
    $stmt->execute();
    foreach ($stmt as $row) {
        $activity[] = ['type' => 'produit', 'icon' => 'shopping-basket', 'text' => 'Produit ajouté : ' . $row['name'], 'time' => $row['created_at']];
    }

    $stmt = $db->prepare('
        SELECT al.action, al.entity_type, al.entity_id, al.reason, al.created_at, u.name AS admin_name
        FROM audit_logs al
        JOIN users u ON u.id = al.admin_user_id
        ORDER BY al.created_at DESC
        LIMIT :n
    ');
    $stmt->bindValue('n', $perTypeLimit, PDO::PARAM_INT);
    $stmt->execute();
    foreach ($stmt as $row) {
        // Texte brut, non echappe : admin/activite.php applique e() une seule
        // fois au rendu (comme pour les autres types d'activite ci-dessus).
        $text = $row['admin_name'] . ' ' . audit_action_label($row['action']) . ' ' . audit_entity_label($row['entity_type'], (int) $row['entity_id']);
        if ($row['reason']) {
            $text .= ' — ' . $row['reason'];
        }
        $activity[] = ['type' => 'admin', 'icon' => 'shield', 'text' => $text, 'time' => $row['created_at']];
    }

    usort($activity, fn ($a, $b) => strtotime((string) $b['time']) <=> strtotime((string) $a['time']));

    return $activity;
}

/**
 * Vrai si l'utilisateur a une commande dont un article de ce produit a ete
 * livre (meme critere que le badge "Achat verifie" affiche sur les avis).
 * Utilise pour exiger un achat livre avant de pouvoir laisser un avis.
 */
function user_has_delivered_purchase(int $userId, int $productId): bool
{
    $stmt = get_db()->prepare("
        SELECT 1 FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE oi.product_id = :product_id AND o.customer_user_id = :user_id AND oi.fulfillment_status = 'delivered'
        LIMIT 1
    ");
    $stmt->execute(['product_id' => $productId, 'user_id' => $userId]);

    return (bool) $stmt->fetchColumn();
}

function recompute_product_rating(int $productId): void
{
    $db = get_db();
    $stmt = $db->prepare('SELECT COALESCE(AVG(rating), 0) AS avg_rating, COUNT(*) AS total FROM reviews WHERE product_id = :id');
    $stmt->execute(['id' => $productId]);
    $row = $stmt->fetch();

    $stmt = $db->prepare('UPDATE products SET rating = :rating, review_count = :count WHERE id = :id');
    $stmt->execute([
        'rating' => round((float) $row['avg_rating'], 1),
        'count' => (int) $row['total'],
        'id' => $productId,
    ]);
}

function product_thumb_html(array $product, int $iconSize = 34): string
{
    if (!empty($product['image'])) {
        return '<img src="/market/' . e((string) $product['image']) . '" alt="' . e((string) $product['name']) . '" loading="lazy">';
    }

    return icon((string) $product['icon'], $iconSize);
}

/**
 * Photo principale (products.image, inchangee) suivie des photos
 * supplementaires facultatives (product_images, triees par sort_order) —
 * utilisee par la galerie cliquable de la fiche produit publique. Un
 * produit sans aucune photo renvoie un tableau vide (repli sur l'icone,
 * comme avant l'ajout de cette fonctionnalite).
 */
function product_gallery_images(array $product): array
{
    $images = [];
    if (!empty($product['image'])) {
        $images[] = (string) $product['image'];
    }

    $stmt = get_db()->prepare('SELECT image FROM product_images WHERE product_id = :id ORDER BY sort_order, id');
    $stmt->execute(['id' => (int) $product['id']]);
    foreach ($stmt->fetchAll() as $row) {
        $images[] = (string) $row['image'];
    }

    return $images;
}

function shop_logo_html(array $shop): string
{
    if (!empty($shop['logo'])) {
        return '<img src="/market/' . e((string) $shop['logo']) . '" alt="' . e((string) $shop['name']) . '" loading="lazy">';
    }

    return e((string) $shop['logo_letter']);
}

/**
 * Lien Google Maps fonctionnel pour localiser une boutique. Precis
 * (coordonnees GPS) si l'admin/le vendeur les a renseignees, sinon repli
 * automatique sur une recherche par nom + quartier (toujours disponible,
 * puisque le quartier est un champ obligatoire) — jamais de lien mort.
 */
function shop_google_maps_url(array $shop): string
{
    $lat = $shop['lat'] ?? null;
    $lng = $shop['lng'] ?? null;

    if ($lat !== null && $lng !== null && $lat !== '' && $lng !== '') {
        return 'https://www.google.com/maps?q=' . rawurlencode((string) $lat . ',' . (string) $lng);
    }

    $query = trim($shop['name'] . ', ' . $shop['neighborhood'] . ', Man, Côte d\'Ivoire');

    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query);
}
