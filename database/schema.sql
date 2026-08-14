-- ManMarket - schema + donnees de demo
-- A importer via phpMyAdmin ou: mysql -u root < schema.sql

CREATE DATABASE IF NOT EXISTS manmarket CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE manmarket;

-- ---------------------------------------------------------------
-- ENGINE=InnoDB est precise explicitement sur chaque table : le
-- storage engine par defaut de ce serveur peut etre MyISAM, qui
-- accepte silencieusement la syntaxe FOREIGN KEY sans jamais
-- l'appliquer (aucune integrite referentielle, aucune erreur).

CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    icon VARCHAR(50) NOT NULL,
    color VARCHAR(20) NOT NULL DEFAULT '#16a34a',
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE shops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NULL,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(150) NOT NULL UNIQUE,
    neighborhood VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    whatsapp VARCHAR(30) NULL,
    logo_letter VARCHAR(2) NOT NULL,
    logo VARCHAR(255) NULL,
    color VARCHAR(20) NOT NULL DEFAULT '#16a34a',
    rating DECIMAL(2,1) NOT NULL DEFAULT 5.0,
    review_count INT NOT NULL DEFAULT 0,
    is_open TINYINT(1) NOT NULL DEFAULT 1,
    fast_delivery TINYINT(1) NOT NULL DEFAULT 0,
    lat DECIMAL(10,6) NULL,
    lng DECIMAL(10,6) NULL,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    shop_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(150) NOT NULL UNIQUE,
    description TEXT NULL,
    price INT NOT NULL,
    original_price INT NULL,
    stock INT NOT NULL DEFAULT 0,
    rating DECIMAL(2,1) NOT NULL DEFAULT 0,
    review_count INT NOT NULL DEFAULT 0,
    icon VARCHAR(50) NOT NULL DEFAULT 'box',
    image VARCHAR(255) NULL,
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id),
    FOREIGN KEY (shop_id) REFERENCES shops(id)
) ENGINE=InnoDB;

CREATE TABLE news (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    excerpt VARCHAR(255) NOT NULL,
    event_day VARCHAR(2) NOT NULL,
    event_month VARCHAR(10) NOT NULL,
    icon VARCHAR(50) NOT NULL DEFAULT 'calendar',
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(30) NULL,
    password_hash VARCHAR(255) NOT NULL,
    avatar VARCHAR(255) NULL,
    is_vendor TINYINT(1) NOT NULL DEFAULT 0,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    is_blocked TINYINT(1) NOT NULL DEFAULT 0,
    blocked_reason VARCHAR(255) NULL,
    last_login_at TIMESTAMP NULL,
    last_activity_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Historique des connexions (une ligne par connexion reussie).
CREATE TABLE login_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_login_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- shops precede users dans ce fichier : la FK owner_id est ajoutee ici
-- (apres la creation de users) plutot qu'inline dans CREATE TABLE shops.
ALTER TABLE shops ADD CONSTRAINT fk_shops_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE contact_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    user_id INT NULL,
    shop_id INT NULL,
    subject VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    delivery_location VARCHAR(255) NULL,
    -- 'pending'/'processing'/'shipping'/'delivered'/'cancelled' forment le
    -- cycle de vie d'une commande (subject='Commande') ; 'processed' est
    -- utilise pour les messages et reclamations (simple traite/non traite).
    status ENUM('pending','processing','shipping','delivered','cancelled','processed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_contact_messages_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Localites de livraison : villes (parent_id NULL) et quartiers
-- (parent_id = id de la ville). Geree par l'admin (nom reel, activable).
CREATE TABLE locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    parent_id INT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_locations_parent FOREIGN KEY (parent_id) REFERENCES locations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NULL,
    shop_id INT NULL,
    product_name VARCHAR(150) NOT NULL,
    unit_price INT NOT NULL,
    quantity INT NOT NULL,
    vendor_status ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES contact_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    CONSTRAINT fk_order_items_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    reference VARCHAR(50) NOT NULL,
    amount INT NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'XOF',
    status ENUM('pending','processing','completed','failed','cancelled','expired','refunded') NOT NULL DEFAULT 'pending',
    payment_method VARCHAR(30) NULL,
    environment ENUM('sandbox','live') NOT NULL,
    payment_url VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uniq_payments_reference (reference),
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES contact_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE subscription_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    plan_id INT NOT NULL,
    reference VARCHAR(50) NOT NULL,
    amount INT NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'XOF',
    status ENUM('pending','processing','completed','failed','cancelled','expired','refunded') NOT NULL DEFAULT 'pending',
    payment_method VARCHAR(30) NULL,
    environment ENUM('sandbox','live') NOT NULL,
    payment_url VARCHAR(500) NULL,
    applied TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uniq_subscription_payments_reference (reference),
    CONSTRAINT fk_subscription_payments_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    CONSTRAINT fk_subscription_payments_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans(id)
) ENGINE=InnoDB;

CREATE TABLE withdrawal_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    amount INT NOT NULL,
    phone VARCHAR(30) NOT NULL,
    status ENUM('pending','approved','rejected','paid') NOT NULL DEFAULT 'pending',
    admin_note VARCHAR(255) NULL,
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    CONSTRAINT fk_withdrawal_requests_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE settings (
    `key` VARCHAR(100) NOT NULL PRIMARY KEY,
    value TEXT NOT NULL
) ENGINE=InnoDB;

CREATE TABLE reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    user_id INT NULL,
    name VARCHAR(150) NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comment TEXT NULL,
    vendor_reply TEXT NULL,
    vendor_reply_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uniq_product_user (product_id, user_id),
    CONSTRAINT fk_reviews_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

CREATE TABLE promotions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    discount_percent TINYINT UNSIGNED NOT NULL,
    scope ENUM('all','category') NOT NULL DEFAULT 'all',
    category_id INT NULL,
    starts_at DATE NOT NULL,
    ends_at DATE NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_promotions_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    CONSTRAINT chk_promotions_discount CHECK (discount_percent BETWEEN 1 AND 99),
    CONSTRAINT chk_promotions_dates CHECK (ends_at >= starts_at)
) ENGINE=InnoDB;

