<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

final readonly class ValuationInput
{
    /** @param list<PriceInput>|null $prices */
    public function __construct(
        public string $valuedOn,
        public string $detail,
        public ?AmountInput $totalValue,
        public ?array $prices,
        public ?AmountInput $observedCash,
        public ?AmountInput $observedTotal,
        public ?string $note,
        public ?int $version,
    ) {
    }
}
