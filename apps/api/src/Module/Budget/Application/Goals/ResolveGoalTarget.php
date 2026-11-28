<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Domain\Goals\GoalReason;
use App\Module\Budget\Domain\Goals\GoalValueType;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Reporting\Application\AnnualMonth;

/**
 * The only translation of a goal definition into a target. It reads nothing itself: every figure
 * comes from what the caller loaded inside its own workspace.
 */
final readonly class ResolveGoalTarget
{
    public function forMonth(GoalVersion $version, AnnualMonth $item, CalendarMonth $month, EvaluationContext|GoalStatusInputs $source): GoalTarget
    {
        switch ($version->valueType) {
            case GoalValueType::AMOUNT:
            case GoalValueType::RATE:
                return self::fixed($version);
            case GoalValueType::INCOME_SHARE:
                $income = $item->figures?->cells['cashIncome'] ?? null;

                return self::share($version, $income?->value, GoalReason::fromReport($income?->reason));
            case GoalValueType::ROLLING_INCOME_SHARE:
                $reference = $source instanceof EvaluationContext
                    ? $source->reference($month)
                    : ReadReferenceIncome::fromMonths($month, array_values($source->months));
                $income = $reference->value?->toString();
                if (null === $income || null === $version->ratio) {
                    return new GoalTarget(reason: GoalReason::NO_REFERENCE_INCOME, income: $income, reference: $reference);
                }

                return new GoalTarget(
                    ExactDecimal::multiplyForResponse(DecimalValue::fromString($income), $version->ratio),
                    $version->toleranceAmount($income),
                    income: $income,
                    reference: $reference,
                );
        }
    }

    /** A share of a year reads the cash income of the whole year, rolling or not. */
    public function forYear(GoalVersion $version, ?string $yearIncome): GoalTarget
    {
        return match ($version->valueType) {
            GoalValueType::AMOUNT, GoalValueType::RATE => self::fixed($version),
            GoalValueType::INCOME_SHARE, GoalValueType::ROLLING_INCOME_SHARE => self::share($version, $yearIncome, null),
        };
    }

    private static function fixed(GoalVersion $version): GoalTarget
    {
        $target = GoalValueType::AMOUNT === $version->valueType ? $version->targetAmount?->toString() : $version->ratio?->toString();

        return new GoalTarget($target, null === $target ? null : $version->toleranceAmount(null));
    }

    private static function share(GoalVersion $version, ?string $income, ?GoalReason $incomeReason): GoalTarget
    {
        if (null === $income || null === $version->ratio) {
            return new GoalTarget(reason: GoalReason::ZERO_CASH_INCOME === $incomeReason ? GoalReason::ZERO_CASH_INCOME : GoalReason::NO_DATA, income: $income);
        }
        if (ExactDecimal::isZeroForResponse($income)) {
            return new GoalTarget(reason: GoalReason::ZERO_CASH_INCOME, income: $income);
        }

        return new GoalTarget(
            ExactDecimal::multiplyForResponse(DecimalValue::fromString($income), $version->ratio),
            $version->toleranceAmount($income),
            income: $income,
        );
    }
}
