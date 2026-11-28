<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Portfolio\Domain\Valuation\ValuationSource;

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
        /** Set by server code only, never read from an HTTP body. */
        public ValuationSource $source = ValuationSource::MANUAL,
    ) {
    }
}
