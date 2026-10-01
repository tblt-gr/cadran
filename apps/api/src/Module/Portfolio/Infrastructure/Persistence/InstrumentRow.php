<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;
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
            id: self::text($row['id']),
            workspace: $workspace,
            name: self::text($row['name']),
            isin: null === $row['isin'] ? null : Isin::fromString(self::text($row['isin'])),
            ticker: null === $row['ticker'] ? null : Ticker::fromString(self::text($row['ticker'])),
            venueMic: null === $row['venue_mic'] ? null : VenueMic::fromString(self::text($row['venue_mic'])),
            instrumentType: InstrumentType::from(self::text($row['instrument_type'])),
            assetClass: null === $row['asset_class'] ? null : AssetClass::from(self::text($row['asset_class'])),
            region: null === $row['region'] ? null : InstrumentRegion::from(self::text($row['region'])),
            currencyCode: AssetCode::fromString(self::text($row['currency_code'])),
            quantityPrecision: self::integer($row['quantity_precision']),
            pricePrecision: self::integer($row['price_precision']),
            source: InstrumentSource::from(self::text($row['source'])),
            reviewedAt: self::date($row['reviewed_at']),
            reviewedBy: null === $row['reviewed_by'] ? null : self::text($row['reviewed_by']),
            status: InstrumentStatus::from(self::text($row['status'])),
            usedAt: self::date($row['used_at']),
            createdAt: self::date($row['created_at']) ?? throw new \LogicException('Instrument created_at is required.'),
            createdBy: self::text($row['created_by']),
            updatedAt: self::date($row['updated_at']) ?? throw new \LogicException('Instrument updated_at is required.'),
            version: self::integer($row['version']),
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

    public static function text(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new \UnexpectedValueException('Expected a scalar database value.');
        }

        return trim((string) $value);
    }

    public static function integer(mixed $value): int
    {
        $text = self::text($value);
        if (1 !== preg_match('/^-?[0-9]+$/D', $text)) {
            throw new \UnexpectedValueException('Expected an integer database value.');
        }

        return (int) $text;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        return null === $value ? null : new \DateTimeImmutable(self::text($value));
    }

    private static function formatted(?\DateTimeImmutable $value): ?string
    {
        return $value?->format('Y-m-d H:i:s.uP');
    }
}
