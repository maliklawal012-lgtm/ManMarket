<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PaymentRepository;
use App\Repositories\WebhookEventRepository;
use App\Support\Logger;
use PDO;

final class WebhookService
{
    private const VALID_STATUSES = ['pending', 'processing', 'completed', 'failed', 'cancelled', 'expired', 'refunded'];

    public function __construct(
        private GeniusPayService $geniusPay,
        private WebhookEventRepository $events,
        private PaymentRepository $payments,
        private OrderSettlementService $settlement,
        private ?PDO $legacyDb = null,
        private ?NotificationService $notifications = null
    ) {
    }

    /**
     * Traite un webhook Genius Pay brut. Retourne un tableau
     * ['http_status' => int, 'body' => array] a renvoyer tel quel par le
     * controleur HTTP.
     */
    public function handle(string $rawBody, string $signature, string $timestamp, string $eventType): array
    {
        // 1. Signature obligatoire AVANT toute autre logique.
        if (!$this->geniusPay->verifyWebhookSignature($rawBody, $signature, $timestamp)) {
            Logger::warning('webhook', 'Signature invalide', ['event_type' => $eventType]);

            return ['http_status' => 401, 'body' => ['status' => 401, 'detail' => 'Invalid signature']];
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return ['http_status' => 400, 'body' => ['status' => 400, 'detail' => 'Invalid payload']];
        }

        $eventId = (string) ($payload['id'] ?? '');
        if ($eventId === '') {
            // Genius Pay envoie toujours un id (voir doc webhook-events). Sans id,
            // impossible de garantir l'idempotence : on rejette plutot que de risquer un double traitement.
            Logger::warning('webhook', 'Evenement sans id, rejete par securite', ['event_type' => $eventType]);

            return ['http_status' => 400, 'body' => ['status' => 400, 'detail' => 'Missing event id']];
        }

        // 2. Idempotence : enregistrement atomique (contrainte UNIQUE), avant tout traitement metier.
        $recorded = $this->events->record('geniuspay', $eventId, $eventType, $payload, true);
        if (!$recorded) {
            Logger::info('webhook', 'Evenement deja traite, ignore', ['event_id' => $eventId]);

            return ['http_status' => 200, 'body' => ['status' => 200, 'detail' => 'already processed']];
        }

        if ($eventType === 'webhook.test' || !isset($payload['data']['reference'])) {
            $this->events->markProcessed('geniuspay', $eventId);

            return ['http_status' => 200, 'body' => ['status' => 200, 'detail' => 'ok']];
        }

        try {
            $this->processPaymentEvent($payload);
            $this->events->markProcessed('geniuspay', $eventId);

            return ['http_status' => 200, 'body' => ['status' => 200, 'detail' => 'ok']];
        } catch (\Throwable $e) {
            $this->events->markProcessed('geniuspay', $eventId, $e->getMessage());
            Logger::error('webhook', 'Erreur pendant le traitement', ['event_id' => $eventId, 'error' => $e->getMessage()]);

            // On renvoie 200 quand meme : l'evenement EST enregistre (idempotence garantie),
            // on ne veut pas que Genius Pay le renvoie en boucle pour une erreur de notre cote
            // qui necessite une investigation manuelle (voir logs/webhook.log).
            return ['http_status' => 200, 'body' => ['status' => 200, 'detail' => 'recorded with error']];
        }
    }

