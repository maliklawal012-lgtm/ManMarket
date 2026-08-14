<?php

declare(strict_types=1);

namespace App\Services;

final class PaymentCreationResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?int $paymentId,
        public readonly ?string $reference,
        public readonly ?string $paymentUrl,
        public readonly ?string $error
    ) {
    }

    public static function success(int $paymentId, string $reference, string $paymentUrl): self
    {
        return new self(true, $paymentId, $reference, $paymentUrl, null);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, null, null, $error);
    }
}
