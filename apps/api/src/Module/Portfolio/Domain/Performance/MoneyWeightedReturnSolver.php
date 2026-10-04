<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

use App\Module\Foundation\Domain\DecimalValue;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Bounded daily-factor XIRR. All products and divisions use the named decimal working scale. */
final class MoneyWeightedReturnSolver
{
    public const int WORKING_SCALE = 40;
    public const string STOP_WIDTH = '0.000000000000001';
    public const int MAX_FLOWS = 500;
    public const int MAX_ITERATIONS = 100;
    public const string MIN_FACTOR = '0.98';
    public const string MAX_FACTOR = '1.01';

    public function __construct(private readonly int $maxIterations = self::MAX_ITERATIONS)
    {
        if ($maxIterations < 1 || $maxIterations > self::MAX_ITERATIONS) {
            throw new \InvalidArgumentException('The iteration budget is between 1 and 100.');
        }
    }

    /** @param list<array{day: int, amount: DecimalValue}> $flows */
    public function solve(array $flows, int $days): MoneyWeightedReturnResult
    {
        if ($days < 0) {
            throw new \InvalidArgumentException('The period duration is non-negative.');
        }
        $net = [];
        foreach ($flows as $flow) {
            if ($flow['day'] < 0 || $flow['day'] > $days) {
                throw new \InvalidArgumentException('A flow falls inside its period.');
            }
            $net[$flow['day']] = ($net[$flow['day']] ?? BigDecimal::zero())->plus($flow['amount']->toString());
        }
        $net = array_filter($net, static fn (BigDecimal $amount): bool => !$amount->isZero());
        ksort($net);
        if (count($net) > self::MAX_FLOWS) {
            return self::failure(PerformanceReason::TOO_MANY_FLOWS);
        }
        $signs = array_map(static fn (BigDecimal $amount): int => $amount->getSign(), array_values($net));
        if (!in_array(-1, $signs, true) || !in_array(1, $signs, true)) {
            return self::failure(PerformanceReason::NO_SIGN_CHANGE);
        }
        $cumulative = [];
        $sum = BigDecimal::zero();
        foreach ($net as $amount) {
            $sum = $sum->plus($amount);
            $cumulative[] = $sum->getSign() * -$signs[0];
        }
        // Norstrom requires an initially negative cumulative sequence, with exactly one crossing.
        $unique = 1 === self::changes($signs) || (1 === self::changes($cumulative) && 1 === ($cumulative[array_key_last($cumulative) ?? 0] ?? 0));
        $points = [BigDecimal::of(self::MIN_FACTOR), BigDecimal::of(self::MAX_FACTOR)];
        if (!$unique) {
            $points = $this->grid();
        }
        $brackets = [];
        $roots = [];
        $previous = null;
        foreach ($points as $point) {
            $value = self::evaluate($net, $point);
            if ($value->isZero()) {
                $roots[] = $point;
            } elseif (null !== $previous && !$previous[1]->isZero() && $value->getSign() !== $previous[1]->getSign()) {
                $brackets[] = [$previous[0], $point, $previous[1], $value];
            }
            $previous = [$point, $value];
        }
        $count = count($roots) + count($brackets);
        if ($count > 1) {
            return self::failure(PerformanceReason::MULTIPLE_ROOTS);
        }
        if (0 === $count) {
            return self::failure(PerformanceReason::NO_ROOT_IN_RANGE);
        }
        if ([] !== $roots) {
            return self::result($roots[0], $days, 0, $unique);
        }
        [$left, $right, $fl, $fr] = $brackets[0];
        $retained = 0;
        for ($iteration = 1; $iteration <= $this->maxIterations; ++$iteration) {
            $x = $left->multipliedBy($fr)->minus($right->multipliedBy($fl))->dividedBy($fr->minus($fl), self::WORKING_SCALE, RoundingMode::HalfEven);
            if ($x->compareTo($left) <= 0 || $x->compareTo($right) >= 0) {
                $x = $left->plus($right)->dividedBy(2, self::WORKING_SCALE, RoundingMode::HalfEven);
            }
            $fx = self::evaluate($net, $x);
            if ($fx->isZero()) {
                return self::result($x, $days, $iteration, $unique);
            }
            if ($fx->getSign() === $fl->getSign()) {
                $left = $x;
                $fl = $fx;
                if (1 === $retained) {
                    $fr = $fr->dividedBy(2, self::WORKING_SCALE, RoundingMode::HalfEven);
                }
                $retained = 1;
            } else {
                $right = $x;
                $fr = $fx;
                if (-1 === $retained) {
                    $fl = $fl->dividedBy(2, self::WORKING_SCALE, RoundingMode::HalfEven);
                }
                $retained = -1;
            }
            if ($right->minus($left)->compareTo(self::STOP_WIDTH) <= 0) {
                $midpoint = $left->plus($right)->dividedBy(2, self::WORKING_SCALE, RoundingMode::HalfEven);

                return self::result($midpoint, $days, $iteration, $unique);
            }
        }

        return self::failure(PerformanceReason::NO_CONVERGENCE, $this->maxIterations);
    }

