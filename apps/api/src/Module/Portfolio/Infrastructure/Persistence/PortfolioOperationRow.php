<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\OperationDetails;
use App\Module\Portfolio\Domain\OperationFieldMatrix;
use App\Module\Portfolio\Domain\OperationState;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\OperationVoidKind;
use App\Module\Portfolio\Domain\PortfolioOperation;

final class PortfolioOperationRow
{
    public const string COLUMNS = 'id, workspace_id, account_id, operation_type, trade_date, settlement_date, sequence, instrument_id, counter_instrument_id, asset_code, '
        .'quantity_value, quantity_scale, unit_price_value, unit_price_scale, gross_amount_value, gross_amount_scale, fees_value, fees_scale, '
        .'taxes_value, taxes_scale, cash_amount_value, cash_amount_scale, cost_basis_value, cost_basis_scale, counter_quantity_value, counter_quantity_scale, '
        .'ratio_numerator_value, ratio_numerator_scale, ratio_denominator_value, ratio_denominator_scale, cost_allocation_ratio_value, cost_allocation_ratio_scale, '
        .'rounding_residual_value, rounding_residual_scale, reference, note, linked_transaction_id, state, voided_at, voided_by, void_reason, void_kind, '
        .'corrects_operation_id, created_at, created_by, version';

    /** @param array<string, mixed> $row */
    public static function hydrate(array $row, WorkspaceScope $workspace): PortfolioOperation
    {
        $type = OperationType::from(self::text($row['operation_type']));
        $storedFees = self::decimal($row, 'fees') ?? DecimalValue::zero();
        $storedTaxes = self::decimal($row, 'taxes') ?? DecimalValue::zero();
        $rules = OperationFieldMatrix::rules($type);
        $allowed = [...$rules['required'], ...$rules['optional']];
        $date = static fn (mixed $value): \DateTimeImmutable => new \DateTimeImmutable(self::text($value), new \DateTimeZone('UTC'));

        $details = new OperationDetails(
            type: $type,
            tradeDate: $date($row['trade_date']),
            settlementDate: null === $row['settlement_date'] ? null : $date($row['settlement_date']),
            instrumentId: self::nullableText($row['instrument_id']),
            counterInstrumentId: self::nullableText($row['counter_instrument_id']),
            quantity: self::decimal($row, 'quantity'),
            unitPrice: self::decimal($row, 'unit_price'),
            grossAmount: self::decimal($row, 'gross_amount'),
            // The columns are NOT NULL: an omitted fee or tax of a type that accepts one reads back as zero.
            fees: in_array('fees', $allowed, true) ? $storedFees : null,
            taxes: in_array('taxes', $allowed, true) ? $storedTaxes : null,
            costBasis: self::decimal($row, 'cost_basis'),
            counterQuantity: self::decimal($row, 'counter_quantity'),
            ratioNumerator: self::decimal($row, 'ratio_numerator'),
            ratioDenominator: self::decimal($row, 'ratio_denominator'),
            costAllocationRatio: self::decimal($row, 'cost_allocation_ratio'),
            reference: self::nullableText($row['reference']),
            note: self::nullableText($row['note']),
            linkedTransactionId: self::nullableText($row['linked_transaction_id']),
        );

        return new PortfolioOperation(
            id: self::text($row['id']),
            workspace: $workspace,
            accountId: self::text($row['account_id']),
            assetCode: AssetCode::fromString(self::text($row['asset_code'])),
            details: $details,
            sequence: (int) self::text($row['sequence']),
            cashAmount: self::decimal($row, 'cash_amount') ?? throw new \UnexpectedValueException('Operation cash amount is required.'),
            roundingResidual: self::decimal($row, 'rounding_residual'),
            state: OperationState::from(self::text($row['state'])),
            voidedAt: null === $row['voided_at'] ? null : new \DateTimeImmutable(self::text($row['voided_at'])),
            voidedBy: self::nullableText($row['voided_by']),
            voidReason: self::nullableText($row['void_reason']),
            voidKind: null === $row['void_kind'] ? null : OperationVoidKind::from(self::text($row['void_kind'])),
            correctsOperationId: self::nullableText($row['corrects_operation_id']),
            createdAt: new \DateTimeImmutable(self::text($row['created_at'])),
            createdBy: self::text($row['created_by']),
            version: (int) self::text($row['version']),
        );
    }

    /** @return array<string, mixed> */
    public static function columns(PortfolioOperation $operation): array
    {
        $details = $operation->details;
        $columns = [
            'id' => $operation->id,
            'account_id' => $operation->accountId,
            'operation_type' => $details->type->value,
            'trade_date' => $details->tradeDate->format('Y-m-d'),
            'settlement_date' => $details->settlementDate?->format('Y-m-d'),
            'sequence' => $operation->sequence,
            'instrument_id' => $details->instrumentId,
            'counter_instrument_id' => $details->counterInstrumentId,
            'asset_code' => $operation->assetCode->toString(),
            ...self::decimalColumns('quantity', $details->quantity),
            ...self::decimalColumns('unit_price', $details->unitPrice),
            ...self::decimalColumns('gross_amount', $details->grossAmount),
            ...self::decimalColumns('fees', $details->fees ?? DecimalValue::zero()),
            ...self::decimalColumns('taxes', $details->taxes ?? DecimalValue::zero()),
            ...self::decimalColumns('cash_amount', $operation->cashAmount),
            ...self::decimalColumns('cost_basis', $details->costBasis),
            ...self::decimalColumns('counter_quantity', $details->counterQuantity),
            ...self::decimalColumns('ratio_numerator', $details->ratioNumerator),
            ...self::decimalColumns('ratio_denominator', $details->ratioDenominator),
            ...self::decimalColumns('cost_allocation_ratio', $details->costAllocationRatio),
            ...self::decimalColumns('rounding_residual', $operation->roundingResidual),
            'reference' => $details->reference,
            'note' => $details->note,
            'linked_transaction_id' => $details->linkedTransactionId,
            'created_at' => $operation->createdAt->format('Y-m-d H:i:s.uP'),
            'created_by' => $operation->createdBy,
        ];

        return [...$columns, ...self::voidColumns($operation)];
    }

    /** @return array<string, mixed> */
    public static function voidColumns(PortfolioOperation $operation): array
    {
        return [
            'state' => $operation->state->value,
            'voided_at' => $operation->voidedAt?->format('Y-m-d H:i:s.uP'),
            'voided_by' => $operation->voidedBy,
            'void_reason' => $operation->voidReason,
            'void_kind' => $operation->voidKind?->value,
            'corrects_operation_id' => $operation->correctsOperationId,
            'version' => $operation->version,
        ];
    }

    public static function text(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new \UnexpectedValueException('Expected a scalar database value.');
        }

        return trim((string) $value);
    }

    public static function nullableText(mixed $value): ?string
    {
        return null === $value ? null : self::text($value);
    }

    /** @return array<string, mixed> */
    private static function decimalColumns(string $name, ?DecimalValue $value): array
    {
        return [$name.'_value' => $value?->toString(), $name.'_scale' => $value?->scale()];
    }

    /** @param array<string, mixed> $row */
    private static function decimal(array $row, string $name): ?DecimalValue
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
}
