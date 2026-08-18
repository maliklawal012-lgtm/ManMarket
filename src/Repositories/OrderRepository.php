<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class OrderRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /** Verrouille la ligne pour la duree de la transaction en cours (SELECT ... FOR UPDATE). */
    public function findByIdForUpdate(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function findByCustomerUserId(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE customer_user_id = :user_id ORDER BY created_at DESC');
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO orders (customer_user_id, customer_name, customer_email, customer_phone, delivery_location, subtotal_amount, delivery_fee_amount, online_payment_fee_amount, total_amount, currency, payment_choice)
            VALUES (:customer_user_id, :customer_name, :customer_email, :customer_phone, :delivery_location, :subtotal_amount, :delivery_fee_amount, :online_payment_fee_amount, :total_amount, :currency, :payment_choice)
        ');
        $stmt->execute([
            'customer_user_id' => $data['customer_user_id'] ?? null,
            'customer_name' => $data['customer_name'],
            'customer_email' => $data['customer_email'],
            'customer_phone' => $data['customer_phone'] ?? null,
            'delivery_location' => $data['delivery_location'] ?? null,
            'subtotal_amount' => $data['subtotal_amount'],
            'delivery_fee_amount' => $data['delivery_fee_amount'] ?? 0,
            'online_payment_fee_amount' => $data['online_payment_fee_amount'] ?? 0,
            'total_amount' => $data['total_amount'],
            'currency' => $data['currency'] ?? 'XOF',
            'payment_choice' => $data['payment_choice'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function setPaymentStatus(int $orderId, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE orders SET payment_status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $orderId]);
    }

    public function setFulfillmentStatus(int $orderId, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE orders SET fulfillment_status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $orderId]);
    }

    public function delete(int $orderId, int $customerUserId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM orders WHERE id = :id AND customer_user_id = :user_id');
        $stmt->execute(['id' => $orderId, 'user_id' => $customerUserId]);

        return $stmt->rowCount() > 0;
    }
}
