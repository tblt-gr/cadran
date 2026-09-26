<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

/** Computes the monthly KPIs under one resolved metric policy. */
final class MonthlyProjectionCalculator
{
    /**
     * @param list<string>          $accountAssets
     * @param list<MonthlyMovement> $movements                booked, live source movements only
     * @param array<string, bool>   $budgetIncludedByCategory
     * @param ?MetricPolicy         $policy                   null when the governing version cannot be loaded
     * @param list<MonthlyMovement> $pendingMovements
     */
    public static function compute(
        array $accountAssets,
        array $movements,
        array $budgetIncludedByCategory,
        ?MetricPolicy $policy,
        array $pendingMovements = [],
    ): MonthlyTransactionMetrics {
        $known = $policy ?? MetricPolicy::systemV1();
        [$sources] = self::provenance($movements, $budgetIncludedByCategory, $known);
        [, $pendingCounts] = self::provenance($pendingMovements, $budgetIncludedByCategory, $known);
        $assets = array_values(array_unique($accountAssets));
        if (count($assets) > 1) {
            return self::allMissing(MonthlyProjectionReason::MIXED_ASSETS, $sources, $pendingCounts);
        }
        if ([] === $assets) {
            return self::allMissing(MonthlyProjectionReason::NO_ACCOUNT, $sources, $pendingCounts);
        }

        $asset = AssetCode::fromString($assets[0]);
        $income = DecimalValue::zero();
        $nonCash = DecimalValue::zero();
        $benefitSigned = DecimalValue::zero();
        $expenseSigned = DecimalValue::zero();
        $uncategorizedSigned = DecimalValue::zero();
        $savings = DecimalValue::zero();

        foreach ($movements as $movement) {
            if (!$movement->asset->equals($asset)) {
                return self::allMissing(MonthlyProjectionReason::MIXED_ASSETS, $sources, $pendingCounts);
            }

            if ($known->isExcluded($movement->accountKind)) {
                if (MonthlyMovementKind::INCOME === $movement->kind) {
                    $nonCash = ExactDecimal::add($nonCash, $movement->amount);
                    continue;
                }
                if (self::contributesToBudgetExpenses($movement->kind)) {
                    $benefitSigned = ExactDecimal::add($benefitSigned, $movement->amount);
                    continue;
                }
            }

            if (MonthlyMovementKind::INCOME === $movement->kind) {
                $income = ExactDecimal::add($income, $movement->amount);
                continue;
            }

            if (MonthlyMovementKind::TRANSFER === $movement->kind) {
                if ($movement->savingsDestination && !$movement->amount->isNegative()) {
                    $savings = ExactDecimal::add($savings, $movement->amount);
                }
                continue;
            }

            if (!self::contributesToBudgetExpenses($movement->kind)) {
                continue;
            }

            if ([] === $movement->splits) {
                $expenseSigned = ExactDecimal::add($expenseSigned, $movement->amount);
                $uncategorizedSigned = ExactDecimal::add($uncategorizedSigned, $movement->amount);
                continue;
            }

            foreach ($movement->splits as $split) {
                if ($budgetIncludedByCategory[$split->categoryId] ?? false) {
                    $expenseSigned = ExactDecimal::add($expenseSigned, $split->amount);
                }
            }
        }

        $expenses = ExactDecimal::negate($expenseSigned);
        $uncategorized = ExactDecimal::negate($uncategorizedSigned);
        $surplus = ExactDecimal::subtract($income, $expenses);
        $rate = ExactDecimal::isZero($income)
            ? MonthlyMetric::missing(MonthlyProjectionReason::ZERO_CASH_INCOME, $sources['cashSavingsRate'], $pendingCounts['cashSavingsRate'])
            : MonthlyMetric::value(ExactDecimal::divide($surplus, $income), sourceTransactionIds: $sources['cashSavingsRate'], pendingCount: $pendingCounts['cashSavingsRate']);

        $savingsMetric = MonthlyMetric::value($savings, $asset, $sources['savingsTransfers'], $pendingCounts['savingsTransfers']);
        if (null === $policy) {
            $unknown = static fn (): MonthlyMetric => MonthlyMetric::missing(MonthlyProjectionReason::UNKNOWN_METRIC_POLICY);

            return new MonthlyTransactionMetrics($unknown(), $unknown(), $unknown(), $unknown(), $unknown(), $unknown(), $savingsMetric, $unknown());
        }

        return new MonthlyTransactionMetrics(
            MonthlyMetric::value($income, $asset, $sources['cashIncome'], $pendingCounts['cashIncome']),
            MonthlyMetric::value($nonCash, $asset, $sources['nonCashBenefits'], $pendingCounts['nonCashBenefits']),
            MonthlyMetric::value(ExactDecimal::negate($benefitSigned), $asset, $sources['benefitSpending'], $pendingCounts['benefitSpending']),
            MonthlyMetric::value($expenses, $asset, $sources['budgetExpenses'], $pendingCounts['budgetExpenses']),
            MonthlyMetric::value($uncategorized, $asset, $sources['uncategorizedExpenses'], $pendingCounts['uncategorizedExpenses']),
            MonthlyMetric::value($surplus, $asset, $sources['budgetSurplus'], $pendingCounts['budgetSurplus']),
            $savingsMetric,
            $rate,
        );
    }

