<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\RoundingMode;
use App\Module\Portfolio\Domain\PortfolioOperation;

/**
 * R = gain x T / (start x T + sum((end - d) x C)): each external flow C is invested from the end of its day d.
 * The numerator and the denominator are exact; the only division is the final one, at scale 24. A denominator that
 * is empty, not positive or small against the capital put to work gives no rate, never zero or an absurd percentage.
 */
final class ModifiedDietzCalculator
{
    public const string DIETZ_MIN_CAPITAL_RATIO = '0.10';
    public const int MAX_WEIGHTS = 100;
    private const int SCALE = 24;
    private const int EXACT_SCALE = 60;
    private const int DISPLAY_SCALE = 6;

    /** @param list<PortfolioOperation> $operations active operations with start date < trade date <= end date */
    public function calculate(PeriodAnchor $start, PeriodAnchor $end, array $operations, int $displayPrecision): ModifiedDietz
    {
        $warnings = TotalGainCalculator::anchorWarnings($start, $end);
        $transferDates = [];
        $flows = [];
        foreach ($operations as $operation) {
            if (ExternalFlow::isUnvalued($operation)) {
                $transferDates[] = $operation->details->tradeDate->format('Y-m-d');
            } elseif (null !== ($signed = ExternalFlow::signedAmount($operation))) {
                $flows[] = [$operation, CalculatedDecimal::fromDecimal($signed)];
            }
        }
        if ([] !== $transferDates) {
            return new ModifiedDietz(null, null, PerformanceReason::UNVALUED_SECURITY_TRANSFER, [], 0, [new PerformanceFinding(PerformanceReason::UNVALUED_SECURITY_TRANSFER, $transferDates)], $warnings);
        }

        $days = self::days($start->date, $end->date);
        $period = self::integer($days);
        $startValue = CalculatedDecimal::fromDecimal($start->totalValue);
        $gain = CalculatedDecimal::fromDecimal($end->totalValue)->subtract($startValue);
        $denominator = $startValue->proportion($period, CalculatedDecimal::one(), self::EXACT_SCALE, RoundingMode::HALF_EVEN);
        $invested = $startValue;
        $weights = [];
        foreach ($flows as [$operation, $amount]) {
            $remaining = self::days($operation->details->tradeDate, $end->date);
            $gain = $gain->subtract($amount);
            $denominator = $denominator->add($amount->proportion(self::integer($remaining), CalculatedDecimal::one(), self::EXACT_SCALE, RoundingMode::HALF_EVEN));
            if ($amount->compareTo(CalculatedDecimal::zero()) > 0) {
                $invested = $invested->add($amount);
            }
            if (count($weights) < self::MAX_WEIGHTS) {
                $weights[] = [
                    'operationId' => $operation->id,
                    'date' => $operation->details->tradeDate->format('Y-m-d'),
                    'amount' => PerformanceAmount::canonical($amount, $displayPrecision),
                    'remainingDays' => (string) $remaining,
                    'weight' => self::display(self::integer($remaining)->proportion(CalculatedDecimal::one(), $period, self::DISPLAY_SCALE, RoundingMode::HALF_UP)),
                ];
            }
        }
        $count = count($flows);
        if ($startValue->isZero() && [] === $flows) {
            return new ModifiedDietz(null, null, PerformanceReason::NO_INVESTED_CAPITAL, [], 0, [new PerformanceFinding(PerformanceReason::NO_INVESTED_CAPITAL)], $warnings);
        }

        $average = self::display($denominator->proportion(CalculatedDecimal::one(), $period, self::DISPLAY_SCALE, RoundingMode::HALF_UP));
        $gainText = PerformanceAmount::canonical($gain, $displayPrecision);
        $denominatorText = PerformanceAmount::canonical($denominator, $displayPrecision);
        $reason = self::guard($denominator, $invested, $period);
        if (null !== $reason) {
            return new ModifiedDietz(null, $average, $reason, $weights, $count, [new PerformanceFinding($reason)], $warnings, $gainText, $denominatorText);
        }

        return new ModifiedDietz($gain->proportion($period, $denominator, self::SCALE, RoundingMode::HALF_EVEN), $average, null, $weights, $count, [], $warnings, $gainText, $denominatorText);
    }

    /** Guards on the exact average capital A = denominator / T, compared without dividing: A < ratio x invested. */
    private static function guard(CalculatedDecimal $denominator, CalculatedDecimal $invested, CalculatedDecimal $period): ?PerformanceReason
    {
        if ($denominator->compareTo(CalculatedDecimal::zero()) <= 0) {
            return PerformanceReason::NON_POSITIVE_DENOMINATOR;
        }
        $floor = CalculatedDecimal::fromDecimal(DecimalValue::fromString(self::DIETZ_MIN_CAPITAL_RATIO))
            ->proportion($invested, CalculatedDecimal::one(), self::EXACT_SCALE, RoundingMode::HALF_EVEN)
            ->proportion($period, CalculatedDecimal::one(), self::EXACT_SCALE, RoundingMode::HALF_EVEN);

        return $denominator->compareTo($floor) < 0 ? PerformanceReason::DENOMINATOR_TOO_SMALL : null;
    }

    private static function display(CalculatedDecimal $value): string
    {
        return PerformanceAmount::canonical($value, self::DISPLAY_SCALE);
    }

    private static function days(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $from->diff($to)->format('%r%a');
    }

    private static function integer(int $value): CalculatedDecimal
    {
        return CalculatedDecimal::fromDecimal(DecimalValue::fromString((string) $value));
    }
}
