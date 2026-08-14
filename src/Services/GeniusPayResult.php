<?php

declare(strict_types=1);

namespace App\Services;

final class GeniusPayResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly int $httpStatus,
        public readonly ?array $body,
        public readonly ?string $error
    ) {
    }

    public static function success(int $httpStatus, array $body): self
    {
        return new self(true, $httpStatus, $body, null);
    }

    public static function failure(int $httpStatus, string $error, ?array $body = null): self
    {
        return new self(false, $httpStatus, $body, $error);
    }

    public function data(): array
    {
        return $this->body['data'] ?? [];
    }
}
