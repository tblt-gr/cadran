<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Foundation\Infrastructure\Persistence\DbalValue;
use App\Module\Portfolio\Domain\AssetClass;
use App\Module\Portfolio\Domain\Instrument;
use App\Module\Portfolio\Domain\InstrumentRegion;
use App\Module\Portfolio\Domain\InstrumentSource;
use App\Module\Portfolio\Domain\InstrumentStatus;
use App\Module\Portfolio\Domain\InstrumentType;
use App\Module\Portfolio\Domain\Isin;
use App\Module\Portfolio\Domain\Ticker;
use App\Module\Portfolio\Domain\VenueMic;

final class InstrumentRow
{
    /** @param array<string, mixed> $row */
    public static function hydrate(array $row, WorkspaceScope $workspace): Instrument
    {
        return new Instrument(
            id: self::trimmed($row['id']),
            workspace: $workspace,
            name: self::trimmed($row['name']),
            isin: null === $row['isin'] ? null : Isin::fromString(self::trimmed($row['isin'])),
            ticker: null === $row['ticker'] ? null : Ticker::fromString(self::trimmed($row['ticker'])),
            venueMic: null === $row['venue_mic'] ? null : VenueMic::fromString(self::trimmed($row['venue_mic'])),
            instrumentType: InstrumentType::from(self::trimmed($row['instrument_type'])),
            assetClass: null === $row['asset_class'] ? null : AssetClass::from(self::trimmed($row['asset_class'])),
            region: null === $row['region'] ? null : InstrumentRegion::from(self::trimmed($row['region'])),
            currencyCode: AssetCode::fromString(self::trimmed($row['currency_code'])),
            quantityPrecision: DbalValue::integer($row['quantity_precision']),
            pricePrecision: DbalValue::integer($row['price_precision']),
            source: InstrumentSource::from(self::trimmed($row['source'])),
            reviewedAt: DbalValue::nullableInstant($row['reviewed_at']),
            reviewedBy: null === $row['reviewed_by'] ? null : self::trimmed($row['reviewed_by']),
            status: InstrumentStatus::from(self::trimmed($row['status'])),
            usedAt: DbalValue::nullableInstant($row['used_at']),
            createdAt: DbalValue::nullableInstant($row['created_at']) ?? throw new \LogicException('Instrument created_at is required.'),
            createdBy: self::trimmed($row['created_by']),
            updatedAt: DbalValue::nullableInstant($row['updated_at']) ?? throw new \LogicException('Instrument updated_at is required.'),
            version: DbalValue::integer($row['version']),
        );
    }

    /** @return array<string, mixed> */
    public static function columns(Instrument $instrument): array
    {
        return [
            'name' => $instrument->name,
            'normalized_name' => $instrument->normalizedName,
            'isin' => $instrument->isin?->toString(),
            'ticker' => $instrument->ticker?->toString(),
            'venue_mic' => $instrument->venueMic?->toString(),
            'instrument_type' => $instrument->instrumentType->value,
            'asset_class' => $instrument->assetClass?->value,
            'region' => $instrument->region?->value,
            'currency_code' => $instrument->currencyCode->toString(),
            'quantity_precision' => $instrument->quantityPrecision,
            'price_precision' => $instrument->pricePrecision,
            'source' => $instrument->source->value,
            'reviewed_at' => self::formatted($instrument->reviewedAt),
            'reviewed_by' => $instrument->reviewedBy,
            'status' => $instrument->status->value,
            'used_at' => self::formatted($instrument->usedAt),
            'updated_at' => self::formatted($instrument->updatedAt),
            'version' => $instrument->version,
        ];
    }

    /** The catalogue columns are padded `CHAR` in places, so they are read trimmed. */
    private static function trimmed(mixed $value): string
    {
        return trim(DbalValue::text($value));
    }

    private static function formatted(?\DateTimeImmutable $value): ?string
    {
        return $value?->format('Y-m-d H:i:s.uP');
    }
}
