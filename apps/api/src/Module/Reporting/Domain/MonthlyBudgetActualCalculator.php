<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\ExactDecimal;

/** Filters one budget scope around the single built-in monthly KPI policy. */
final class MonthlyBudgetActualCalculator
{
    public const string POLICY_DESCRIPTION = 'BOOKED non-voided EXPENSE, FEE and REFUND split amounts on accounts inside the cash perimeter of the governing metric policy, in budget-included categories; PENDING rows are counted but excluded from sums; TRANSFER and ADJUSTMENT rows are excluded.';

    /**
     * @param list<MonthlyMovement>       $booked
     * @param list<MonthlyMovement>       $pending
     * @param array<string, bool>         $budgetIncludedByCategory
     * @param array<string, list<string>> $ancestorIdsByCategory
     */
    public static function compute(
        array $booked,
        array $pending,
        array $budgetIncludedByCategory,
        array $ancestorIdsByCategory,
        string $scopeType,
        string $scopeId,
        string $fallbackAsset,
        bool $hasAccounts,
    ): MonthlyBudgetActual {
        $sum = '0';
        /** @var array<string, true> $assets */
        $assets = [];
        /** @var array<string, MonthlyBudgetActualSource> $sources */
        $sources = [];
        foreach ($booked as $movement) {
            $selected = self::selectedSplits(
                $movement,
                $budgetIncludedByCategory,
                $ancestorIdsByCategory,
                $scopeType,
                $scopeId,
            );
            if ([] === $selected) {
                continue;
            }
            $assets[$movement->asset->toString()] = true;
            $selectedAmount = '0';
            foreach ($selected as $split) {
                $sum = ExactDecimal::addForResponse($sum, $split->amount->toString());
                $selectedAmount = ExactDecimal::addForResponse($selectedAmount, $split->amount->toString());
            }
            if (isset($sources[$movement->transactionId])) {
                if (!$sources[$movement->transactionId]->asset->equals($movement->asset)) {
                    throw new \LogicException('One transaction cannot contribute budget sources in multiple assets.');
                }
                $selectedAmount = ExactDecimal::addForResponse(
                    $sources[$movement->transactionId]->amount,
                    $selectedAmount,
                );
            }
            $sources[$movement->transactionId] = new MonthlyBudgetActualSource(
                $movement->transactionId,
                $selectedAmount,
                $movement->asset,
            );
        }

        $pendingCount = 0;
        foreach ($pending as $movement) {
            if ([] !== self::selectedSplits(
                $movement,
                $budgetIncludedByCategory,
                $ancestorIdsByCategory,
                $scopeType,
                $scopeId,
            )) {
                ++$pendingCount;
            }
        }

        ksort($sources, SORT_STRING);
        $sources = array_values($sources);

        if (!$hasAccounts) {
            return new MonthlyBudgetActual(null, null, MonthlyProjectionReason::NO_ACCOUNT, $sources, $pendingCount);
        }
        if (count($assets) > 1) {
            return new MonthlyBudgetActual(null, null, MonthlyProjectionReason::MIXED_ASSETS, $sources, $pendingCount);
        }
        $asset = AssetCode::fromString([] === $assets ? $fallbackAsset : (string) array_key_first($assets));

        return new MonthlyBudgetActual(ExactDecimal::negateForResponse($sum), $asset, null, $sources, $pendingCount);
    }

    /**
     * @param array<string, bool>         $budgetIncludedByCategory
     * @param array<string, list<string>> $ancestorIdsByCategory
     *
     * @return list<MonthlySplit>
     */
    private static function selectedSplits(
        MonthlyMovement $movement,
        array $budgetIncludedByCategory,
        array $ancestorIdsByCategory,
        string $scopeType,
        string $scopeId,
    ): array {
        if (!MonthlyProjectionCalculator::contributesToBudgetExpenses($movement->kind)) {
            return [];
        }

        return array_values(array_filter(
            $movement->splits,
            static function (MonthlySplit $split) use ($budgetIncludedByCategory, $ancestorIdsByCategory, $scopeType, $scopeId): bool {
                if (!($budgetIncludedByCategory[$split->categoryId] ?? false)) {
                    return false;
                }

                return match ($scopeType) {
                    'CATEGORY' => $split->categoryId === $scopeId,
                    'GROUP' => $split->categoryId === $scopeId
                        || in_array($scopeId, $ancestorIdsByCategory[$split->categoryId] ?? [], true),
                    'AXIS' => in_array($scopeId, $split->analyticAxes, true),
                    default => throw new \InvalidArgumentException('A monthly budget scope type must be recognised.'),
                };
            },
        ));
    }
}