CREATE TABLE subscription_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    duration_months INT NOT NULL,
    price INT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- Historique des paiements d'abonnement d'une boutique (enregistres
-- manuellement par l'admin, paiement recu hors-ligne). Une boutique
-- est visible sur le site public seulement si CURDATE() est compris
-- entre starts_at et ends_at d'une ligne de cette table.
CREATE TABLE shop_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT NOT NULL,
    plan_id INT NULL,
    plan_name VARCHAR(100) NOT NULL,
    price_paid INT NOT NULL,
    starts_at DATE NOT NULL,
    ends_at DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_shop_subscriptions_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    CONSTRAINT fk_shop_subscriptions_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE advertisements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    image VARCHAR(255) NOT NULL,
    link_url VARCHAR(255) NULL,
    starts_at DATE NOT NULL,
    ends_at DATE NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_ads_dates CHECK (ends_at >= starts_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
INSERT INTO categories (name, slug, icon, color, sort_order) VALUES
('Alimentation', 'alimentation', 'shopping-basket', '#16a34a', 1),
('Mode & Beauté', 'mode-beaute', 'shirt', '#db2777', 2),
('Électronique', 'electronique', 'smartphone', '#2563eb', 3),
('Maison & Bureau', 'maison-bureau', 'sofa', '#d97706', 4),
('Santé & Pharmacie', 'sante-pharmacie', 'cross', '#dc2626', 5);

INSERT INTO shops (name, slug, neighborhood, logo_letter, color, rating, review_count, is_open, fast_delivery, lat, lng, sort_order) VALUES
('Boutique Amani', 'boutique-amani', 'Madina, Man', 'BA', '#16a34a', 4.6, 120, 1, 1, 7.4128, -7.5536, 1),
('Electro Man', 'electro-man', 'Dioulabougou, Man', 'EM', '#2563eb', 4.4, 96, 1, 0, 7.4180, -7.5590, 2),
('Chez Fatou', 'chez-fatou', 'Liberté, Man', 'CF', '#db2777', 4.7, 150, 1, 1, 7.4090, -7.5480, 3),
('Pharmacie la Grâce', 'pharmacie-la-grace', 'Centre-ville, Man', 'PG', '#dc2626', 4.5, 110, 1, 0, 7.4145, -7.5525, 4);

-- rating/review_count demarrent a 0 : ils sont recalcules a partir
-- de la table reviews des qu'un avis reel est depose (voir
-- recompute_product_rating() dans includes/functions.php).
INSERT INTO products (category_id, shop_id, name, slug, price, original_price, stock, rating, review_count, icon, is_featured, sort_order) VALUES
(1, 3, 'Riz local 25kg', 'riz-local-25kg', 12000, 14000, 25, 0, 0, 'wheat', 1, 1),
(1, 1, 'Huile de palme 5L', 'huile-de-palme-5l', 4000, 6000, 40, 0, 0, 'droplet', 1, 2),
(3, 2, 'Smartphone Infinix', 'smartphone-infinix', 115000, 130000, 8, 0, 0, 'smartphone', 1, 3),
(2, 1, 'Chaussures Nike Air', 'chaussures-nike-air', 35000, 40000, 15, 0, 0, 'footprints', 1, 4),
(2, 3, 'Parfum Miracle 100ml', 'parfum-miracle-100ml', 18000, 23000, 0, 0, 0, 'spray-can', 1, 5);

INSERT INTO news (title, excerpt, event_day, event_month, icon, sort_order) VALUES
('Foire commerciale de Man 2025', 'Venez découvrir les meilleures offres locales.', '20', 'Mars', 'store', 1),
('Journée de la culture Mano', 'Célébrons notre culture et nos traditions.', '15', 'Mars', 'drama', 2),
('Nouvelles routes et développement', 'Les grands projets de la ville de Man.', '10', 'Mars', 'road', 3),
('Ouverture du nouveau marché moderne', 'Un nouvel espace moderne pour mieux vous servir.', '05', 'Mars', 'building-2', 4);

INSERT INTO subscription_plans (name, duration_months, price, is_active, sort_order) VALUES
('1 an', 12, 25000, 1, 1),
('2 ans', 24, 45000, 1, 2);

-- Abonnement initial (1 an, a partir d'aujourd'hui) pour que les boutiques
-- de demonstration restent visibles sur une installation fraiche.
INSERT INTO shop_subscriptions (shop_id, plan_id, plan_name, price_paid, starts_at, ends_at)
SELECT id, 1, '1 an', 25000, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR) FROM shops;

