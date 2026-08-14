-- ============================================================================
-- PROPOSITION DE SCHEMA - Architecture portefeuille vendeur / Genius Pay
-- NON APPLIQUE A LA BASE DE PRODUCTION - en attente de validation.
--
-- Regles generales :
--   - Toutes les colonnes monetaires sont en DECIMAL(12,2), jamais FLOAT/DOUBLE.
--   - Toutes les tables sont InnoDB (transactions + FK).
--   - Toute reference externe (Genius Pay) qui doit garantir l'idempotence
--     porte une contrainte UNIQUE explicite.
--
-- COMMISSION : la marketplace ne prend actuellement AUCUNE commission (modele
-- economique = abonnement boutique, deja en place). Le taux applique est lu
-- depuis la table `settings` existante (cle 'marketplace_commission_rate',
-- valeur par defaut '0.00') au moment de CHAQUE commande, puis fige dans
-- order_items.commission_rate. Aucune commission n'est jamais codee en dur :
-- si ce taux change un jour, seule la valeur en base change, aucune migration
-- de schema n'est necessaire.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- VENDORS
-- Represente l'identite "vendeur" au sens financier, distincte de la boutique
-- (une boutique appartient a un vendeur ; un wallet appartient a un vendeur,
-- pas a une boutique, pour permettre un jour a un vendeur de gerer plusieurs
-- boutiques sur un solde unique).
-- ----------------------------------------------------------------------------
CREATE TABLE vendors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    business_name VARCHAR(150) NOT NULL,
    status ENUM('active','suspended') NOT NULL DEFAULT 'active',
    suspended_reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uniq_vendors_user (user_id),
    CONSTRAINT fk_vendors_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- shops.vendor_id vient s'ajouter (ou remplacer) shops.owner_id existant.
-- ALTER TABLE shops ADD COLUMN vendor_id INT NULL AFTER owner_id;
-- ALTER TABLE shops ADD CONSTRAINT fk_shops_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL;

