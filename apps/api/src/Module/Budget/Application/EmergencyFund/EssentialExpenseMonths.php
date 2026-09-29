<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\EmergencyFund;

use App\Module\Budget\Domain\EmergencyFund\EmergencyFundPolicy;
use App\Module\Budget\Domain\EmergencyFund\EssentialMode;
use App\Module\Categories\Application\BudgetCategoryFact;
use App\Module\Categories\Domain\Category;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Reporting\Application\AnnualColumnSeries;
use App\Module\Reporting\Application\AnnualMonth;
use App\Module\Reporting\Domain\Aggregation\MonthValue;

/** Reads the essential expenses of each reference month out of the report figures, as the policy defines them. */
final class EssentialExpenseMonths
{
    private const string ESSENTIAL_AXIS_COLUMN = 'axis:ESSENTIAL';

    /**
     * @param list<AnnualMonth>             $months
     * @param list<BudgetCategoryFact>|null $categories the category tree, read only in CATEGORIES mode
     *
     * @return list<MonthValue>
     */
    public static function of(EmergencyFundPolicy $policy, array $months, ?array $categories): array
    {
        if (EssentialMode::AXIS === $policy->essentialMode) {
            return AnnualColumnSeries::values(self::ESSENTIAL_AXIS_COLUMN, true, $months);
        }
        $branch = self::branch($policy->essentialCategoryIds, $categories ?? throw new \LogicException('A category perimeter reads the category tree.'));

        return array_map(static fn (AnnualMonth $month): MonthValue => self::categoryMonth($month, $branch), $months);
    }

    /**
     * Every listed category and its descendants, each once: a child listed beside its parent is
     * never counted twice. A listed id that no longer exists names nothing.
     *
     * @param list<string>             $ids
     * @param list<BudgetCategoryFact> $categories
     *
     * @return list<string>
     */
    public static function branch(array $ids, array $categories): array
    {
        $selected = array_flip($ids);
        $byId = [];
        foreach ($categories as $category) {
            $byId[$category->id] = $category;
        }
        $branch = [];
        foreach ($categories as $category) {
            $cursor = $category;
            for ($depth = 0; $depth < Category::MAX_TREE_DEPTH; ++$depth) {
                if (isset($selected[$cursor->id])) {
                    $branch[$category->id] = true;
                    break;
                }
                if (null === $cursor->parentId || !isset($byId[$cursor->parentId])) {
                    break;
                }
                $cursor = $byId[$cursor->parentId];
            }
        }

        return array_keys($branch);
    }

    /** @param list<string> $branch */
    private static function categoryMonth(AnnualMonth $month, array $branch): MonthValue
    {
        $figures = $month->figures;
        if (null === $figures) {
            return new MonthValue($month->month, $month->state, null, null, $month->state->value);
        }
        // The same budget perimeter the ESSENTIAL axis and category goals read.
        $included = array_flip($figures->budgetExpenseCategoryIds);
        $values = [];
        $assets = [];
        foreach ($branch as $categoryId) {
            if (!isset($included[$categoryId])) {
                continue;
            }
            $cell = $figures->cells['category:'.$categoryId] ?? null;
            if (null === $cell) {
                return new MonthValue($month->month, $month->state, null, null, 'MISSING_MONTH_VALUE', $figures->policyVersion);
            }
            if (null === $cell->value) {
                if (AnnualColumnSeries::NO_MOVEMENTS === $cell->reason) {
                    continue;
                }

                return new MonthValue($month->month, $month->state, null, null, $cell->reason ?? 'MISSING_MONTH_VALUE', $figures->policyVersion);
            }
            $values[] = DecimalValue::fromString($cell->value);
            if (null !== $cell->assetCode) {
                $assets[$cell->assetCode] = true;
            }
        }
        if (count($assets) > 1) {
            return new MonthValue($month->month, $month->state, null, null, 'MIXED_ASSETS', $figures->policyVersion);
        }

        return new MonthValue(
            $month->month,
            $month->state,
            ExactDecimal::sum(...$values),
            [] === $assets ? null : AssetCode::fromString((string) array_key_first($assets)),
            null,
            $figures->policyVersion,
        );
    }
}
