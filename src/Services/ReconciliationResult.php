<?php

declare(strict_types=1);

namespace App\Services;

final class ReconciliationResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly int $expectedTotal,
        public readonly int $computedTotal,
        public readonly string $message
    ) {
    }

    public static function success(int $expectedTotal, int $computedTotal): self
    {
        return new self(true, $expectedTotal, $computedTotal, 'OK');
    }

    public static function failure(int $expectedTotal, int $computedTotal, string $message): self
    {
        return new self(false, $expectedTotal, $computedTotal, $message);
    }
}
