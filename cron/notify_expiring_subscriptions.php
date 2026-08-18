<?php

declare(strict_types=1);

/**
 * Job planifie (cron / Tache planifiee Windows) : envoie un email de rappel
 * aux boutiques dont l'abonnement expire dans exactement REMINDER_DAYS_BEFORE
 * jours, pour qu'elles le renouvellent avant de devenir invisibles sur le
 * site. Le seuil "= N jours" (et non "<= N jours") garantit un envoi unique
 * par cycle d'abonnement, meme si le job tourne plusieurs fois le meme jour
 * ou tous les jours : DATEDIFF ne vaut N que sur une seule date de calendrier
 * pour une date de fin donnee.
 *
 * Usage CLI :
 *   php cron/notify_expiring_subscriptions.php
 *
 * Frequence recommandee : une fois par jour.
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

const REMINDER_DAYS_BEFORE = 3;

$db = get_db();
$stmt = $db->prepare('
    SELECT s.id, ss.ends_at
    FROM shops s
    JOIN shop_subscriptions ss ON ss.shop_id = s.id
    WHERE s.owner_id IS NOT NULL
      AND CURDATE() BETWEEN ss.starts_at AND ss.ends_at
      AND DATEDIFF(ss.ends_at, CURDATE()) = :days
');
$stmt->execute(['days' => REMINDER_DAYS_BEFORE]);
$shops = $stmt->fetchAll();

foreach ($shops as $shop) {
    wallet_notification_service()->subscriptionExpiringSoon((int) $shop['id'], (string) $shop['ends_at']);
}

echo sprintf(
    "[%s] Rappels d'expiration d'abonnement : %d boutique(s) notifiee(s) (seuil = %d jours avant expiration).\n",
    date('Y-m-d H:i:s'),
    count($shops),
    REMINDER_DAYS_BEFORE
);

exit(0);
