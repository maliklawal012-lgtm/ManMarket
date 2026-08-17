<?php

declare(strict_types=1);

/**
 * Migration consolidee et idempotente de l'architecture portefeuille (Genius Pay
 * + wallets vendeurs). Rejoue exactement les etapes appliquees manuellement
 * pendant le developpement, dans l'ordre, en verifiant a chaque fois si
 * l'etape est deja appliquee avant d'agir — peut etre execute sans risque sur
 * une base fraiche (apres database/schema.sql) OU sur une base deja migree
 * (aucune action si tout est deja en place).
 *
 * Usage CLI : php database/migrate_wallet_v2.php
 *
 * Prerequis : database/schema.sql deja applique (tables users, shops, products,
 * contact_messages, categories, settings, etc.).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI uniquement.');
}

require_once __DIR__ . '/../config/db.php';

$db = get_db();

function migrate_step(string $label, callable $check, callable $apply): void
{
    global $db;
    if ($check($db)) {
        echo "[SKIP] {$label} (deja applique)\n";

        return;
    }
    $apply($db);
    echo "[OK]   {$label}\n";
}

function migrate_table_exists(PDO $db, string $table): bool
{
    // SHOW TABLES ne peut pas etre une requete preparee cote serveur MySQL :
    // echappement manuel via PDO::quote() (identifiants controles en interne
    // par ce script, jamais d'entree utilisateur ici).
    $stmt = $db->query('SHOW TABLES LIKE ' . $db->quote($table));

    return (bool) $stmt->fetch();
}

function migrate_column_exists(PDO $db, string $table, string $column): bool
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
        throw new InvalidArgumentException("Nom de table invalide : {$table}");
    }
    $stmt = $db->query('SHOW COLUMNS FROM ' . $table . ' LIKE ' . $db->quote($column));

    return (bool) $stmt->fetch();
}

function migrate_fk_exists(PDO $db, string $constraintName): bool
{
    $stmt = $db->prepare("
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ");
    $stmt->execute([$constraintName]);

    return (bool) $stmt->fetch();
}

function migrate_setting_exists(PDO $db, string $key): bool
{
    $stmt = $db->prepare('SELECT 1 FROM settings WHERE `key` = ?');
    $stmt->execute([$key]);

    return (bool) $stmt->fetch();
}

// ----------------------------------------------------------------------
// Etape 1 : archiver les anciennes tables order_items/payments (systeme
// contact_messages) pour liberer leurs noms pour la nouvelle architecture.
// ----------------------------------------------------------------------

migrate_step(
    'Renommer order_items -> legacy_order_items',
    fn (PDO $db) => migrate_table_exists($db, 'legacy_order_items') || !migrate_table_exists($db, 'order_items'),
    function (PDO $db) {
        // Si order_items existe deja au format v2 (avec colonne vendor_id), ne pas ecraser.
        if (migrate_column_exists($db, 'order_items', 'vendor_id')) {
            throw new RuntimeException('order_items existe deja au format v2 — migration deja effectuee autrement, verifier manuellement.');
        }
        $db->exec('RENAME TABLE order_items TO legacy_order_items');
    }
);

migrate_step(
    'Renommer payments -> legacy_payments',
    fn (PDO $db) => migrate_table_exists($db, 'legacy_payments') || !migrate_table_exists($db, 'payments'),
    function (PDO $db) {
        if (migrate_column_exists($db, 'payments', 'provider_reference')) {
            throw new RuntimeException('payments existe deja au format v2 — migration deja effectuee autrement, verifier manuellement.');
        }
        $db->exec('RENAME TABLE payments TO legacy_payments');
    }
);

// ----------------------------------------------------------------------
// Etape 2 : renommer les contraintes FK des tables legacy (le nom de
// contrainte reste apres RENAME TABLE, il faut liberer les noms pour les
// nouvelles tables du meme nom conceptuel).
// ----------------------------------------------------------------------

$legacyFkRenames = [
    ['fk_order_items_order', 'fk_legacy_order_items_order', 'legacy_order_items', 'order_id', 'contact_messages', 'id', 'CASCADE'],
    ['fk_order_items_product', 'fk_legacy_order_items_product', 'legacy_order_items', 'product_id', 'products', 'id', 'SET NULL'],
    ['fk_order_items_shop', 'fk_legacy_order_items_shop', 'legacy_order_items', 'shop_id', 'shops', 'id', 'SET NULL'],
    ['fk_payments_order', 'fk_legacy_payments_order', 'legacy_payments', 'order_id', 'contact_messages', 'id', 'CASCADE'],
];

foreach ($legacyFkRenames as [$old, $new, $table, $col, $refTable, $refCol, $onDelete]) {
    migrate_step(
        "Renommer contrainte {$old} -> {$new}",
        fn (PDO $db) => !migrate_table_exists($db, $table) || migrate_fk_exists($db, $new) || !migrate_fk_exists($db, $old),
        function (PDO $db) use ($old, $new, $table, $col, $refTable, $refCol, $onDelete) {
            $db->exec("ALTER TABLE {$table} DROP FOREIGN KEY {$old}, ADD CONSTRAINT {$new} FOREIGN KEY ({$col}) REFERENCES {$refTable}({$refCol}) ON DELETE {$onDelete}");
        }
    );
}

// ----------------------------------------------------------------------
// Etape 3 : creer les 13 nouvelles tables de l'architecture portefeuille.
// ----------------------------------------------------------------------

migrate_step(
    'Creer les tables wallet v2 (vendors, orders, order_items, payments, ...)',
    fn (PDO $db) => migrate_table_exists($db, 'wallets'),
    function (PDO $db) {
        $sql = file_get_contents(__DIR__ . '/schema_wallet_v2_proposal.sql');
        $db->exec($sql);
    }
);

// ----------------------------------------------------------------------
// Etape 4 : shops.vendor_id (lien boutique -> entite vendeur financiere).
// ----------------------------------------------------------------------

migrate_step(
    'Ajouter shops.vendor_id',
    fn (PDO $db) => migrate_column_exists($db, 'shops', 'vendor_id'),
    function (PDO $db) {
        $db->exec('ALTER TABLE shops ADD COLUMN vendor_id INT NULL AFTER owner_id');
        $db->exec('ALTER TABLE shops ADD CONSTRAINT fk_shops_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL');
    }
);

// ----------------------------------------------------------------------
// Etape 5 : reglages settings (commission marketplace, delai de retenue).
// ----------------------------------------------------------------------

migrate_step(
    "Ajouter le reglage marketplace_commission_rate ('0.00' par defaut)",
    fn (PDO $db) => migrate_setting_exists($db, 'marketplace_commission_rate'),
    fn (PDO $db) => $db->prepare("INSERT INTO settings (`key`, value) VALUES ('marketplace_commission_rate', '0.00')")->execute()
);

migrate_step(
    "Ajouter le reglage wallet_hold_days ('0' par defaut)",
    fn (PDO $db) => migrate_setting_exists($db, 'wallet_hold_days'),
    fn (PDO $db) => $db->prepare("INSERT INTO settings (`key`, value) VALUES ('wallet_hold_days', '0')")->execute()
);

// ----------------------------------------------------------------------
// Etape 6 : colonnes de liberation du solde (order_items.delivered_at,
// wallet_released_at).
// ----------------------------------------------------------------------

migrate_step(
    'Ajouter order_items.delivered_at',
    fn (PDO $db) => migrate_column_exists($db, 'order_items', 'delivered_at'),
    fn (PDO $db) => $db->exec('ALTER TABLE order_items ADD COLUMN delivered_at TIMESTAMP NULL AFTER wallet_credited_at')
);

migrate_step(
    'Ajouter order_items.wallet_released_at',
    fn (PDO $db) => migrate_column_exists($db, 'order_items', 'wallet_released_at'),
    fn (PDO $db) => $db->exec('ALTER TABLE order_items ADD COLUMN wallet_released_at TIMESTAMP NULL AFTER delivered_at')
);

// ----------------------------------------------------------------------
// Etape 7 : table de rate limiting (login/inscription/webhook/retraits).
// ----------------------------------------------------------------------

migrate_step(
    'Creer la table rate_limit_hits',
    fn (PDO $db) => migrate_table_exists($db, 'rate_limit_hits'),
    function (PDO $db) {
        $db->exec("
            CREATE TABLE rate_limit_hits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rl_key VARCHAR(191) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_rate_limit_key_created (rl_key, created_at)
            ) ENGINE=InnoDB
        ");
    }
);

// ----------------------------------------------------------------------
// Etape 8 : tailles produit (vetements/chaussures). products.size_type +
// table product_sizes (stock par taille) + order_items.size (taille
// choisie, historisee independamment d'une modification ulterieure des
// tailles du produit).
// ----------------------------------------------------------------------

migrate_step(
    'Ajouter products.size_type',
    fn (PDO $db) => migrate_column_exists($db, 'products', 'size_type'),
    fn (PDO $db) => $db->exec("ALTER TABLE products ADD COLUMN size_type ENUM('none','clothing','shoe') NOT NULL DEFAULT 'none' AFTER stock")
);

migrate_step(
    'Creer la table product_sizes',
    fn (PDO $db) => migrate_table_exists($db, 'product_sizes'),
    function (PDO $db) {
        $db->exec("
            CREATE TABLE product_sizes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                size VARCHAR(10) NOT NULL,
                stock INT NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                UNIQUE KEY uniq_product_size (product_id, size),
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ");
    }
);

migrate_step(
    'Ajouter order_items.size',
    fn (PDO $db) => migrate_column_exists($db, 'order_items', 'size'),
    fn (PDO $db) => $db->exec('ALTER TABLE order_items ADD COLUMN size VARCHAR(10) NULL AFTER quantity')
);

// ----------------------------------------------------------------------
// Etape 9 : mot de passe oublie. Table password_resets (token a usage
// unique, expirant, seul son hash SHA-256 est stocke).
// ----------------------------------------------------------------------

migrate_step(
    'Creer la table password_resets',
    fn (PDO $db) => migrate_table_exists($db, 'password_resets'),
    function (PDO $db) {
        $db->exec("
            CREATE TABLE password_resets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                used_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_password_resets_token_hash (token_hash),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ");
    }
);

// ----------------------------------------------------------------------
// Etape 10 : 2FA par email pour les comptes admin. Table login_2fa_codes
// (code a 6 chiffres a usage unique, expirant, hash SHA-256 uniquement,
// compteur d'essais pour contrer le brute-force).
// ----------------------------------------------------------------------

migrate_step(
    'Creer la table login_2fa_codes',
    fn (PDO $db) => migrate_table_exists($db, 'login_2fa_codes'),
    function (PDO $db) {
        $db->exec("
            CREATE TABLE login_2fa_codes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                code_hash VARCHAR(64) NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                attempts INT NOT NULL DEFAULT 0,
                used_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ");
    }
);

// ----------------------------------------------------------------------
// Etape 11 : frais de livraison par lieu (locations.delivery_fee) et
// montant reellement facture, historise a la commande
// (orders.delivery_fee_amount).
// ----------------------------------------------------------------------

migrate_step(
    'Ajouter locations.delivery_fee',
    fn (PDO $db) => migrate_column_exists($db, 'locations', 'delivery_fee'),
    fn (PDO $db) => $db->exec('ALTER TABLE locations ADD COLUMN delivery_fee INT NOT NULL DEFAULT 0 AFTER sort_order')
);

migrate_step(
    'Ajouter orders.delivery_fee_amount',
    fn (PDO $db) => migrate_column_exists($db, 'orders', 'delivery_fee_amount'),
    fn (PDO $db) => $db->exec('ALTER TABLE orders ADD COLUMN delivery_fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER subtotal_amount')
);

// ----------------------------------------------------------------------
// Etape 12 : systeme de fiabilite paiement. Un client connecte qui ne
// retire pas sa commande au moins deux fois doit payer en ligne pour
// sa prochaine commande ; il retrouve le choix du paiement a la
// livraison apres deux paiements en ligne reussis consecutifs.
// ----------------------------------------------------------------------

migrate_step(
    'Ajouter users.payment_restricted',
    fn (PDO $db) => migrate_column_exists($db, 'users', 'payment_restricted'),
    fn (PDO $db) => $db->exec('ALTER TABLE users ADD COLUMN payment_restricted TINYINT(1) NOT NULL DEFAULT 0')
);

migrate_step(
    'Ajouter users.failed_pickup_count',
    fn (PDO $db) => migrate_column_exists($db, 'users', 'failed_pickup_count'),
    fn (PDO $db) => $db->exec('ALTER TABLE users ADD COLUMN failed_pickup_count INT NOT NULL DEFAULT 0')
);

migrate_step(
    'Ajouter users.online_payment_streak',
    fn (PDO $db) => migrate_column_exists($db, 'users', 'online_payment_streak'),
    fn (PDO $db) => $db->exec('ALTER TABLE users ADD COLUMN online_payment_streak INT NOT NULL DEFAULT 0')
);

migrate_step(
    "Ajouter 'not_collected' a orders.fulfillment_status",
    function (PDO $db) {
        $col = $db->query("SHOW COLUMNS FROM orders LIKE 'fulfillment_status'")->fetch();
        return $col && str_contains($col['Type'], 'not_collected');
    },
    fn (PDO $db) => $db->exec("ALTER TABLE orders MODIFY COLUMN fulfillment_status ENUM('pending','processing','shipping','delivered','cancelled','not_collected') NOT NULL DEFAULT 'pending'")
);

migrate_step(
    "Ajouter 'not_collected' a order_items.fulfillment_status",
    function (PDO $db) {
        $col = $db->query("SHOW COLUMNS FROM order_items LIKE 'fulfillment_status'")->fetch();
        return $col && str_contains($col['Type'], 'not_collected');
    },
    fn (PDO $db) => $db->exec("ALTER TABLE order_items MODIFY COLUMN fulfillment_status ENUM('pending','confirmed','rejected','shipped','delivered','cancelled','not_collected') NOT NULL DEFAULT 'pending'")
);

// ----------------------------------------------------------------------
// Etape 13 : boutique en libre-service avec validation admin. Une
// boutique creee par un vendeur (vendeur/demande-boutique.php) reste
// invisible et inoperante tant que l'admin ne l'a pas approuvee.
// DEFAULT 'approved' pour que les boutiques existantes (creees par
// l'admin) restent inchangees.
// ----------------------------------------------------------------------

migrate_step(
    'Ajouter shops.approval_status',
    fn (PDO $db) => migrate_column_exists($db, 'shops', 'approval_status'),
    fn (PDO $db) => $db->exec("ALTER TABLE shops ADD COLUMN approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved'")
);

migrate_step(
    'Ajouter shops.rejection_reason',
    fn (PDO $db) => migrate_column_exists($db, 'shops', 'rejection_reason'),
    fn (PDO $db) => $db->exec('ALTER TABLE shops ADD COLUMN rejection_reason VARCHAR(255) NULL')
);

// ----------------------------------------------------------------------
// Etape 14 : verification d'adresse email a l'inscription. Table
// email_verifications (meme patron que password_resets : token a usage
// unique, expirant, seul son hash SHA-256 est stocke). Les comptes
// EXISTANTS sont consideres verifies d'office (backfill sur created_at)
// pour ne pas les bloquer retroactivement ; seuls les NOUVEAUX comptes
// demarrent non verifies.
// ----------------------------------------------------------------------

migrate_step(
    'Ajouter users.email_verified_at',
    fn (PDO $db) => migrate_column_exists($db, 'users', 'email_verified_at'),
    function (PDO $db) {
        $db->exec('ALTER TABLE users ADD COLUMN email_verified_at TIMESTAMP NULL');
        $db->exec('UPDATE users SET email_verified_at = created_at');
    }
);

migrate_step(
    'Creer la table email_verifications',
    fn (PDO $db) => migrate_table_exists($db, 'email_verifications'),
    function (PDO $db) {
        $db->exec("
            CREATE TABLE email_verifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                used_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email_verifications_token_hash (token_hash),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ");
    }
);

// ----------------------------------------------------------------------
// Etape 15 : photos supplementaires (facultatives) par produit, en plus
// de products.image (photo principale, inchangee). Affichees en galerie
// cliquable sur la fiche produit publique, uniquement quand il y en a.
// ----------------------------------------------------------------------

migrate_step(
    'Creer la table product_images',
    fn (PDO $db) => migrate_table_exists($db, 'product_images'),
    function (PDO $db) {
        $db->exec("
            CREATE TABLE product_images (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                image VARCHAR(255) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_product_images_product (product_id),
                CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
            ) ENGINE=InnoDB
        ");
    }
);

echo "\nMigration terminee.\n";
