<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Portfolio\Domain\AssetClass;
use App\Module\Portfolio\Domain\InstrumentRegion;
use App\Module\Portfolio\Domain\InstrumentType;
use App\Module\Portfolio\Domain\Isin;
use App\Module\Portfolio\Domain\Ticker;
use App\Module\Portfolio\Domain\VenueMic;

final readonly class ParsedInstrumentInput
{
    public function __construct(
        public string $name,
        public ?Isin $isin,
        public ?Ticker $ticker,
        public ?VenueMic $venueMic,
        public InstrumentType $instrumentType,
        public ?AssetClass $assetClass,
        public ?InstrumentRegion $region,
        public AssetCode $currencyCode,
        public int $quantityPrecision,
        public int $pricePrecision,
    ) {
    }
}
