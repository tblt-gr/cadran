<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

/** One portfolio account and its retained valuation, the only input of the holdings aggregation. */
final readonly class HoldingsAccount
{
    public function __construct(
        public string $accountId,
        public string $label,
        public string $assetCode,
        public ?ValuationView $view,
    ) {
    }
}
