<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Logger;

/**
 * Seul point de contact avec l'API Genius Pay dans tout le projet.
 *
 * Ce qui est officiellement supporte par Genius Pay (verifie dans leur doc,
 * teste avec de vrais appels API le 2026-08-12) :
 *   - createPayment() -> POST /payments
 *   - verifyPayment()  -> GET /payments/{reference}
 *   - listPayments()   -> GET /payments
 *   - getAccount()      -> GET /account
 *   - registerWebhook() -> POST /webhooks
 *   - listWebhooks()    -> GET /webhooks
 *   - updateWebhook()   -> PUT /webhooks/{id}
 *   - verifyWebhookSignature() -> HMAC-SHA256, documente
 *
 * Ce qui N'EST PAS disponible (verifie sur geniuspay.ci/docs/payout-api le
 * 2026-08-12 : titre de la page = "Payouts API - Bientot disponible") :
 *   - createPayout() : n'appelle AUCUN endpoint reel. Leve NotSupportedException.
 *     Ne jamais inventer d'URL pour cette fonctionnalite.
 */
final class GeniusPayService
{
    private string $apiBase;
    private string $publicKey;
    private string $secretKey;
    private string $webhookSecret;

    public function __construct(string $apiBase, string $publicKey, string $secretKey, string $webhookSecret)
    {
        $this->apiBase = rtrim($apiBase, '/');
        $this->publicKey = $publicKey;
        $this->secretKey = $secretKey;
        $this->webhookSecret = $webhookSecret;
    }

    public static function fromEnv(): self
    {
        return new self(
            \geniuspay_config('API_BASE'),
            \geniuspay_config('PUBLIC_KEY'),
            \geniuspay_config('SECRET_KEY'),
            \env('GENIUSPAY_WEBHOOK_SECRET', '')
        );
    }

    /**
     * Cree un paiement. $params doit contenir au minimum 'amount' (int, XOF).
     * Voir doc : payment_method, description, customer, success_url, error_url, metadata.
     */
    public function createPayment(array $params): GeniusPayResult
    {
        return $this->request('POST', '/payments', $params);
    }

    public function verifyPayment(string $reference): GeniusPayResult
    {
        return $this->request('GET', '/payments/' . rawurlencode($reference));
    }

    public function listPayments(array $filters = []): GeniusPayResult
    {
        $query = $filters ? '?' . http_build_query($filters) : '';

        return $this->request('GET', '/payments' . $query);
    }

    public function getAccount(): GeniusPayResult
    {
        return $this->request('GET', '/account');
    }

    public function registerWebhook(string $url, array $events): GeniusPayResult
    {
        return $this->request('POST', '/webhooks', ['name' => 'ManMarket', 'url' => $url, 'events' => $events]);
    }

    public function listWebhooks(): GeniusPayResult
    {
        return $this->request('GET', '/webhooks');
    }

    public function updateWebhook(int $id, array $params): GeniusPayResult
    {
        return $this->request('PUT', '/webhooks/' . $id, $params);
    }

    /**
     * PAS DISPONIBLE cote Genius Pay a ce jour (API "Bientot disponible").
     * Cette methode existe pour que l'architecture soit prete le jour ou
     * l'endpoint sera publie, mais elle n'appelle rien : elle documente
     * explicitement l'indisponibilite plutot que d'echouer silencieusement.
     */
    public function createPayout(array $params): never
    {
        Logger::warning('geniuspay', 'Tentative d\'appel a createPayout() alors que l\'API Payouts Genius Pay n\'est pas disponible', $params);

        throw new \RuntimeException(
            "L'API Payouts de Genius Pay n'est pas disponible publiquement a ce jour " .
            '(geniuspay.ci/docs/payout-api indique "Bientot disponible"). ' .
            'Les retraits vendeur restent executes manuellement par un administrateur.'
        );
    }

    public function verifyWebhookSignature(string $rawBody, string $signature, string $timestamp): bool
    {
        if ($this->webhookSecret === '' || $signature === '' || $timestamp === '') {
            return false;
        }

        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->webhookSecret);

        return hash_equals($expected, $signature);
    }

    private function request(string $method, string $path, ?array $body = null): GeniusPayResult
    {
        $ch = curl_init($this->apiBase . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'X-API-Key: ' . $this->publicKey,
                'X-API-Secret: ' . $this->secretKey,
                'Content-Type: application/json',
            ],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $response = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            Logger::error('geniuspay', 'Erreur de connexion', ['path' => $path, 'error' => $curlError]);

            return GeniusPayResult::failure(0, $curlError ?: 'Erreur de connexion a Genius Pay.');
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            Logger::error('geniuspay', 'Reponse JSON invalide', ['path' => $path, 'raw' => $response]);

            return GeniusPayResult::failure($httpStatus, 'Reponse Genius Pay invalide.');
        }

        if ($httpStatus < 200 || $httpStatus >= 300 || empty($decoded['success'])) {
            $message = $decoded['error']['message'] ?? ('Erreur Genius Pay (HTTP ' . $httpStatus . ').');
            Logger::warning('geniuspay', 'Reponse en erreur', ['path' => $path, 'status' => $httpStatus, 'message' => $message]);

            return GeniusPayResult::failure($httpStatus, $message, $decoded);
        }

        return GeniusPayResult::success($httpStatus, $decoded);
    }
}
