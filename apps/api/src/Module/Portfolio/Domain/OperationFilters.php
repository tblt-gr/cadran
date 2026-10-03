<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

final readonly class OperationFilters
{
    /** @param list<OperationType> $types */
    public function __construct(
        public ?\DateTimeImmutable $from,
        public ?\DateTimeImmutable $to,
        public array $types,
        public ?string $instrumentId,
        public bool $includeVoided,
    ) {
    }
}
