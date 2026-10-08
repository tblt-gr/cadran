<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\LifeInsurance;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperation;

/**
 * Observed income and deductions only; announced rates identify missing evidence, never amounts.
 *
 * @phpstan-type AnnualIncome array{year: int, assetCode: string, interest: array<string, string>, profitSharing: array<string, string>, fees: array<string, string>, withheldTaxes: array<string, string>, qualityIssues: list<array{code: string, operationIds: list<string>, count: int}>, sources: list<array{kind: string, id: string, date: string}>, sourceCount: int}
 */
final class IncomeAndFeesCalculator
{
    /** @param list<PortfolioOperation> $operations
     * @param list<EuroFundRate> $rates
     *
     * @return array{year: int, assetCode: string, interest: array<string, string>, profitSharing: array<string, string>, fees: array<string, string>, withheldTaxes: array<string, string>, qualityIssues: list<array{code: string, operationIds: list<string>, count: int}>, sources: list<array{kind: string, id: string, date: string}>, sourceCount: int}
     */
    public static function calculate(int $year, string $assetCode, array $operations, array $rates): array
    {
        $zero = DecimalValue::zero();
        $interest = $profit = ['gross' => $zero, 'withheld' => $zero, 'net' => $zero];
        $fees = array_fill_keys(['ENTRY', 'MANAGEMENT', 'SWITCH', 'SURRENDER', 'OTHER', 'total'], $zero);
        $taxes = array_fill_keys(['INCOME_TAX', 'SOCIAL_CONTRIBUTIONS', 'OTHER', 'total'], $zero);
        $known = [];
        foreach ($rates as $rate) {
            $known[$rate->instrumentId.':'.$rate->year] = true;
        }
        $issues = [];
        $sources = [];
        $sourceCount = 0;
        foreach ($operations as $operation) {
            $details = $operation->details;
            if (!$operation->isActive() || (int) $details->tradeDate->format('Y') !== $year) {
                continue;
            }
            ++$sourceCount;
            if (count($sources) < 20) {
                $sources[] = ['kind' => 'OPERATION', 'id' => $operation->id, 'date' => $details->tradeDate->format('Y-m-d')];
            }
            $nature = FeeNature::of($operation);
            if (null !== $nature) {
                $fees[$nature->value] = ExactDecimal::add($fees[$nature->value], $details->fees ?? $zero);
            }
            $fees['total'] = ExactDecimal::add($fees['total'], $details->fees ?? $zero);
            $taxes['total'] = ExactDecimal::add($taxes['total'], $details->taxes ?? $zero);
            if ($details->type->isSurrender()) {
                $taxes['INCOME_TAX'] = ExactDecimal::add($taxes['INCOME_TAX'], $details->withheldIncomeTax ?? $zero);
                $taxes['SOCIAL_CONTRIBUTIONS'] = ExactDecimal::add($taxes['SOCIAL_CONTRIBUTIONS'], $details->withheldSocialContributions ?? $zero);
            } else {
                $kind = $details->type->isCreditedInterest() || OperationType::SOCIAL_CONTRIBUTION === $details->type ? 'SOCIAL_CONTRIBUTIONS' : 'OTHER';
                $taxes[$kind] = ExactDecimal::add($taxes[$kind], $details->taxes ?? $zero);
            }
            if (!$details->type->isCreditedInterest()) {
                continue;
            }
            $income = OperationType::EURO_FUND_INTEREST === $details->type ? $interest : $profit;
            $income['gross'] = ExactDecimal::add($income['gross'], $details->grossAmount ?? $zero);
            $income['withheld'] = ExactDecimal::add($income['withheld'], $details->taxes ?? $zero);
            $income['net'] = ExactDecimal::add($income['net'], $details->netCreditedAmount() ?? $zero);
            if (OperationType::EURO_FUND_INTEREST === $details->type) {
                $interest = $income;
            } else {
                $profit = $income;
            }
            if (InterestBasis::UNKNOWN === $details->interestBasis) {
                self::issue($issues, 'INTEREST_BASIS_UNKNOWN', 'INTEREST_BASIS_UNKNOWN', $operation->id);
            }
            $key = $details->instrumentId.':'.$details->rateYear;
            if (!isset($known[$key])) {
                self::issue($issues, 'MISSING_EURO_FUND_RATE:'.$key, 'MISSING_EURO_FUND_RATE', $operation->id);
            }
        }
        $format = static fn (DecimalValue $value): string => $value->toString();

        return ['year' => $year, 'assetCode' => $assetCode,
            'interest' => array_map($format, $interest), 'profitSharing' => array_map($format, $profit),
            'fees' => array_map($format, $fees), 'withheldTaxes' => array_map($format, $taxes),
            'qualityIssues' => array_values($issues), 'sources' => $sources, 'sourceCount' => $sourceCount];
    }

    /** @param array<string, array{code: string, operationIds: list<string>, count: int}> $issues */
    private static function issue(array &$issues, string $key, string $code, string $id): void
    {
        $issues[$key] ??= ['code' => $code, 'operationIds' => [], 'count' => 0];
        ++$issues[$key]['count'];
        if (count($issues[$key]['operationIds']) < 20) {
            $issues[$key]['operationIds'][] = $id;
        }
    }
}
