<?php

declare(strict_types=1);

namespace App\Services;

final class SettlementResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly bool $alreadySettled,
        public readonly ?string $error,
        public readonly array $vendorShares
    ) {
    }

    public static function success(array $vendorShares): self
    {
        return new self(true, false, null, $vendorShares);
    }

    public static function alreadySettled(): self
    {
        return new self(true, true, null, []);
    }

    public static function failure(string $error): self
    {
        return new self(false, false, $error, []);
    }
}
