<?php

declare(strict_types=1);

namespace App\Services;

final class WithdrawalResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?int $withdrawalId,
        public readonly ?string $reference,
        public readonly ?string $error
    ) {
    }

    public static function success(int $withdrawalId, string $reference): self
    {
        return new self(true, $withdrawalId, $reference, null);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, null, $error);
    }
}
