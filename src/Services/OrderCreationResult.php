<?php

declare(strict_types=1);

namespace App\Services;

final class OrderCreationResult
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $totalAmount,
        public readonly array $orderItemIds
    ) {
    }
}
