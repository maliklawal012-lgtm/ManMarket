<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/geniuspay.php';

/**
 * Effectue une requete HTTP vers l'API GeniusPay.
 * Retourne ['ok' => bool, 'status' => int, 'body' => array|null, 'error' => string|null].
 */
function geniuspay_request(string $method, string $path, ?array $body = null): array
{
    $ch = curl_init(GENIUSPAY_API_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'X-API-Key: ' . GENIUSPAY_PUBLIC_KEY,
            'X-API-Secret: ' . GENIUSPAY_SECRET_KEY,
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
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $curlError ?: 'Erreur de connexion à GeniusPay.'];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'status' => $httpStatus, 'body' => null, 'error' => 'Réponse GeniusPay invalide.'];
    }

    if ($httpStatus < 200 || $httpStatus >= 300 || empty($decoded['success'])) {
        $message = $decoded['error']['message'] ?? 'Erreur GeniusPay (HTTP ' . $httpStatus . ').';
        return ['ok' => false, 'status' => $httpStatus, 'body' => $decoded, 'error' => $message];
    }

    return ['ok' => true, 'status' => $httpStatus, 'body' => $decoded, 'error' => null];
}

/**
 * Cree un paiement GeniusPay et retourne les donnees de la transaction (reference, payment_url, ...).
 * $params attend : amount (int, XOF), description, customer (name/email/phone), success_url, error_url, metadata.
 */
function geniuspay_create_payment(array $params): array
{
    return geniuspay_request('POST', '/payments', $params);
}

/**
 * Recupere le statut actuel d'un paiement via sa reference (MTX-...).
 */
function geniuspay_get_payment(string $reference): array
{
    return geniuspay_request('GET', '/payments/' . rawurlencode($reference));
}

/**
 * Verifie la signature HMAC-SHA256 d'un webhook GeniusPay.
 * signature = HMAC-SHA256(timestamp + "." + json_payload, webhook_secret)
 */
function geniuspay_verify_webhook_signature(string $rawBody, string $signature, string $timestamp): bool
{
    if (GENIUSPAY_WEBHOOK_SECRET === '' || $signature === '' || $timestamp === '') {
        return false;
    }

    if (abs(time() - (int) $timestamp) > 300) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, GENIUSPAY_WEBHOOK_SECRET);

    return hash_equals($expected, $signature);
}
