<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Logger;
use PDO;

/**
 * Point d'entree unique pour toute notification email envoyee par la
 * plateforme (client ou vendeur). Chaque methode publique correspond a UN
 * evenement metier et ne leve jamais d'exception : une notification qui
 * echoue ne doit jamais faire echouer l'action qui l'a declenchee (voir
 * SmtpMailer::send(), lui-meme "best effort").
 *
 * Tant que SmtpMailer::isConfigured() est faux (aucun SMTP_HOST dans .env),
 * chaque methode se contente de logger et retourne immediatement — aucune
 * tentative de connexion. C'est le mode par defaut de ce projet tant qu'un
 * vrai compte SMTP n'a pas ete fourni.
 */
final class NotificationService
{
    public function __construct(
        private PDO $db,
        private SmtpMailer $mailer
    ) {
    }

    public function orderCreated(int $orderId): void
    {
        $this->guard(function () use ($orderId): void {
            $order = $this->findOrder($orderId);
            if (!$order) {
                return;
            }

            $items = $this->findOrderItems($orderId);
            $lines = array_map(
                fn (array $i) => "  - {$i['quantity']} x {$i['product_name']} — " . $this->money((float) $i['subtotal']),
                $items
            );

            $body = "Bonjour {$order['customer_name']},\n\n"
                . "Nous avons bien recu votre commande #{$orderId} sur ManMarket.\n\n"
                . "Articles :\n" . implode("\n", $lines) . "\n\n"
                . "Total : " . $this->money((float) $order['total_amount']) . "\n"
                . "Mode de paiement : " . ($order['payment_choice'] === 'online' ? 'En ligne' : 'A la livraison') . "\n\n"
                . "Nous vous tiendrons informe de son avancement.\n\nL'equipe ManMarket";

            $this->send($order['customer_email'], $order['customer_name'], "Confirmation de votre commande #{$orderId}", $body, 'order_created', $orderId);
        }, 'orderCreated', $orderId);
    }

    public function paymentResult(int $orderId, string $status): void
    {
        $this->guard(function () use ($orderId, $status): void {
            $order = $this->findOrder($orderId);
            if (!$order) {
                return;
            }

            if ($status === 'completed') {
                $subject = "Paiement confirme — commande #{$orderId}";
                $body = "Bonjour {$order['customer_name']},\n\n"
                    . "Votre paiement pour la commande #{$orderId} ({$this->money((float) $order['total_amount'])}) a bien ete confirme.\n\n"
                    . "Votre commande va maintenant etre preparee.\n\nL'equipe ManMarket";
            } else {
                $labels = ['failed' => 'a echoue', 'cancelled' => 'a ete annule', 'expired' => 'a expire'];
                $label = $labels[$status] ?? 'a rencontre un probleme';
                $subject = "Probleme avec le paiement de votre commande #{$orderId}";
                $body = "Bonjour {$order['customer_name']},\n\n"
                    . "Le paiement de votre commande #{$orderId} {$label}. Aucun montant n'a ete debite avec succes.\n\n"
                    . "Vous pouvez reessayer depuis votre espace ManMarket, ou nous contacter si le probleme persiste.\n\nL'equipe ManMarket";
            }

            $this->send($order['customer_email'], $order['customer_name'], $subject, $body, 'payment_' . $status, $orderId);
        }, 'paymentResult', $orderId);
    }

    public function orderStatusChanged(int $orderId, string $newStatus): void
    {
        $this->guard(function () use ($orderId, $newStatus): void {
            $order = $this->findOrder($orderId);
            if (!$order) {
                return;
            }

            $labels = [
                'processing' => 'est en cours de preparation',
                'shipping' => 'est en cours de livraison',
                'delivered' => 'a ete livree',
                'cancelled' => 'a ete annulee',
                'pending' => 'est en attente',
            ];
            $label = $labels[$newStatus] ?? ('a change de statut : ' . $newStatus);

            $subject = "Votre commande #{$orderId} {$label}";
            $body = "Bonjour {$order['customer_name']},\n\n"
                . "Votre commande #{$orderId} {$label}.\n\n"
                . "L'equipe ManMarket";

            $this->send($order['customer_email'], $order['customer_name'], $subject, $body, 'order_status_' . $newStatus, $orderId);
        }, 'orderStatusChanged', $orderId);
    }

    /**
     * @param array<int, array{vendor_id:int, product_name:string, quantity:int, subtotal:int}> $resolvedItems
     */
    public function newOrderForVendors(int $orderId, array $resolvedItems): void
    {
        $this->guard(function () use ($orderId, $resolvedItems): void {
            $byVendor = [];
            foreach ($resolvedItems as $item) {
                $byVendor[(int) $item['vendor_id']][] = $item;
            }

            foreach ($byVendor as $vendorId => $items) {
                $vendor = $this->findVendorContact($vendorId);
                if (!$vendor) {
                    continue;
                }

                $lines = array_map(
                    fn (array $i) => "  - {$i['quantity']} x {$i['product_name']} — " . $this->money((float) $i['subtotal']),
                    $items
                );
                $total = array_sum(array_column($items, 'subtotal'));

                $body = "Bonjour {$vendor['name']},\n\n"
                    . "Vous avez recu une nouvelle commande (#{$orderId}) sur ManMarket.\n\n"
                    . "Vos articles commandes :\n" . implode("\n", $lines) . "\n\n"
                    . "Sous-total : " . $this->money((float) $total) . "\n\n"
                    . "Connectez-vous a votre espace vendeur pour la traiter.\n\nL'equipe ManMarket";

                $this->send($vendor['email'], $vendor['name'], "Nouvelle commande #{$orderId} sur votre boutique", $body, 'new_order_vendor', $orderId);
            }
        }, 'newOrderForVendors', $orderId);
    }

