<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

/**
 * Limiteur de frequence generique, appuye sur la table rate_limit_hits.
 * Chaque appel : purge les entrees perimees pour CETTE cle, compte les
 * tentatives dans la fenetre, refuse si le plafond est atteint, sinon
 * enregistre la tentative et autorise.
 *
 * Le nettoyage est "paresseux" (uniquement sur la cle consultee) : suffisant
 * a l'echelle d'un marche local, pas besoin d'un job de purge dedie.
 */
function rate_limit_check(string $key, int $maxAttempts, int $windowSeconds): bool
{
    $db = get_db();

    $stmt = $db->prepare('DELETE FROM rate_limit_hits WHERE rl_key = :key AND created_at < :since');
    $stmt->execute(['key' => $key, 'since' => date('Y-m-d H:i:s', time() - $windowSeconds)]);

    $stmt = $db->prepare('SELECT COUNT(*) FROM rate_limit_hits WHERE rl_key = :key');
    $stmt->execute(['key' => $key]);
    $count = (int) $stmt->fetchColumn();

    if ($count >= $maxAttempts) {
        return false;
    }

    $db->prepare('INSERT INTO rate_limit_hits (rl_key) VALUES (:key)')->execute(['key' => $key]);

    return true;
}

function rate_limit_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}
