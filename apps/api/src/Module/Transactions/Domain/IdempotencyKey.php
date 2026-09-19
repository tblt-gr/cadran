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
        /** @var list<string>|null business days (Y-m-d) the original write touched; null when unknown (key stored before they were recorded) */
        public ?array $periodDays = [],
    ) {
    }

    public function isCompleted(): bool
    {
        return 'COMPLETED' === $this->status;
    }
}
