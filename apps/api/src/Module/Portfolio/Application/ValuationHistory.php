<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

final readonly class ValuationHistory
{
    /** @param list<ValuationView> $points oldest first */
    public function __construct(
        public ?\DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public string $assetCode,
        public bool $truncated,
        public array $points,
    ) {
    }
}
