<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Les montants sont stockes en DECIMAL(12,2) en base (jamais FLOAT/DOUBLE),
 * mais le XOF n'a pas de sous-unite utilisee en pratique dans ce projet :
 * on manipule donc des entiers PHP (arithmetique exacte, pas de flottant),
 * et on ne convertit en chaine DECIMAL qu'au moment d'ecrire en base.
 *
 * Toute valeur qui transite par ici DOIT venir du serveur (jamais du montant
 * envoye par le navigateur).
 */
final class Money
{
    /**
     * Convertit une valeur DECIMAL lue en base (toujours une string via PDO)
     * en entier PHP. Rejette explicitement toute partie fractionnaire non nulle
     * pour detecter une anomalie plutot que de la tronquer silencieusement.
     */
    public static function fromDecimalString(string $value): int
    {
        if (!preg_match('/^-?\d+(\.\d{1,2})?$/', $value)) {
            throw new \InvalidArgumentException("Valeur monetaire invalide : {$value}");
        }

        $float = (float) $value;
        $int = (int) round($float);

        if (abs($float - $int) > 0.0001) {
            throw new \InvalidArgumentException("Montant non entier inattendu pour XOF : {$value}");
        }

        return $int;
    }

    public static function toDecimalString(int $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    public static function format(int $amount): string
    {
        return number_format($amount, 0, ',', ' ') . ' FCFA';
    }
}
