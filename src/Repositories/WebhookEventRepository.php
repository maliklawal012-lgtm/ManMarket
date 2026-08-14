<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class WebhookEventRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function exists(string $provider, string $eventId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM webhook_events WHERE provider = :provider AND event_id = :event_id');
        $stmt->execute(['provider' => $provider, 'event_id' => $eventId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Insere l'evenement. Retourne false si event_id deja present (idempotence) —
     * s'appuie sur la contrainte UNIQUE, pas sur exists() seul, pour eviter une
     * fenetre de course entre deux requetes webhook simultanees.
     */
    public function record(string $provider, string $eventId, string $eventType, array $payload, bool $signatureValid): bool
    {
        try {
            $stmt = $this->db->prepare('
                INSERT INTO webhook_events (provider, event_id, event_type, payload, signature_valid)
                VALUES (:provider, :event_id, :event_type, :payload, :sig)
            ');
            $stmt->execute([
                'provider' => $provider,
                'event_id' => $eventId,
                'event_type' => $eventType,
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'sig' => $signatureValid ? 1 : 0,
            ]);

            return true;
        } catch (\PDOException $e) {
            if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                return false;
            }
            throw $e;
        }
    }

    public function markProcessed(string $provider, string $eventId, ?string $errorMessage = null): void
    {
        $stmt = $this->db->prepare('
            UPDATE webhook_events
            SET processed = 1, processed_at = CURRENT_TIMESTAMP, error_message = :error
            WHERE provider = :provider AND event_id = :event_id
        ');
        $stmt->execute(['error' => $errorMessage, 'provider' => $provider, 'event_id' => $eventId]);
    }
}
