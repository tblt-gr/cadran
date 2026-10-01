<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

final readonly class CreateInstrumentInput
{
    public function __construct(
        public string $name,
        public ?string $isin,
        public ?string $ticker,
        public ?string $venueMic,
        public string $instrumentType,
        public ?string $assetClass,
        public ?string $region,
        public string $currencyCode,
        public ?int $quantityPrecision = null,
        public ?int $pricePrecision = null,
        public bool $confirmDuplicate = false,
    ) {
    }
}
