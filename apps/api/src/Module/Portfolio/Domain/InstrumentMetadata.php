<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

final readonly class InstrumentMetadata
{
    public function __construct(
        public ?string $name,
        public ?Ticker $ticker,
        public ?VenueMic $venueMic,
        public ?InstrumentType $instrumentType,
        public ?AssetClass $assetClass,
        public ?InstrumentRegion $region,
    ) {
    }
}
