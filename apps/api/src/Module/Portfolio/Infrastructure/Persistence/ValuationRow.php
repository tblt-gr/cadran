<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\Valuation\CashSource;
use App\Module\Portfolio\Domain\Valuation\PositionSnapshot;
use App\Module\Portfolio\Domain\Valuation\PriceQuality;
use App\Module\Portfolio\Domain\Valuation\PriceSource;
use App\Module\Portfolio\Domain\Valuation\Valuation;
use App\Module\Portfolio\Domain\Valuation\ValuationCompleteness;
use App\Module\Portfolio\Domain\Valuation\ValuationDetail;
use App\Module\Portfolio\Domain\Valuation\ValuationSource;

final class ValuationRow
{
    public const string COLUMNS = 'id, account_id, valued_on, asset_code, cash_value, cash_scale, derived_cash_value, derived_cash_scale, cash_source, '
        .'securities_value_value, securities_value_scale, total_value_value, total_value_scale, observed_total_value, observed_total_scale, '
        .'detail, completeness, source, note, supersedes_id, superseded_at, withdrawn, recorded_at, recorded_by, version';

    public const string SNAPSHOT_COLUMNS = 'id, valuation_id, instrument_id, quantity_value, quantity_scale, unit_price_value, unit_price_scale, price_date, '
        .'price_source, market_value_value, market_value_scale, average_cost_basis_value, average_cost_basis_scale, price_quality';

    /**
     * @param array<string, mixed>   $row
     * @param list<PositionSnapshot> $positions
     */
    public static function hydrate(array $row, WorkspaceScope $workspace, array $positions): Valuation
    {
        return new Valuation(
            id: self::text($row['id']),
            workspace: $workspace,
            accountId: self::text($row['account_id']),
            valuedOn: self::day($row['valued_on']),
            assetCode: AssetCode::fromString(self::text($row['asset_code'])),
            cash: self::decimal($row, 'cash') ?? throw new \UnexpectedValueException('A valuation cash is required.'),
            derivedCash: self::decimal($row, 'derived_cash') ?? throw new \UnexpectedValueException('A valuation derived cash is required.'),
            cashSource: CashSource::from(self::text($row['cash_source'])),
            securitiesValue: self::decimal($row, 'securities_value'),
            totalValue: self::decimal($row, 'total_value'),
            observedTotal: self::decimal($row, 'observed_total'),
            detail: ValuationDetail::from(self::text($row['detail'])),
            completeness: ValuationCompleteness::from(self::text($row['completeness'])),
            source: ValuationSource::from(self::text($row['source'])),
            note: null === $row['note'] ? null : self::text($row['note']),
            supersedesId: null === $row['supersedes_id'] ? null : self::text($row['supersedes_id']),
            supersededAt: null === $row['superseded_at'] ? null : new \DateTimeImmutable(self::text($row['superseded_at'])),
            withdrawn: (bool) $row['withdrawn'],
            recordedAt: new \DateTimeImmutable(self::text($row['recorded_at'])),
            recordedBy: self::text($row['recorded_by']),
            version: (int) self::text($row['version']),
            positions: $positions,
        );
    }

    /** @param array<string, mixed> $row */
    public static function snapshot(array $row): PositionSnapshot
    {
        return new PositionSnapshot(
            id: self::text($row['id']),
            valuationId: self::text($row['valuation_id']),
            instrumentId: self::text($row['instrument_id']),
            quantity: self::decimal($row, 'quantity') ?? throw new \UnexpectedValueException('A snapshot quantity is required.'),
            unitPrice: self::decimal($row, 'unit_price'),
            priceDate: null === $row['price_date'] ? null : self::day($row['price_date']),
            priceSource: null === $row['price_source'] ? null : PriceSource::from(self::text($row['price_source'])),
            marketValue: self::decimal($row, 'market_value'),
            averageCostBasis: self::decimal($row, 'average_cost_basis'),
            priceQuality: PriceQuality::from(self::text($row['price_quality'])),
        );
    }

    /** @return array<string, mixed> */
    public static function columns(Valuation $valuation): array
    {
        return [
            'id' => $valuation->id,
            'account_id' => $valuation->accountId,
            'valued_on' => $valuation->valuedOn->format('Y-m-d'),
            'asset_code' => $valuation->assetCode->toString(),
            ...self::decimalColumns('cash', $valuation->cash),
            ...self::decimalColumns('derived_cash', $valuation->derivedCash),
            'cash_source' => $valuation->cashSource->value,
            ...self::decimalColumns('securities_value', $valuation->securitiesValue),
            ...self::decimalColumns('total_value', $valuation->totalValue),
            ...self::decimalColumns('observed_total', $valuation->observedTotal),
            'detail' => $valuation->detail->value,
            'completeness' => $valuation->completeness->value,
            'source' => $valuation->source->value,
            'note' => $valuation->note,
            'supersedes_id' => $valuation->supersedesId,
            ...self::supersessionColumns($valuation),
            'recorded_at' => $valuation->recordedAt->format('Y-m-d H:i:s.uP'),
            'recorded_by' => $valuation->recordedBy,
        ];
    }

    /** @return array<string, mixed> */
    public static function supersessionColumns(Valuation $valuation): array
    {
        return [
            'superseded_at' => $valuation->supersededAt?->format('Y-m-d H:i:s.uP'),
            'withdrawn' => $valuation->withdrawn,
            'version' => $valuation->version,
        ];
    }

    /** @return array<string, mixed> */
    public static function snapshotColumns(PositionSnapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'valuation_id' => $snapshot->valuationId,
            'instrument_id' => $snapshot->instrumentId,
            ...self::decimalColumns('quantity', $snapshot->quantity),
            ...self::decimalColumns('unit_price', $snapshot->unitPrice),
            'price_date' => $snapshot->priceDate?->format('Y-m-d'),
            'price_source' => $snapshot->priceSource?->value,
            ...self::decimalColumns('market_value', $snapshot->marketValue),
            ...self::decimalColumns('average_cost_basis', $snapshot->averageCostBasis),
            'price_quality' => $snapshot->priceQuality->value,
        ];
    }

    public static function text(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new \UnexpectedValueException('Expected a scalar database value.');
        }

        return trim((string) $value);
    }

    public static function day(mixed $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::text($value), new \DateTimeZone('UTC'));
    }

    /** @param array<string, mixed> $row */
    public static function decimal(array $row, string $name): ?DecimalValue
    {
        $value = $row[$name.'_value'] ?? null;
        $scale = $row[$name.'_scale'] ?? null;
        if (null === $value || null === $scale) {
            return null;
        }
        [$integer, $fraction] = array_pad(explode('.', self::text($value), 2), 2, '');
        $digits = (int) self::text($scale);

        return DecimalValue::fromString(0 === $digits ? $integer : $integer.'.'.substr(str_pad($fraction, $digits, '0'), 0, $digits));
    }

    /** @return array<string, mixed> */
    private static function decimalColumns(string $name, ?DecimalValue $value): array
    {
        return [$name.'_value' => $value?->toString(), $name.'_scale' => $value?->scale()];
    }
}
