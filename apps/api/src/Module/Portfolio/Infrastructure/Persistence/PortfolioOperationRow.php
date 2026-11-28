<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Foundation\Infrastructure\Persistence\DbalValue;
use App\Module\Portfolio\Domain\OperationDetails;
use App\Module\Portfolio\Domain\OperationFieldMatrix;
use App\Module\Portfolio\Domain\OperationLeg;
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
        .'corrects_operation_id, created_at, created_by, version, withheld_income_tax_value, withheld_income_tax_scale, withheld_social_contributions_value, withheld_social_contributions_scale, interest_basis, rate_year';

    /** @param array<string, mixed> $row
     * @param list<OperationLeg> $legs
     */
    public static function hydrate(array $row, WorkspaceScope $workspace, array $legs = []): PortfolioOperation
    {
        $type = OperationType::from(DbalValue::text($row['operation_type']));
        $storedFees = self::decimal($row, 'fees') ?? DecimalValue::zero();
        $storedTaxes = self::decimal($row, 'taxes') ?? DecimalValue::zero();
        $rules = OperationFieldMatrix::rules($type);
        $allowed = [...$rules['required'], ...$rules['optional']];

        $details = new OperationDetails(
            interestBasis: null === ($row['interest_basis'] ?? null) ? null : \App\Module\Portfolio\Domain\LifeInsurance\InterestBasis::from(DbalValue::text($row['interest_basis'])),
            rateYear: null === ($row['rate_year'] ?? null) ? null : (int) DbalValue::text($row['rate_year']),
            type: $type,
            tradeDate: DbalValue::businessDay($row['trade_date']),
            settlementDate: null === $row['settlement_date'] ? null : DbalValue::businessDay($row['settlement_date']),
            instrumentId: DbalValue::nullableText($row['instrument_id']),
            counterInstrumentId: DbalValue::nullableText($row['counter_instrument_id']),
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
            reference: DbalValue::nullableText($row['reference']),
            note: DbalValue::nullableText($row['note']),
            linkedTransactionId: DbalValue::nullableText($row['linked_transaction_id']),
            legs: $legs,
            withheldIncomeTax: self::decimal($row, 'withheld_income_tax'),
            withheldSocialContributions: self::decimal($row, 'withheld_social_contributions'),
            stored: true,
        );

        return new PortfolioOperation(
            id: DbalValue::text($row['id']),
            workspace: $workspace,
            accountId: DbalValue::text($row['account_id']),
            assetCode: AssetCode::fromString(DbalValue::text($row['asset_code'])),
            details: $details,
            sequence: (int) DbalValue::text($row['sequence']),
            cashAmount: self::decimal($row, 'cash_amount') ?? throw new \UnexpectedValueException('Operation cash amount is required.'),
            roundingResidual: self::decimal($row, 'rounding_residual'),
            state: OperationState::from(DbalValue::text($row['state'])),
            voidedAt: null === $row['voided_at'] ? null : new \DateTimeImmutable(DbalValue::text($row['voided_at'])),
            voidedBy: DbalValue::nullableText($row['voided_by']),
            voidReason: DbalValue::nullableText($row['void_reason']),
            voidKind: null === $row['void_kind'] ? null : OperationVoidKind::from(DbalValue::text($row['void_kind'])),
            correctsOperationId: DbalValue::nullableText($row['corrects_operation_id']),
            createdAt: new \DateTimeImmutable(DbalValue::text($row['created_at'])),
            createdBy: DbalValue::text($row['created_by']),
            version: (int) DbalValue::text($row['version']),
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
            'interest_basis' => $details->interestBasis?->value,
            'rate_year' => $details->rateYear,
            'trade_date' => $details->tradeDate->format('Y-m-d'),
            'settlement_date' => $details->settlementDate?->format('Y-m-d'),
            'sequence' => $operation->sequence,
            'instrument_id' => $details->instrumentId,
            'counter_instrument_id' => $details->counterInstrumentId,
            'asset_code' => $operation->assetCode->toString(),
            ...self::decimalColumns('quantity', $details->quantity),
            ...self::decimalColumns('unit_price', $details->unitPrice),
            ...self::decimalColumns('withheld_income_tax', $details->withheldIncomeTax),
            ...self::decimalColumns('withheld_social_contributions', $details->withheldSocialContributions),
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

    /** @return array<string, mixed> */
    public static function decimalColumns(string $name, ?DecimalValue $value): array
    {
        return [$name.'_value' => $value?->toString(), $name.'_scale' => $value?->scale()];
    }

    /** @param array<string, mixed> $row a stored column pair that is never null: fees and taxes are stored as zero when absent */
    public static function storedDecimal(array $row, string $name): DecimalValue
    {
        return self::decimal($row, $name) ?? DecimalValue::zero();
    }

    /** @param array<string, mixed> $row */
    public static function decimal(array $row, string $name): ?DecimalValue
    {
        $value = $row[$name.'_value'] ?? null;
        $scale = $row[$name.'_scale'] ?? null;
        if (null === $value || null === $scale) {
            return null;
        }
        [$integer, $fraction] = array_pad(explode('.', DbalValue::text($value), 2), 2, '');
        $digits = (int) DbalValue::text($scale);

        return DecimalValue::fromString(0 === $digits ? $integer : $integer.'.'.substr(str_pad($fraction, $digits, '0'), 0, $digits));
    }
}