    public static function contributesToBudgetExpenses(MonthlyMovementKind $kind): bool
    {
        return in_array($kind, [MonthlyMovementKind::EXPENSE, MonthlyMovementKind::FEE, MonthlyMovementKind::REFUND], true);
    }

    /**
     * @param list<MonthlyMovement> $movements
     * @param array<string, bool>   $budgetIncludedByCategory
     *
     * @return array{array<string, list<string>>, array<string, int>}
     */
    private static function provenance(array $movements, array $budgetIncludedByCategory, MetricPolicy $policy): array
    {
        $sourceSets = [
            'cashIncome' => [],
            'nonCashBenefits' => [],
            'benefitSpending' => [],
            'budgetExpenses' => [],
            'uncategorizedExpenses' => [],
            'budgetSurplus' => [],
            'savingsTransfers' => [],
            'cashSavingsRate' => [],
        ];
        $counts = array_fill_keys(array_keys($sourceSets), 0);
        foreach ($movements as $movement) {
            foreach (self::metricKeys($movement, $budgetIncludedByCategory, $policy) as $metricKey) {
                ++$counts[$metricKey];
                self::addSource($sourceSets[$metricKey], $movement->transactionId);
            }
        }

        $sources = [];
        foreach ($sourceSets as $metricKey => $sourceSet) {
            $sources[$metricKey] = self::sortedSources($sourceSet);
        }

        return [$sources, $counts];
    }

    /**
     * @param array<string, bool> $budgetIncludedByCategory
     *
     * @return list<string>
     */
    private static function metricKeys(MonthlyMovement $movement, array $budgetIncludedByCategory, MetricPolicy $policy): array
    {
        if ($policy->isExcluded($movement->accountKind)) {
            if (MonthlyMovementKind::INCOME === $movement->kind) {
                return ['nonCashBenefits'];
            }
            if (self::contributesToBudgetExpenses($movement->kind)) {
                return ['benefitSpending'];
            }
        }
        if (MonthlyMovementKind::INCOME === $movement->kind) {
            return ['cashIncome', 'budgetSurplus', 'cashSavingsRate'];
        }
        if (MonthlyMovementKind::TRANSFER === $movement->kind) {
            return $movement->savingsDestination && !$movement->amount->isNegative() ? ['savingsTransfers'] : [];
        }
        if (!self::contributesToBudgetExpenses($movement->kind)) {
            return [];
        }
        if ([] === $movement->splits) {
            return ['budgetExpenses', 'uncategorizedExpenses', 'budgetSurplus', 'cashSavingsRate'];
        }
        foreach ($movement->splits as $split) {
            if ($budgetIncludedByCategory[$split->categoryId] ?? false) {
                return ['budgetExpenses', 'budgetSurplus', 'cashSavingsRate'];
            }
        }

        return [];
    }

    /** @param array<string, true> $sources */
    private static function addSource(array &$sources, string $transactionId): void
    {
        if ('' !== $transactionId) {
            $sources[$transactionId] = true;
        }
    }

    /**
     * @param array<string, true> $sources
     *
     * @return list<string>
     */
    private static function sortedSources(array $sources): array
    {
        $identifiers = array_keys($sources);
        sort($identifiers, SORT_STRING);

        return $identifiers;
    }

    /**
     * @param array<string, list<string>> $sources
     * @param array<string, int>          $pendingCounts
     */
    private static function allMissing(
        MonthlyProjectionReason $reason,
        array $sources,
        array $pendingCounts,
    ): MonthlyTransactionMetrics {
        return new MonthlyTransactionMetrics(
            MonthlyMetric::missing($reason, $sources['cashIncome'], $pendingCounts['cashIncome']),
            MonthlyMetric::missing($reason, $sources['nonCashBenefits'], $pendingCounts['nonCashBenefits']),
            MonthlyMetric::missing($reason, $sources['benefitSpending'], $pendingCounts['benefitSpending']),
            MonthlyMetric::missing($reason, $sources['budgetExpenses'], $pendingCounts['budgetExpenses']),
            MonthlyMetric::missing($reason, $sources['uncategorizedExpenses'], $pendingCounts['uncategorizedExpenses']),
            MonthlyMetric::missing($reason, $sources['budgetSurplus'], $pendingCounts['budgetSurplus']),
            MonthlyMetric::missing($reason, $sources['savingsTransfers'], $pendingCounts['savingsTransfers']),
            MonthlyMetric::missing($reason, $sources['cashSavingsRate'], $pendingCounts['cashSavingsRate']),
        );
    }
}
