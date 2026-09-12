<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

final readonly class IdempotencyKey
{
    /**
     * @param array<string, mixed>|null $responseBody
     */
    public function __construct(
        public string $id,
        public string $fingerprint,
        public string $status,
        public ?int $responseStatus,
        public ?array $responseBody,
        public bool $claimed,
    ) {
    }

    public function isCompleted(): bool
    {
        return 'COMPLETED' === $this->status;
    }
}
