# Déploiement de ManMarket

Ce document couvre le déploiement de ManMarket sur un nouvel environnement (production, staging, ou reconstruction locale), et les procédures de mise à jour d'un environnement existant.

## 1. Prérequis

- PHP **8.4** avec extensions `pdo_mysql`, `curl`, `mbstring`, `openssl`, `json`
- MySQL/MariaDB **8.0+** (InnoDB requis — voir note dans `database/schema.sql`, un moteur MyISAM par défaut accepterait silencieusement la syntaxe `FOREIGN KEY` sans jamais l'appliquer)
- Apache avec `mod_rewrite` activé (utilisé par `.htaccess`)
- Un compte marchand [Genius Pay](https://geniuspay.ci) actif (clé publique/secrète, environnement `live`)

## 2. Récupérer le code

Copier l'intégralité du répertoire `market/` vers la racine web du serveur cible (ou un sous-dossier, en adaptant les URLs absolues `/market/...` utilisées dans tout le code si le chemin diffère).

## 3. Configuration (`.env`)

```
cp .env.example .env
```

Renseigner :

| Variable | Description |
|---|---|
| `APP_ENV` | `local` en dev, `production` en prod |
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | Connexion MySQL |
| `GENIUSPAY_API_BASE` | `https://geniuspay.ci/api/v1/merchant` (ne pas modifier) |
| `GENIUSPAY_MODE` | `live` (aucun mode sandbox utilisé par ce projet) |
| `GENIUSPAY_PUBLIC_KEY`, `GENIUSPAY_SECRET_KEY` | Clés API du compte marchand Genius Pay |
| `GENIUSPAY_MERCHANT_CODE` | Code marchand |
| `GENIUSPAY_WEBHOOK_SECRET` | Voir étape 8 — obtenu à l'enregistrement du webhook, jamais avant |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_USERNAME`, `SMTP_PASSWORD` | Compte SMTP (ex. Brevo) pour l'envoi d'emails |
| `SMTP_FROM_EMAIL`, `SMTP_FROM_NAME` | Expéditeur affiché sur les emails envoyés |

**`SMTP_*` n'est plus purement optionnel** : tant qu'il n'est pas configuré, `SmtpMailer::isConfigured()` retourne faux et **tout** email applicatif est silencieusement ignoré (juste loggé dans `logs/notifications.log`, jamais envoyé) — y compris le code de vérification en deux étapes (2FA) requis pour toute connexion admin (`admin/connexion.php`) et les liens de réinitialisation de mot de passe (`mot-de-passe-oublie.php`). **Sans SMTP configuré en production, personne ne peut se connecter à l'espace admin.** À vérifier avant toute mise en ligne.

**Ne jamais commiter `.env`** — déjà bloqué en accès web par `.htaccess` (`<FilesMatch "^(\.env.*|...)$">`), mais à garder hors du contrôle de version également.

## 4. Base de données

### 4a. Installation fraîche (nouvel environnement)

```
mysql -u root -p < database/schema.sql
php database/migrate_wallet_v2.php
```

`schema.sql` crée la base `manmarket` et le schéma "historique" (utilisateurs, boutiques, produits, `contact_messages`, etc.). `migrate_wallet_v2.php` applique ensuite, de façon **idempotente**, toute l'architecture portefeuille par-dessus : renommage des anciennes tables `order_items`/`payments` en `legacy_order_items`/`legacy_payments`, création des 13 tables de la nouvelle architecture (`vendors`, `orders`, `order_items`, `payments`, `webhook_events`, `commissions`, `wallets`, `wallet_transactions`, `withdrawals`, `refunds`, `refund_items`, `settlement_failures`, `audit_logs`), ajout de `shops.vendor_id`, des réglages `settings` (`marketplace_commission_rate`, `wallet_hold_days`), des colonnes `order_items.delivered_at`/`wallet_released_at`, et de la table `rate_limit_hits`.

Ce script peut être exécuté plusieurs fois sans risque : chaque étape vérifie son propre état avant d'agir et s'affiche `[SKIP]` si déjà appliquée. Validé sur une base fraîche (32 tables au total) et sur la base de développement déjà migrée (aucune action).

### 4b. Mise à jour d'un environnement déjà en place

```
php database/migrate_wallet_v2.php
```

Seules les étapes manquantes s'appliquent.

### 4c. Contenu de démonstration

`schema.sql` inclut des données de départ minimales (catégories, etc.). **Aucune fausse commande, aucun faux paiement, aucun faux avis n'est jamais inséré** — conforme à la discipline du projet : toute donnée de commande/paiement/wallet doit provenir d'une vraie action utilisateur.

## 5. Configuration Apache

- `DocumentRoot` pointé sur le dossier contenant `market/`, ou vhost dédié avec `DocumentRoot` = `market/` directement (adapter alors les chemins absolus `/market/...` dans le code — recherche/remplace global si nécessaire).
- `AllowOverride All` sur le vhost pour que `.htaccess` soit pris en compte.
- `.htaccess` bloque déjà l'accès direct à `.env*`, `*.sql`, `*.log`, et aux dossiers `src/`, `logs/`, `database/`, `cron/`, `tests/` (exécutés uniquement via `require`/CLI, jamais servis directement).

## 6. Certificats SSL sortants (curl)

Si les appels sortants vers l'API Genius Pay échouent avec `SSL certificate problem: unable to get local issuer certificate`, PHP n'a pas de bundle CA configuré. Vérifier `php.ini` :

```ini
curl.cainfo = "/chemin/vers/cacert.pem"
openssl.cafile = "/chemin/vers/cacert.pem"
```

Un bundle à jour est disponible sur [curl.se/ca/cacert.pem](https://curl.se/ca/cacert.pem). Redémarrer Apache/PHP-FPM après modification.

## 7. Permissions fichiers

Les dossiers suivants doivent être accessibles en écriture par l'utilisateur du serveur web :

```
assets/uploads/avatars/
assets/uploads/shops/
assets/uploads/products/
assets/uploads/ads/
logs/
```

## 8. Enregistrement du webhook Genius Pay

Un seul webhook gère à la fois les commandes (nouvelle architecture wallet) et les abonnements boutique — voir `src/Services/WebhookService.php`.

```bash
php -r '
require "config/autoload.php";
require "config/env.php";
require "config/db.php";
require "config/geniuspay.php";
$gp = App\Services\GeniusPayService::fromEnv();
$r = $gp->registerWebhook(
    "https://VOTRE-DOMAINE/market/api/webhooks/geniuspay.php",
    ["payment.initiated","payment.success","payment.failed","payment.cancelled","payment.refunded","payment.expired"]
);
echo json_encode($r->body, JSON_PRETTY_PRINT);
'
```

La réponse contient un secret `whsec_...` — **retourné une seule fois**. Le copier immédiatement dans `.env` (`GENIUSPAY_WEBHOOK_SECRET`).

Pour changer l'URL d'un webhook déjà enregistré (ex. passage de test à prod) sans perdre son secret, utiliser `updateWebhook(id, [...])` plutôt que d'en recréer un (voir `GeniusPayService::updateWebhook()`, endpoint confirmé `PUT /webhooks/{id}`).

Vérifier la livraison : `POST /webhooks/{id}/test` sur l'API Genius Pay, ou consulter `logs/webhook.log`.

## 9. Tâche planifiée (cron) — libération des soldes vendeurs

```
# crontab -e
0 * * * * /usr/bin/php /chemin/vers/market/cron/release_wallet_holds.php >> /chemin/vers/market/logs/wallet_release_cron.log 2>&1
```

Sous Windows (Planificateur de tâches), déclencheur horaire exécutant :
```
C:\wamp64\bin\php\php8.4.15\php.exe C:\wamp64\www\market\cron\release_wallet_holds.php
```

Ce job bascule `pending_balance` → `available_balance` pour toute commande livrée dont le délai `settings.wallet_hold_days` est écoulé. Idempotent (rejouable sans risque). Le déclenchement horaire est largement suffisant puisque le délai se compte en jours entiers.

## 10. Tâche planifiée (cron) — rappel d'expiration d'abonnement

```
# crontab -e
0 8 * * * /usr/bin/php /chemin/vers/market/cron/notify_expiring_subscriptions.php >> /chemin/vers/market/logs/subscription_reminder_cron.log 2>&1
```

Sous Windows (Planificateur de tâches), déclencheur quotidien exécutant :
```
C:\wamp64\bin\php\php8.4.15\php.exe C:\wamp64\www\market\cron\notify_expiring_subscriptions.php
```

Ce job envoie un email au propriétaire de chaque boutique dont l'abonnement expire dans exactement 3 jours. Une exécution quotidienne suffit (le seuil « = 3 jours » garantit un envoi unique par cycle d'abonnement, jamais de doublon).

## 11. Vérifications post-déploiement (smoke tests)

1. `php -l` sur l'ensemble du projet (aucune erreur de syntaxe) :
   ```
   find . -name "*.php" -not -path "./vendor/*" -exec php -l {} \; | grep -v "No syntax errors"
   ```
2. Suite de tests formelle (15 scénarios paiement/wallet/retrait/remboursement) :
   ```
   php tests/wallet_scenarios.php
   ```
   Doit afficher `15/15 scenarios reussis`. Utilise des données 100% jetables, nettoyées automatiquement — sans impact sur les données réelles.
2bis. Suite de tests HTTP (connexion, recherche, contrôle d'accès admin) — **nécessite que le serveur web soit déjà démarré** (contrairement à la suite ci-dessus, purement en base) :
   ```
   php tests/http_scenarios.php
   ```
   Doit afficher `13/13 scenarios reussis`. Données 100% jetables (préfixe `httptest-`), nettoyées automatiquement.
3. Connexion admin réelle → `/market/admin/connexion.php` (formulaire dédié, distinct de `/market/connexion.php`) avec un compte `is_admin = 1`, saisir le code reçu par email sur `/market/verification-2fa.php`, puis vérifier l'accès à `/market/admin/index.php` et `/market/admin/finances.php`.
4. Un vrai parcours client : ajout panier → `/market/commander.php` → paiement en ligne → vérifier la redirection vers la vraie page de paiement Genius Pay.
5. Vérifier que `GET /market/.env` et `GET /market/database/schema.sql` renvoient bien une erreur 403/404 (pas le contenu du fichier).
6. Remplacer `VOTRE-DOMAINE` par le vrai nom de domaine dans `robots.txt` (ligne `Sitemap:`), puis vérifier que `/market/sitemap.php` renvoie bien du XML valide.

## 12. Modèle de sécurité (RBAC)

Trois rôles, portés par `users.is_admin` / `users.is_vendor` (booléens) :

- **Client** : aucun flag. Accès aux pages publiques + son propre compte (`require_login()`).
- **Vendeur** : `is_vendor = 1`. Accès à `/vendeur/*` (`require_vendor()`). Un vendeur dont l'entité `vendors.status = 'suspended'` (distincte du flag `is_vendor`, gérée par un admin via `/admin/finances.php` → "Suspendre") perd l'accès à **tout** l'espace vendeur (`vendeur/suspendu.php`), pas seulement aux retraits.
- **Admin** : `is_admin = 1`. Accès à `/admin/*` (`require_admin()`). Pas de sous-rôles — un admin a accès à l'intégralité du panneau (adapté à la taille de l'équipe actuelle ; à revisiter si l'équipe grandit).

Toutes les pages `/admin/*` et `/vendeur/*` sont protégées (vérifié par audit systématique — soit un appel direct à `require_admin()`/`require_vendor()`, soit via l'inclusion de `admin_header.php`/`vendor_header.php` qui l'appelle en interne). Toute action sensible (suspension, ajustement manuel de wallet) est tracée dans `audit_logs` avec l'admin responsable et la justification.

**Connexion admin séparée + 2FA email** — un compte `is_admin = 1` ne peut plus s'authentifier via le formulaire public `/connexion.php` (rejeté avec un message générique, comme un email/mot de passe incorrect — aucune fuite d'information). Seul `/admin/connexion.php` accepte les comptes admin, et uniquement eux (symétrique : un compte client/vendeur y est rejeté de la même façon). Après mot de passe valide, un code à 6 chiffres est envoyé par email (usage unique, expire en 10 min, verrouillage après 5 essais incorrects — voir `login_2fa_codes`) avant l'ouverture de session (`verification-2fa.php`). `require_admin()` redirige tout visiteur non connecté directement vers `/admin/connexion.php`. Aucun compte admin n'est créé par défaut à l'installation (voir §4c) — le tout premier admin s'obtient en passant `is_admin = 1` en base directement sur un compte déjà inscrit.

Récupération de mot de passe (`/mot-de-passe-oublie.php`, commune aux trois rôles) : token à usage unique haché en base (SHA-256), expire en 1h, aucune énumération d'email. Redirige vers `/admin/connexion.php` ou `/connexion.php` selon le rôle du compte concerné.

Protection CSRF sur tous les formulaires (`includes/csrf.php`), rate limiting sur connexion/inscription/webhook/retraits/2FA/reset (`includes/rate_limit.php`) — voir le code pour le détail des seuils.

## 13. En cas de problème

- Logs applicatifs : `logs/{geniuspay,payment,webhook,settlement,wallet,wallet_release,withdrawal,vendor_admin,refund,notifications}.log` (format JSON par ligne, voir `src/Support/Logger.php`). `notifications.log` trace chaque email (envoyé, échoué, ou ignoré si SMTP non configuré) — premier réflexe en cas de 2FA ou de reset de mot de passe non reçu.
- Échec de rapprochement paiement (somme des parts vendeurs + commission ≠ montant payé) : jamais de crédit silencieux, la commande reste non réglée et un incident est enregistré dans `settlement_failures` — visible en haut de `/admin/finances.php` tant que non résolu.
- Un webhook reçu deux fois (retry Genius Pay) est automatiquement ignoré (`webhook_events.event_id` UNIQUE) — vérifier `logs/webhook.log` en cas de doute plutôt que de re-déclencher manuellement.
