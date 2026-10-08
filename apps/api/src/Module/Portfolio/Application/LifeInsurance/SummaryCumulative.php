<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Portfolio\Domain\Cash\CashLedger;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperation;

/**
 * @phpstan-import-type AnnualIncome from \App\Module\Portfolio\Domain\LifeInsurance\IncomeAndFeesCalculator
 *
 * @phpstan-type TaxYear array{year: int, premiums: string, surrendersGross: string, surrendersNetPaid: string, withheldIncomeTax: string, withheldSocialContributions: string}
 */
final class SummaryCumulative
{
    /** @param list<PortfolioOperation> $operations
     * @param AnnualIncome $annual
     *
     * @return TaxYear
     */
    public static function taxYear(int $year, array $operations, array $annual): array
    {
        $flows = array_values(array_filter($operations, static fn (PortfolioOperation $operation): bool => OperationType::PREMIUM === $operation->details->type || $operation->details->type->isSurrender()));
        $totals = CashLedger::replay([], $flows)->totals;
        $gross = '0';
        foreach ($flows as $operation) {
            if ($operation->details->type->isSurrender()) {
                $gross = ExactDecimal::addForResponse($gross, $operation->details->grossAmount?->toString() ?? '0');
            }
        }

        return ['year' => $year, 'premiums' => $totals->deposits->toString(), 'surrendersGross' => $gross,
            'surrendersNetPaid' => $totals->withdrawals->toString(), 'withheldIncomeTax' => $annual['withheldTaxes']['INCOME_TAX'],
            'withheldSocialContributions' => $annual['withheldTaxes']['SOCIAL_CONTRIBUTIONS']];
    }

    /** @param list<TaxYear> $years
     * @param list<AnnualIncome> $annual
     *
     * @return array<string, mixed>
     */
    public static function of(array $years, array $annual): array
    {
        $result = ['premiums' => '0', 'surrendersGross' => '0', 'surrendersNetPaid' => '0',
            'fees' => array_fill_keys(['ENTRY', 'MANAGEMENT', 'SWITCH', 'SURRENDER', 'OTHER', 'total'], '0'),
            'withheldTaxes' => array_fill_keys(['INCOME_TAX', 'SOCIAL_CONTRIBUTIONS', 'OTHER', 'total'], '0'),
            'interestCredited' => ['gross' => '0', 'net' => '0'], 'profitSharing' => ['gross' => '0', 'net' => '0'], 'sources' => [], 'sourceCount' => 0];
        foreach ($years as $year) {
            foreach (['premiums', 'surrendersGross', 'surrendersNetPaid'] as $key) {
                $result[$key] = ExactDecimal::addForResponse($result[$key], $year[$key]);
            }
        }
        foreach ($annual as $year) {
            foreach (['fees' => 'fees', 'withheldTaxes' => 'withheldTaxes', 'interestCredited' => 'interest', 'profitSharing' => 'profitSharing'] as $key => $source) {
                foreach ($result[$key] as $kind => $value) {
                    $result[$key][$kind] = ExactDecimal::addForResponse($value, $year[$source][$kind]);
                }
            }
            $result['sources'] = array_slice([...$result['sources'], ...$year['sources']], 0, 20);
            $result['sourceCount'] += $year['sourceCount'];
        }

        return $result;
    }

    /** @param list<AnnualIncome> $annual
     * @return list<array{code: string, operationIds: list<string>, count: int}>
     */
    public static function issues(array $annual): array
    {
        $issues = [];
        foreach ($annual as $year) {
            foreach ($year['qualityIssues'] as $issue) {
                $code = $issue['code'];
                $issues[$code] ??= ['code' => $code, 'operationIds' => [], 'count' => 0];
                $issues[$code]['count'] += $issue['count'];
                $issues[$code]['operationIds'] = array_slice([...$issues[$code]['operationIds'], ...$issue['operationIds']], 0, 20);
            }
        }

        return array_values($issues);
    }
}
