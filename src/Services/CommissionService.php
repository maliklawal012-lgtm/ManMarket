<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Calcule la commission d'une ligne de commande a partir du taux configure
 * (settings.marketplace_commission_rate, 0.00 par defaut). Le taux est fige
 * dans order_items au moment de la commande : ce service ne recalcule jamais
 * une commande passee avec un taux different.
 */
final class CommissionService
{
    public function __construct(private \PDO $db)
    {
    }

    public function currentRate(): float
    {
        $stmt = $this->db->prepare("SELECT value FROM settings WHERE `key` = 'marketplace_commission_rate'");
        $stmt->execute();
        $value = $stmt->fetchColumn();

        return $value !== false ? (float) $value : 0.0;
    }

    /**
     * @return array{commission_amount:int, net_amount:int}
     */
    public function calculate(int $subtotal, float $rate): array
    {
        if ($rate < 0 || $rate > 100) {
            throw new \InvalidArgumentException("Taux de commission invalide : {$rate}");
        }

        $commissionAmount = (int) round($subtotal * $rate / 100);
        $netAmount = $subtotal - $commissionAmount;

        return ['commission_amount' => $commissionAmount, 'net_amount' => $netAmount];
    }
}
