<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Goals;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

/**
 * Whether a period meets its goal, decided on exact decimal strings and never by the browser.
 *
 * A ceiling is missed as soon as the achieved value passes the target, even in a running period;
 * a minimum is only judged once the period is complete. Equality is met.
 */
final class GoalEvaluationRules
{
    /** Interpolate the stock from its opening value using complete calendar months only. */
    public static function stockExpected(?string $start, ?string $target, int $elapsedMonths): ?string
    {
        if ($elapsedMonths < 0 || $elapsedMonths > 12) {
            throw new \InvalidArgumentException('A yearly stock pace uses between zero and twelve complete months.');
        }
        if (null === $start || null === $target) {
            return null;
        }

        $change = ExactDecimal::subtractForResponse($target, $start);
        $elapsedChange = ExactDecimal::multiplyCanonicalForResponse(
            $change,
            DecimalValue::fromString((string) $elapsedMonths),
        );

        return ExactDecimal::addForResponse($start, ExactDecimal::divideForResponse($elapsedChange, '12'));
    }

    /** @param ?string $tolerance a margin in the unit of the target, already converted to an amount for a share; null or zero is strict */
    public static function status(GoalDirection $direction, ?string $achieved, ?string $target, bool $complete, ?string $tolerance = null): GoalEvaluationStatus
    {
        if (null === $achieved || null === $target) {
            return GoalEvaluationStatus::NOT_CALCULABLE;
        }
        $tolerance ??= '0';
        if (GoalDirection::CEILING === $direction) {
            return match (true) {
                ExactDecimal::compareForResponse($achieved, ExactDecimal::addForResponse($target, $tolerance)) > 0 => GoalEvaluationStatus::MISSED,
                $complete => GoalEvaluationStatus::MET,
                default => GoalEvaluationStatus::IN_PROGRESS,
            };
        }

        return match (true) {
            !$complete => GoalEvaluationStatus::IN_PROGRESS,
            ExactDecimal::compareForResponse($achieved, ExactDecimal::subtractForResponse($target, $tolerance)) < 0 => GoalEvaluationStatus::MISSED,
            default => GoalEvaluationStatus::MET,
        };
    }

    /**
     * Whether the achieved value is on the wrong side of the target yet not past the bound the tolerance
     * allows. Unlike the status, a running minimum is judged on the bound too, so the display can tell a
     * shortfall the owner accepts from one he does not.
     */
    public static function withinTolerance(GoalDirection $direction, ?string $achieved, ?string $target, ?string $tolerance): ?bool
    {
        if (null === $achieved || null === $target) {
            return null;
        }
        if (null === $tolerance || ExactDecimal::isZeroForResponse($tolerance)) {
            return false;
        }
        if (GoalDirection::CEILING === $direction) {
            return ExactDecimal::compareForResponse($achieved, $target) > 0
                && ExactDecimal::compareForResponse($achieved, ExactDecimal::addForResponse($target, $tolerance)) <= 0;
        }

        return ExactDecimal::compareForResponse($achieved, $target) < 0
            && ExactDecimal::compareForResponse($achieved, ExactDecimal::subtractForResponse($target, $tolerance)) >= 0;
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
