<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

use App\Module\Foundation\Domain\ExactDecimal;

/**
 * Whether a period meets its goal, decided on exact decimal strings and never by the browser.
 *
 * A ceiling is missed as soon as the achieved value passes the target, even in a running period;
 * a minimum is only judged once the period is complete. Equality is met.
 */
final class GoalEvaluationRules
{
    public static function status(GoalDirection $direction, ?string $achieved, ?string $target, bool $complete): GoalEvaluationStatus
    {
        if (null === $achieved || null === $target) {
            return GoalEvaluationStatus::NOT_CALCULABLE;
        }
        $comparison = ExactDecimal::compareForResponse($achieved, $target);
        if (GoalDirection::CEILING === $direction) {
            return match (true) {
                $comparison > 0 => GoalEvaluationStatus::MISSED,
                $complete => GoalEvaluationStatus::MET,
                default => GoalEvaluationStatus::IN_PROGRESS,
            };
        }

        return match (true) {
            !$complete => GoalEvaluationStatus::IN_PROGRESS,
            $comparison < 0 => GoalEvaluationStatus::MISSED,
            default => GoalEvaluationStatus::MET,
        };
    }

    /** Signed so that a positive gap is always in the goal's favour. */
    public static function gap(GoalDirection $direction, ?string $achieved, ?string $target): ?string
    {
        if (null === $achieved || null === $target) {
            return null;
        }

        return GoalDirection::CEILING === $direction
            ? ExactDecimal::subtractForResponse($target, $achieved)
            : ExactDecimal::subtractForResponse($achieved, $target);
    }

    /** @return 'AHEAD'|'BEHIND'|'ON_TRACK'|'OVER_PACE' */
    public static function pace(GoalDirection $direction, string $achieved, string $expected): string
    {
        $comparison = ExactDecimal::compareForResponse($achieved, $expected);
        if (GoalDirection::MINIMUM === $direction) {
            return $comparison >= 0 ? 'AHEAD' : 'BEHIND';
        }

        return $comparison <= 0 ? 'ON_TRACK' : 'OVER_PACE';
    }
}
