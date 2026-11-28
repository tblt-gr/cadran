<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

/** Why a goal figure is missing, or what the owner must be warned about. */
enum GoalReason: string
{
    case NO_REFERENCE_INCOME = 'NO_REFERENCE_INCOME';
    case ZERO_CASH_INCOME = 'ZERO_CASH_INCOME';
    case MISSING_CASH_INCOME = 'MISSING_CASH_INCOME';
    case NO_DATA = 'NO_DATA';
    case MIXED_ASSETS = 'MIXED_ASSETS';
    case MIXED_METRIC_POLICIES = 'MIXED_METRIC_POLICIES';
    case MISSING_MONTH_VALUE = 'MISSING_MONTH_VALUE';
    case REFERENCE_ARCHIVED = 'REFERENCE_ARCHIVED';
    case GOAL_NOT_STARTED = 'GOAL_NOT_STARTED';
    case GOAL_ENDED = 'GOAL_ENDED';
    case EVALUATION_NOT_AVAILABLE = 'EVALUATION_NOT_AVAILABLE';
    case FUTURE_MONTH = 'FUTURE_MONTH';
    case TRANSFER_SCOPE_TOO_LARGE = 'TRANSFER_SCOPE_TOO_LARGE';

    /** The single mapping of a reason produced outside goals: an unknown code never leaks through. */
    public static function fromReport(?string $reason): ?self
    {
        return null === $reason ? null : (self::tryFrom($reason) ?? self::NO_DATA);
    }

    /** The goal evaluations publish five reasons only. */
    public function forEvaluation(): self
    {
        return match ($this) {
            self::NO_REFERENCE_INCOME, self::ZERO_CASH_INCOME, self::NO_DATA, self::MIXED_ASSETS, self::REFERENCE_ARCHIVED => $this,
            default => self::NO_DATA,
        };
    }
}