-- Localites de livraison : principales villes de Cote d'Ivoire, avec
-- les quartiers de Man en sous-localites (Man est la ville du marche).
INSERT INTO locations (name, parent_id, is_active, sort_order) VALUES
('Man', NULL, 1, 1),
('Abidjan', NULL, 1, 2),
('Yamoussoukro', NULL, 1, 3),
('Bouaké', NULL, 1, 4),
('Daloa', NULL, 1, 5),
('San-Pédro', NULL, 1, 6),
('Korhogo', NULL, 1, 7),
('Gagnoa', NULL, 1, 8),
('Divo', NULL, 1, 9),
('Abengourou', NULL, 1, 10),
('Agboville', NULL, 1, 11),
('Grand-Bassam', NULL, 1, 12),
('Bondoukou', NULL, 1, 13),
('Séguéla', NULL, 1, 14),
('Odienné', NULL, 1, 15),
('Ferkessédougou', NULL, 1, 16),
('Bouaflé', NULL, 1, 17),
('Soubré', NULL, 1, 18),
('Aboisso', NULL, 1, 19),
('Dabou', NULL, 1, 20),
('Toumodi', NULL, 1, 21),
('Guiglo', NULL, 1, 22);

INSERT INTO locations (name, parent_id, is_active, sort_order)
SELECT n.name, l.id, 1, n.sort_order
FROM locations l
JOIN (
    SELECT 'Madina' AS name, 1 AS sort_order
    UNION ALL SELECT 'Dioulabougou', 2
    UNION ALL SELECT 'Liberté', 3
    UNION ALL SELECT 'Centre-ville', 4
) n
WHERE l.name = 'Man' AND l.parent_id IS NULL;

INSERT INTO settings (`key`, value) VALUES
('site_name', 'ManMarket'),
('site_address', 'Centre-ville, Man, Côte d''Ivoire'),
('site_phone', '+225 07 00 00 00 00'),
('site_whatsapp', '225700000000'),
('site_email', 'contact@manmarket.ci'),
('site_support_hours', 'Disponible 24/7');