    /** @param list<int> $signs */
    private static function changes(array $signs): int
    {
        $last = 0;
        $count = 0;
        foreach ($signs as $sign) {
            if (0 === $sign) {
                continue;
            }
            if (0 !== $last && $last !== $sign) {
                ++$count;
            }
            $last = $sign;
        }

        return $count;
    }

    /** @return list<BigDecimal> */
    private function grid(): array
    {
        $points = [];
        foreach ([['0.98', '0.03', 40], ['0.998103', '0.003798', 200]] as [$start, $width, $steps]) {
            $step = BigDecimal::of($width)->dividedBy($steps, self::WORKING_SCALE, RoundingMode::HalfEven);
            for ($i = 0; $i <= $steps; ++$i) {
                $point = BigDecimal::of($start)->plus($step->multipliedBy($i));
                $points[(string) $point] = $point;
            }
        }
        $points = array_values($points);
        usort($points, static fn (BigDecimal $a, BigDecimal $b): int => $a->compareTo($b));

        return $points;
    }

    /** @param array<int, BigDecimal> $net */
    private static function evaluate(array $net, BigDecimal $x): BigDecimal
    {
        $value = BigDecimal::zero();
        $previousDay = array_key_first($net) ?? 0;
        $powers = [];
        foreach ($net as $day => $amount) {
            $gap = $day - $previousDay;
            $powers[$gap] ??= self::power($x, $gap);
            $value = self::product($value, $powers[$gap])->plus($amount);
            $previousDay = $day;
        }

        // Drop the positive common trailing factor: fixed-scale underflow must not manufacture a root.
        return $value;
    }

    private static function product(BigDecimal $a, BigDecimal $b): BigDecimal
    {
        return $a->multipliedBy($b)->toScale(self::WORKING_SCALE, RoundingMode::HalfEven);
    }

    private static function power(BigDecimal $base, int $exponent): BigDecimal
    {
        $result = BigDecimal::one();
        while ($exponent > 0) {
            if (1 === $exponent % 2) {
                $result = self::product($result, $base);
            }
            $exponent = intdiv($exponent, 2);
            if ($exponent > 0) {
                $base = self::product($base, $base);
            }
        }

        return $result;
    }

    private static function result(BigDecimal $x, int $days, int $iterations, bool $unique): MoneyWeightedReturnResult
    {
        return new MoneyWeightedReturnResult(
            (string) self::power($x, 365)->minus(1)->toScale(6, RoundingMode::HalfUp),
            (string) self::power($x, $days)->minus(1)->toScale(6, RoundingMode::HalfUp),
            null, $iterations, $unique,
        );
    }

    private static function failure(PerformanceReason $reason, ?int $iterations = null): MoneyWeightedReturnResult
    {
        return new MoneyWeightedReturnResult(null, null, $reason, $iterations);
    }
}
