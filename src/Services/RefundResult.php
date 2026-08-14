<?php

declare(strict_types=1);

namespace App\Services;

final class RefundResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?int $refundId,
        public readonly ?string $error
    ) {
    }

    public static function success(int $refundId): self
    {
        return new self(true, $refundId, null);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, $error);
    }
}
