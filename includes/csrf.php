<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

/**
 * Protection CSRF minimale, dependance-free : un token aleatoire par session,
 * verifie sur CHAQUE requete POST. Voir csrf_verify() pour l'usage standard :
 * appelee une seule fois par page, juste apres les requires, avant tout
 * traitement metier du POST (protege alors tous les blocs POST de la page,
 * quel que soit leur nombre).
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/**
 * A appeler en tout debut de traitement d'une requete POST (avant toute
 * lecture/ecriture liee au formulaire). Arrete l'execution avec 403 si le
 * token est absent ou invalide.
 */
function csrf_verify(): void
{
    $submitted = (string) ($_POST['_csrf'] ?? '');
    $expected = (string) ($_SESSION['csrf_token'] ?? '');

    if ($expected === '' || $submitted === '' || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Session expirée</title></head><body style="font-family:sans-serif; max-width:520px; margin:80px auto; text-align:center;">'
            . '<h1>Session expirée</h1>'
            . '<p>Votre formulaire a expiré ou provient d\'une source non fiable. Revenez en arrière et réessayez.</p>'
            . '<p><a href="javascript:history.back()">Retour</a></p>'
            . '</body></html>';
        exit;
    }
}