-- ----------------------------------------------------------------------------
-- ORDERS
-- Remplace l'usage actuel de contact_messages (subject='Commande') par une
-- vraie table dediee. contact_messages redevient un simple formulaire de
-- contact/support.
-- ----------------------------------------------------------------------------
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_user_id INT NULL,
    customer_name VARCHAR(150) NOT NULL,
    customer_email VARCHAR(190) NOT NULL,
    customer_phone VARCHAR(30) NULL,
    delivery_location VARCHAR(255) NULL,
    subtotal_amount DECIMAL(12,2) NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'XOF',
    fulfillment_status ENUM('pending','processing','shipping','delivered','cancelled') NOT NULL DEFAULT 'pending',
    payment_status ENUM('unpaid','pending','paid','partially_refunded','refunded','failed') NOT NULL DEFAULT 'unpaid',
    payment_choice ENUM('cod','online') NOT NULL DEFAULT 'cod',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL,
    CONSTRAINT fk_orders_customer FOREIGN KEY (customer_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_orders_payment_status (payment_status),
    INDEX idx_orders_fulfillment_status (fulfillment_status)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- ORDER_ITEMS
-- Une ligne = un produit d'une boutique donnee dans une commande.
-- Tout est fige au moment de la commande (unit_price, subtotal, commission_rate)
-- -> une commande passee reste exacte meme si le vendeur change son prix plus tard.
--
-- wallet_credited protege contre un double credit meme en cas de bug applicatif
-- (deuxieme filet de securite en plus de webhook_events.event_id UNIQUE et de la
-- contrainte UNIQUE sur wallet_transactions).
--
-- 3 statuts INDEPENDANTS par ligne (une commande multi-boutiques peut avoir un
-- item livre chez A et un autre encore en attente chez B) :
--   - fulfillment_status : suivi logistique cote vendeur/admin
--   - payment_status     : reflet du paiement de CETTE ligne (toute la commande
--                          est payee en un seul versement Genius Pay, donc en
--                          pratique toutes les lignes passent 'paid' ensemble,
--                          sauf si un remboursement partiel ne touche qu'une ligne)
--   - refund_status      : aucun remboursement / partiel / total, sur CETTE ligne
-- ----------------------------------------------------------------------------
CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NULL,
    shop_id INT NOT NULL,
    vendor_id INT NOT NULL,
    product_name VARCHAR(150) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    quantity INT NOT NULL,
    subtotal DECIMAL(12,2) NOT NULL COMMENT 'unit_price * quantity, fige a la commande',
    commission_rate DECIMAL(5,2) NOT NULL COMMENT 'taux fige au moment de la commande',
    commission_amount DECIMAL(12,2) NOT NULL COMMENT 'subtotal * commission_rate',
    vendor_net_amount DECIMAL(12,2) NOT NULL COMMENT 'subtotal - commission_amount',
    fulfillment_status ENUM('pending','confirmed','rejected','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending',
    payment_status ENUM('unpaid','paid','refunded','partially_refunded') NOT NULL DEFAULT 'unpaid',
    refund_status ENUM('none','requested','partial','full') NOT NULL DEFAULT 'none',
    refunded_quantity INT NOT NULL DEFAULT 0,
    refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    wallet_credited TINYINT(1) NOT NULL DEFAULT 0,
    wallet_credited_at TIMESTAMP NULL,
    delivered_at TIMESTAMP NULL COMMENT 'fixe au moment ou fulfillment_status passe a delivered, sert de point de depart au delai settings.wallet_hold_days',
    wallet_released_at TIMESTAMP NULL COMMENT 'fixe quand pending_balance -> available_balance a ete effectue pour cette ligne (idempotence de WalletReleaseService)',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    CONSTRAINT fk_order_items_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE RESTRICT,
    CONSTRAINT fk_order_items_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE RESTRICT,
    INDEX idx_order_items_vendor (vendor_id),
    INDEX idx_order_items_order_vendor (order_id, vendor_id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- PAYMENTS
-- Un paiement Genius Pay = un montant GLOBAL pour une commande (jamais un
-- paiement par vendeur : Genius Pay n'a aucune notion de split).
-- provider_reference UNIQUE = premiere barriere anti double-traitement.
-- raw_response conserve la reponse brute pour audit/litige.
-- ----------------------------------------------------------------------------
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    provider VARCHAR(30) NOT NULL DEFAULT 'geniuspay',
    provider_reference VARCHAR(60) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'XOF',
    status ENUM('pending','processing','completed','failed','cancelled','expired','refunded') NOT NULL DEFAULT 'pending',
    payment_method VARCHAR(30) NULL,
    environment ENUM('sandbox','live') NOT NULL,
    payment_url VARCHAR(500) NULL,
    raw_response JSON NULL,
    confirmed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uniq_payments_provider_reference (provider, provider_reference),
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- WEBHOOK_EVENTS
-- Deuxieme (et principale) barriere anti double-traitement : event_id UNIQUE.
-- Chaque webhook recu est enregistre AVANT tout traitement metier.
-- ----------------------------------------------------------------------------
CREATE TABLE webhook_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(30) NOT NULL DEFAULT 'geniuspay',
    event_id VARCHAR(100) NOT NULL,
    event_type VARCHAR(60) NOT NULL,
    payload JSON NOT NULL,
    signature_valid TINYINT(1) NOT NULL,
    processed TINYINT(1) NOT NULL DEFAULT 0,
    processed_at TIMESTAMP NULL,
    error_message VARCHAR(500) NULL,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_webhook_events_event_id (provider, event_id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- COMMISSIONS
-- order_items contient deja le "contrat fige" (commission_rate/commission_amount
-- /vendor_net_amount au moment de la commande). Cette table trace en plus le
-- CYCLE DE VIE reel de cette commission : quand a-t-elle ete appliquee (credit
-- effectif du wallet), et de combien a-t-elle ete annulee suite a un ou
-- plusieurs remboursements partiels (reversed_amount peut s'incrementer
-- plusieurs fois si plusieurs remboursements successifs touchent la meme ligne).
-- gross_amount/commission_amount/net_amount sont une COPIE en lecture seule de
-- order_items au moment de l'application -> order_items reste la source unique
-- de verite pour le "contrat", commissions pour "ce qui a ete reellement credite".
-- ----------------------------------------------------------------------------
CREATE TABLE commissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    order_item_id INT NOT NULL,
    vendor_id INT NOT NULL,
    gross_amount DECIMAL(12,2) NOT NULL,
    commission_rate DECIMAL(5,2) NOT NULL,
    commission_amount DECIMAL(12,2) NOT NULL,
    net_amount DECIMAL(12,2) NOT NULL,
    reversed_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('pending','applied','partially_reversed','reversed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    applied_at TIMESTAMP NULL,
    reversed_at TIMESTAMP NULL,
    UNIQUE KEY uniq_commissions_order_item (order_item_id),
    CONSTRAINT fk_commissions_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_commissions_order_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_commissions_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- WALLETS
-- Un wallet par vendeur. Les colonnes de solde sont des CACHES denormalises
-- pour un affichage rapide : la verite absolue reste la somme de
-- wallet_transactions. Un job de reconciliation devra comparer periodiquement
-- les deux (a construire dans une etape ulterieure).
-- ----------------------------------------------------------------------------
CREATE TABLE wallets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT NOT NULL,
    available_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    pending_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_earned DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_withdrawn DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_commission_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_refunded DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency VARCHAR(10) NOT NULL DEFAULT 'XOF',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uniq_wallets_vendor (vendor_id),
    CONSTRAINT fk_wallets_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- WALLET_TRANSACTIONS (le ledger — jamais modifie, jamais supprime)
-- reference_type + reference_id identifient la source (order_item, withdrawal,
-- refund...). La contrainte UNIQUE empeche qu'une meme source cree deux fois
-- le meme type de mouvement (protection idempotence au niveau comptable).
-- ----------------------------------------------------------------------------
CREATE TABLE wallet_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wallet_id INT NOT NULL,
    vendor_id INT NOT NULL,
    type ENUM('SALE','COMMISSION','REFUND','WITHDRAWAL','WITHDRAWAL_REVERSAL','ADJUSTMENT') NOT NULL,
    reference_type VARCHAR(30) NOT NULL,
    reference_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL COMMENT 'signe : positif = credit, negatif = debit',
    balance_before DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    description VARCHAR(255) NOT NULL,
    status ENUM('PENDING','COMPLETED','REVERSED') NOT NULL DEFAULT 'COMPLETED',
    created_by INT NULL COMMENT 'user_id admin si ADJUSTMENT, sinon NULL (systeme)',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_wallet_tx_source (wallet_id, type, reference_type, reference_id),
    CONSTRAINT fk_wallet_tx_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE CASCADE,
    CONSTRAINT fk_wallet_tx_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
    INDEX idx_wallet_tx_wallet_date (wallet_id, created_at)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- WITHDRAWALS
-- ----------------------------------------------------------------------------
CREATE TABLE withdrawals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT NOT NULL,
    wallet_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    fee DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_amount DECIMAL(12,2) NOT NULL,
    payment_method VARCHAR(30) NOT NULL COMMENT 'wave, orange_money, mtn_money, moov_money',
    account_number VARCHAR(30) NOT NULL,
    status ENUM('PENDING','APPROVED','PROCESSING','COMPLETED','REJECTED','FAILED','CANCELLED') NOT NULL DEFAULT 'PENDING',
    reference VARCHAR(60) NOT NULL,
    admin_note VARCHAR(255) NULL,
    processed_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    UNIQUE KEY uniq_withdrawals_reference (reference),
    CONSTRAINT fk_withdrawals_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE RESTRICT,
    CONSTRAINT fk_withdrawals_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE RESTRICT,
    CONSTRAINT fk_withdrawals_admin FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- REFUNDS + REFUND_ITEMS
-- Un remboursement peut toucher UN SEUL vendeur, PLUSIEURS vendeurs, ou UNE
-- SEULE ligne (retour partiel d'un produit) : refunds = l'enveloppe globale
-- (une demande, un motif, un statut), refund_items = le detail ligne par ligne
-- (quel order_item, quelle quantite rendue, quel montant brut/commission/net).
-- C'est refund_items qui pilote precisement WalletService::debit() par vendeur.
-- ----------------------------------------------------------------------------
CREATE TABLE refunds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    payment_id INT NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL COMMENT 'somme des refund_items, calculee serveur',
    reason VARCHAR(255) NOT NULL,
    status ENUM('requested','processing','completed','rejected') NOT NULL DEFAULT 'requested',
    requested_by INT NULL,
    processed_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    CONSTRAINT fk_refunds_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_refunds_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_refunds_requested_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_refunds_processed_by FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE refund_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    refund_id INT NOT NULL,
    order_item_id INT NOT NULL,
    vendor_id INT NOT NULL,
    quantity INT NOT NULL COMMENT 'unites rendues (peut etre < order_items.quantity : retour partiel)',
    refunded_gross_amount DECIMAL(12,2) NOT NULL,
    refunded_commission_amount DECIMAL(12,2) NOT NULL COMMENT 'part de commission annulee, revient a la marketplace',
    refunded_net_amount DECIMAL(12,2) NOT NULL COMMENT 'ce qui est effectivement retire au wallet du vendeur',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_refund_items_refund FOREIGN KEY (refund_id) REFERENCES refunds(id) ON DELETE CASCADE,
    CONSTRAINT fk_refund_items_order_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE RESTRICT,
    CONSTRAINT fk_refund_items_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- SETTLEMENT_FAILURES
-- Si OrderSettlementService::reconcile() detecte une inegalite (total paye !=
-- somme des parts vendeurs + commission), AUCUN credit n'est effectue et
-- l'incident est enregistre ici pour investigation manuelle par l'admin.
-- ----------------------------------------------------------------------------
CREATE TABLE settlement_failures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    payment_id INT NULL,
    expected_total DECIMAL(12,2) NOT NULL COMMENT 'payments.amount confirme par Genius Pay',
    computed_total DECIMAL(12,2) NOT NULL COMMENT 'somme(vendor_net_amount) + somme(commission_amount)',
    difference DECIMAL(12,2) NOT NULL,
    error_message VARCHAR(500) NOT NULL,
    resolved TINYINT(1) NOT NULL DEFAULT 0,
    resolved_by INT NULL,
    resolved_note VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    CONSTRAINT fk_settlement_failures_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_settlement_failures_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- AUDIT_LOGS
-- Trace generale des actions administrateur sensibles (au-dela du seul ledger
-- financier) : blocage vendeur, approbation/rejet retrait, ajustement manuel.
-- ----------------------------------------------------------------------------
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT NOT NULL,
    action VARCHAR(60) NOT NULL,
    entity_type VARCHAR(30) NOT NULL,
    entity_id INT NOT NULL,
    reason VARCHAR(255) NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_logs_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_audit_logs_entity (entity_type, entity_id)
) ENGINE=InnoDB;