    private function processPaymentEvent(array $payload): void
    {
        $reference = (string) $payload['data']['reference'];
        $status = (string) ($payload['data']['status'] ?? '');
        $method = $payload['data']['payment_method'] ?? $payload['data']['provider'] ?? null;
        $remoteAmount = isset($payload['data']['amount']) ? (int) round((float) $payload['data']['amount']) : null;
        $remoteCurrency = $payload['data']['currency'] ?? null;

        if ($status === '' || !in_array($status, self::VALID_STATUSES, true)) {
            Logger::warning('webhook', 'Statut inconnu ignore', ['reference' => $reference, 'status' => $status]);

            return;
        }

        $payment = $this->payments->findByReference($reference);
        if (!$payment) {
            $this->processSubscriptionEvent($reference, $status, $method);

            return;
        }

        // Verification stricte montant/devise avant toute mise a jour de statut "completed".
        if ($status === 'completed') {
            if ($remoteAmount !== null && $remoteAmount !== (int) round((float) $payment['amount'])) {
                Logger::error('webhook', 'Montant recu different du montant attendu — paiement NON confirme', [
                    'reference' => $reference, 'expected' => $payment['amount'], 'received' => $remoteAmount,
                ]);

                return;
            }
            if ($remoteCurrency !== null && $remoteCurrency !== $payment['currency']) {
                Logger::error('webhook', 'Devise recue differente — paiement NON confirme', [
                    'reference' => $reference, 'expected' => $payment['currency'], 'received' => $remoteCurrency,
                ]);

                return;
            }
        }

        $this->payments->updateStatus((int) $payment['id'], $status, $method, $status === 'completed');

        if ($status === 'completed') {
            $this->settlement->settleOrder((int) $payment['order_id']);
            $this->recordReliabilityOnSuccess((int) $payment['order_id']);
        }

        if (in_array($status, ['completed', 'failed', 'cancelled', 'expired'], true)) {
            $this->notifications?->paymentResult((int) $payment['order_id'], $status);
        }
    }

    /**
     * Systeme de fiabilite paiement (voir includes/functions.php) : un paiement
     * en ligne confirme par webhook est la seule source jugee fiable pour
     * compter les paiements en ligne reussis consecutifs d'un client restreint.
     * $legacyDb est en realite la connexion PDO principale (voir constructeur) —
     * reutilisee ici pour cette lecture simple plutot que d'introduire une
     * nouvelle dependance au constructeur pour un seul SELECT.
     */
    private function recordReliabilityOnSuccess(int $orderId): void
    {
        if ($this->legacyDb === null) {
            return;
        }

        $stmt = $this->legacyDb->prepare('SELECT customer_user_id, payment_choice FROM orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();

        if ($order && $order['customer_user_id'] && $order['payment_choice'] === 'online') {
            \record_successful_online_payment((int) $order['customer_user_id']);
        }
    }

    /**
     * Abonnements boutique (shop_subscriptions) : domaine distinct des commandes
     * marketplace, sans wallet ni commission. Repris tel quel de l'ancien
     * webhooks/geniuspay.php pour garder UN SEUL webhook Genius Pay enregistre
     * (donc un seul secret a gerer) plutot que d'en dupliquer un second.
     */
    private function processSubscriptionEvent(string $reference, string $status, ?string $method): void
    {
        if ($this->legacyDb === null) {
            Logger::warning('webhook', 'Reference inconnue (aucune table abonnement configuree)', ['reference' => $reference]);

            return;
        }

        $stmt = $this->legacyDb->prepare('SELECT * FROM subscription_payments WHERE reference = :reference');
        $stmt->execute(['reference' => $reference]);
        $subPayment = $stmt->fetch();

        if (!$subPayment) {
            Logger::warning('webhook', 'Reference inconnue (ni payments, ni subscription_payments)', ['reference' => $reference]);

            return;
        }

        $this->legacyDb->prepare('UPDATE subscription_payments SET status = :status, payment_method = :method, updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute([
            'status' => $status,
            'method' => $method,
            'id' => $subPayment['id'],
        ]);

        if ($status === 'completed' && !$subPayment['applied']) {
            $stmt = $this->legacyDb->prepare('SELECT * FROM subscription_plans WHERE id = :id');
            $stmt->execute(['id' => $subPayment['plan_id']]);
            $plan = $stmt->fetch();

            if ($plan) {
                \apply_subscription_payment((int) $subPayment['shop_id'], $plan, (int) $subPayment['amount']);
                $this->legacyDb->prepare('UPDATE subscription_payments SET applied = 1 WHERE id = :id')->execute(['id' => $subPayment['id']]);
            }
        }
    }
}
