<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Portfolio\Domain\Instrument;

final readonly class InstrumentView
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $isin,
        public ?string $ticker,
        public ?string $venueMic,
        public string $instrumentType,
        public ?string $assetClass,
        public ?string $region,
        public string $currencyCode,
        public int $quantityPrecision,
        public int $pricePrecision,
        public string $source,
        public bool $reviewed,
        public ?string $reviewedAt,
        public string $status,
        public bool $used,
        public int $version,
    ) {
    }

    public static function fromInstrument(Instrument $instrument): self
    {
        return new self(
            $instrument->id, $instrument->name, $instrument->isin?->toString(), $instrument->ticker?->toString(),
            $instrument->venueMic?->toString(), $instrument->instrumentType->value, $instrument->assetClass?->value,
            $instrument->region?->value, $instrument->currencyCode->toString(), $instrument->quantityPrecision,
            $instrument->pricePrecision, $instrument->source->value, null !== $instrument->reviewedAt,
            $instrument->reviewedAt?->format(\DateTimeInterface::ATOM), $instrument->status->value,
            null !== $instrument->usedAt, $instrument->version,
        );
    }
}
