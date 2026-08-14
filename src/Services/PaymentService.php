<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PaymentRepository;
use App\Support\Logger;

final class PaymentService
{
    public function __construct(
        private GeniusPayService $geniusPay,
        private PaymentRepository $payments,
        private string $environment
    ) {
    }

    /**
     * Cree un paiement Genius Pay pour une commande DEJA enregistree en base
     * (order_id, total_amount fige serveur). Ne credite RIEN : cree seulement
     * la transaction cote Genius Pay et la ligne payments (status=pending).
     */
    public function createForOrder(int $orderId, int $amount, array $params): PaymentCreationResult
    {
        if ($amount < 200) {
            return PaymentCreationResult::failure('Le paiement en ligne necessite un montant minimum de 200 FCFA.');
        }

        $result = $this->geniusPay->createPayment(array_merge($params, ['amount' => $amount]));

        if (!$result->ok) {
            Logger::error('payment', 'Echec creation paiement Genius Pay', ['order_id' => $orderId, 'error' => $result->error]);

            return PaymentCreationResult::failure($result->error ?? 'Erreur inconnue Genius Pay.');
        }

        $data = $result->data();
        if (empty($data['reference']) || empty($data['payment_url'])) {
            return PaymentCreationResult::failure('Reponse Genius Pay incomplete (reference/payment_url manquants).');
        }

        $paymentId = $this->payments->create([
            'order_id' => $orderId,
            'provider_reference' => $data['reference'],
            'amount' => $amount,
            'currency' => $data['currency'] ?? 'XOF',
            'status' => $data['status'] ?? 'pending',
            'payment_method' => $data['payment_method'] ?? null,
            'environment' => $data['environment'] ?? $this->environment,
            'payment_url' => $data['payment_url'],
            'raw_response' => $data,
        ]);

        Logger::info('payment', 'Paiement cree', ['order_id' => $orderId, 'payment_id' => $paymentId, 'reference' => $data['reference']]);

        return PaymentCreationResult::success($paymentId, $data['reference'], $data['payment_url']);
    }

    /**
     * Verification serveur->serveur explicite (page de retour, ou job de
     * rattrapage). Met a jour payments.status avec le VRAI statut Genius Pay.
     * Ne credite jamais un wallet elle-meme : c'est WebhookService/
     * OrderSettlementService qui s'en charge, sur la base de ce statut verifie.
     */
    public function verifyAndSync(string $reference): string
    {
        $payment = $this->payments->findByReference($reference);
        if (!$payment) {
            throw new \RuntimeException("Paiement introuvable pour la reference {$reference}.");
        }

        $result = $this->geniusPay->verifyPayment($reference);
        if (!$result->ok) {
            Logger::warning('payment', 'Verification Genius Pay indisponible, statut local conserve', ['reference' => $reference, 'error' => $result->error]);

            return $payment['status'];
        }

        $data = $result->data();
        $realStatus = (string) ($data['status'] ?? $payment['status']);

        if ($realStatus !== $payment['status']) {
            $this->payments->updateStatus((int) $payment['id'], $realStatus, $data['payment_method'] ?? null, $realStatus === 'completed');
        }

        return $realStatus;
    }
}