    public function withdrawalStatusChanged(int $withdrawalId): void
    {
        $this->guard(function () use ($withdrawalId): void {
            $stmt = $this->db->prepare('SELECT * FROM withdrawals WHERE id = :id');
            $stmt->execute(['id' => $withdrawalId]);
            $withdrawal = $stmt->fetch();
            if (!$withdrawal) {
                return;
            }

            $vendor = $this->findVendorContact((int) $withdrawal['vendor_id']);
            if (!$vendor) {
                return;
            }

            $labels = [
                'PENDING' => 'est en attente de traitement',
                'APPROVED' => 'a ete approuve',
                'PROCESSING' => 'est en cours de paiement',
                'COMPLETED' => 'a ete paye',
                'REJECTED' => 'a ete rejete',
                'FAILED' => 'a echoue',
                'CANCELLED' => 'a ete annule',
            ];
            $label = $labels[$withdrawal['status']] ?? ('a change de statut : ' . $withdrawal['status']);

            $body = "Bonjour {$vendor['name']},\n\n"
                . "Votre demande de retrait #{$withdrawalId} ({$this->money((float) $withdrawal['amount'])}) {$label}.\n\n"
                . ($withdrawal['admin_note'] ? "Note : {$withdrawal['admin_note']}\n\n" : '')
                . "L'equipe ManMarket";

            $this->send($vendor['email'], $vendor['name'], "Retrait #{$withdrawalId} {$label}", $body, 'withdrawal_' . $withdrawal['status'], $withdrawalId);
        }, 'withdrawalStatusChanged', $withdrawalId);
    }

    public function refundProcessed(int $refundId): void
    {
        $this->guard(function () use ($refundId): void {
            $stmt = $this->db->prepare('SELECT * FROM refunds WHERE id = :id');
            $stmt->execute(['id' => $refundId]);
            $refund = $stmt->fetch();
            if (!$refund) {
                return;
            }

            $order = $this->findOrder((int) $refund['order_id']);

            $stmt = $this->db->prepare('SELECT * FROM refund_items WHERE refund_id = :id');
            $stmt->execute(['id' => $refundId]);
            $refundItems = $stmt->fetchAll();

            if ($order) {
                $body = "Bonjour {$order['customer_name']},\n\n"
                    . "Un remboursement a ete traite pour votre commande #{$refund['order_id']} : " . $this->money((float) $refund['total_amount']) . ".\n\n"
                    . "Motif : {$refund['reason']}\n\n"
                    . "L'equipe ManMarket";

                $this->send($order['customer_email'], $order['customer_name'], "Remboursement traite — commande #{$refund['order_id']}", $body, 'refund_customer', $refundId);
            }

            $byVendor = [];
            foreach ($refundItems as $ri) {
                $byVendor[(int) $ri['vendor_id']] = ($byVendor[(int) $ri['vendor_id']] ?? 0) + (float) $ri['refunded_net_amount'];
            }

            foreach ($byVendor as $vendorId => $netAmount) {
                $vendor = $this->findVendorContact($vendorId);
                if (!$vendor) {
                    continue;
                }

                $body = "Bonjour {$vendor['name']},\n\n"
                    . "Un remboursement a ete applique sur la commande #{$refund['order_id']}, impactant votre solde de " . $this->money($netAmount) . ".\n\n"
                    . "Motif : {$refund['reason']}\n\n"
                    . "L'equipe ManMarket";

                $this->send($vendor['email'], $vendor['name'], "Remboursement applique sur votre solde — commande #{$refund['order_id']}", $body, 'refund_vendor', $refundId);
            }
        }, 'refundProcessed', $refundId);
    }

    private function guard(\Closure $fn, string $event, int $refId): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Logger::error('notifications', 'Erreur notification (avalee, sans impact sur l\'action metier)', [
                'event' => $event, 'ref_id' => $refId, 'error' => $e->getMessage(),
            ]);
        }
    }

    private function send(string $toEmail, string $toName, string $subject, string $body, string $event, int $refId): void
    {
        if (!$this->mailer->isConfigured()) {
            Logger::info('notifications', 'SMTP non configure, envoi ignore', ['event' => $event, 'ref_id' => $refId, 'to' => $toEmail]);

            return;
        }

        $ok = $this->mailer->send($toEmail, $toName, $subject, $body);

        if ($ok) {
            Logger::info('notifications', 'Email envoye', ['event' => $event, 'ref_id' => $refId, 'to' => $toEmail]);
        } else {
            Logger::warning('notifications', 'Echec envoi email', ['event' => $event, 'ref_id' => $refId, 'to' => $toEmail]);
        }
    }

    private function findOrder(int $orderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);

        return $stmt->fetch() ?: null;
    }

    private function findOrderItems(int $orderId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM order_items WHERE order_id = :id ORDER BY id');
        $stmt->execute(['id' => $orderId]);

        return $stmt->fetchAll();
    }

    private function findVendorContact(int $vendorId): ?array
    {
        $stmt = $this->db->prepare('SELECT u.email, u.name FROM vendors v JOIN users u ON u.id = v.user_id WHERE v.id = :id');
        $stmt->execute(['id' => $vendorId]);

        return $stmt->fetch() ?: null;
    }

    private function money(float $amount): string
    {
        return number_format((int) round($amount), 0, ',', ' ') . ' FCFA';
    }
}
