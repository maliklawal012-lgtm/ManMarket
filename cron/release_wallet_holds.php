<?php

declare(strict_types=1);

/**
 * Job planifie (cron / Tache planifiee Windows) : libere pending_balance vers
 * available_balance pour toute ligne de commande livree dont le delai de
 * retenue (settings.wallet_hold_days) est ecoule. Idempotent — peut etre
 * execute aussi souvent que necessaire sans double-liberer un meme montant.
 *
 * Usage CLI :
 *   php cron/release_wallet_holds.php
 *
 * Frequence recommandee : toutes les heures (wallet_hold_days se compte en
 * jours entiers, une execution horaire suffit largement a le respecter).
 * Ne PAS exposer ce script en HTTP (deja bloque par .htaccess : RewriteRule
 * ^(src|logs|database|cron)/ - [F,L]).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ce script ne peut etre execute qu\'en ligne de commande.');
}

require_once __DIR__ . '/../config/autoload.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/wallet_bootstrap.php';

$result = wallet_release_service()->releaseMaturedHolds();

echo sprintf(
    "[%s] Liberation terminee : %d ligne(s) liberee(s), %d FCFA au total, %d erreur(s).\n",
    date('Y-m-d H:i:s'),
    $result['released'],
    $result['total_amount'],
    $result['errors']
);

exit($result['errors'] > 0 ? 1 : 0);
