<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\ProblemRule;
use App\Module\Portfolio\Application\Performance\PerformanceRangeTooDense;
use Symfony\Component\HttpFoundation\Response;

/**
 * The tables of the portfolio summary reads: the rows of {@see PortfolioProblems::position()}
 * answered as untyped problems, which those endpoints identify by their `code` member alone.
 */
final class PortfolioReadProblems
{
    private function __construct()
    {
    }

    /**
     * Portfolio list, reminders, summary and allocation, and the life-insurance summary.
     *
     * @return array<class-string<\Throwable>, ProblemRule|\Closure>
     */
    public static function summary(): array
    {
        return self::untyped(PortfolioProblems::position());
    }

    /**
     * The performance reads name their own bound.
     *
     * @return array<class-string<\Throwable>, ProblemRule|\Closure>
     */
    public static function performance(): array
    {
        return self::untyped([
            ...PortfolioProblems::position(),
            PerformanceRangeTooDense::class => PortfolioProblems::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'performance_range_too_dense'),
        ]);
    }

    /**
     * @param array<class-string<\Throwable>, ProblemRule|\Closure> $rules
     *
     * @return array<class-string<\Throwable>, ProblemRule|\Closure>
     */
    private static function untyped(array $rules): array
    {
        $strip = static fn (ProblemRule $rule): ProblemRule => new ProblemRule($rule->status, $rule->translationKey, extensions: $rule->extensions);

        return array_map(
            static fn (ProblemRule|\Closure $rule): ProblemRule|\Closure => $rule instanceof ProblemRule
                ? $strip($rule)
                : static function (\Throwable $exception) use ($rule, $strip): ProblemRule {
                    /** @var ProblemRule $built */
                    $built = $rule($exception);

                    return $strip($built);
                },
            $rules,
        );
    }
}
