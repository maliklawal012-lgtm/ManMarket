# ManMarket

Marketplace en ligne pour la ville de Man, Côte d'Ivoire — PHP 8.4 / MySQL, avec une architecture portefeuille complète (vendeurs, commissions, retraits, remboursements) intégrée à [Genius Pay](https://geniuspay.ci).

## Stack technique

- PHP **8.4** (`declare(strict_types=1)`, namespaces `App\` autoloadés via `config/autoload.php`)
- MySQL/MariaDB **8.0+** (InnoDB requis)
- Apache + `mod_rewrite` (`.htaccess`)
- Aucune dépendance externe (pas de Composer/npm) — tout le code est natif

## Installation locale (WAMP / Windows)

### 1. Prérequis

- WAMP Server (ou équivalent) avec PHP 8.4 et MySQL 8+
- Un compte marchand [Genius Pay](https://geniuspay.ci) (facultatif pour explorer le code, requis pour tester un vrai paiement)

### 2. Récupérer le projet

Cloner (ou copier) ce dépôt dans le dossier servi par WAMP, par exemple :

```
C:\wamp64\www\market
```

### 3. Configuration

```bash
cp .env.example .env
```

Renseigner au minimum `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` dans `.env`. Les clés `GENIUSPAY_*` peuvent rester vides tant qu'aucun paiement réel n'est testé (voir `DEPLOYMENT.md` §8 pour l'enregistrement du webhook).

### 4. Base de données

```bash
mysql -u root -p < database/schema.sql
php database/migrate_wallet_v2.php
```

`schema.sql` crée la base `manmarket` et le schéma de base (utilisateurs, boutiques, produits, catégories...). `migrate_wallet_v2.php` applique ensuite, de façon idempotente, toute l'architecture portefeuille par-dessus (vendors, wallets, commissions, retraits, remboursements, etc.). Le script peut être relancé sans risque à tout moment.

### 5. Démarrer

Démarrer WAMP (Apache + MySQL), puis ouvrir :

```
http://localhost/market/
```

Un compte admin doit être créé/promu directement en base (`UPDATE users SET is_admin = 1 WHERE email = '...'`) pour accéder à `/admin/`.

## Tests

Suite de régression formelle (15 scénarios : paiement, webhook, retrait, remboursement, sécurité) :

```bash
php tests/wallet_scenarios.php
```

Doit afficher `15/15 scenarios reussis`. Utilise uniquement des données jetables, créées et nettoyées automatiquement — aucun impact sur les données réelles.

Vérification de syntaxe sur l'ensemble du projet :

```bash
find . -name "*.php" -not -path "./vendor/*" -exec php -l {} \; | grep -v "No syntax errors"
```

(ne doit rien afficher)

## Structure du projet

```
admin/          Panneau d'administration (gestion boutiques, produits, commandes, finances...)
vendeur/        Espace vendeur (produits, commandes, finances, retraits...)
src/            Couche métier (Repositories, Services) — architecture wallet/paiement
includes/       Fonctions partagées, authentification, CSRF, rate limiting
config/         Autoload, connexion DB, configuration Genius Pay
database/       Schéma SQL + migration idempotente
cron/           Tâche planifiée (libération des soldes vendeurs)
tests/          Suite de tests de régression
api/webhooks/   Point d'entrée webhook Genius Pay
```

## Déploiement en production

Voir [`DEPLOYMENT.md`](DEPLOYMENT.md) pour la procédure complète : configuration Apache, permissions fichiers, enregistrement du webhook Genius Pay, tâche cron, modèle de sécurité (RBAC), et vérifications post-déploiement.

## Sécurité

- CSRF sur tous les formulaires (`includes/csrf.php`)
- Rate limiting sur connexion, inscription, webhook et retraits (`includes/rate_limit.php`)
- RBAC à trois rôles (client / vendeur / admin) — voir `DEPLOYMENT.md` §11
- `.env` jamais committé (voir `.gitignore`) et bloqué en accès web par `.htaccess`

## Licence

Propriétaire — tous droits réservés. Voir [`LICENSE`](LICENSE).
