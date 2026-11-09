<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

/** One goal evaluated for one month or one year. */
final readonly class GoalEvaluationView
{
    /**
     * @param list<string>                             $overrides the goals this one hides for the period
     * @param array<string, ?string>                   $operands  target, achieved, income, ratio and tolerance of the formula
     * @param ?array{expected: string, status: string} $pace
     */
    public function __construct(
        public string $goalId,
        public string $label,
        public string $periodicity,
        public string $metric,
        public string $scopeType,
        public ?string $scopeRef,
        public string $direction,
        public string $valueType,
        public ?string $columnKey,
        public ?GoalEvaluationFigure $target,
        public ?GoalEvaluationFigure $achieved,
        public ?string $gap,
        public string $status,
        public ?string $reason,
        public string $formulaKey,
        public array $operands,
        public int $versionNo,
        public array $overrides,
        public ?array $pace,
        public ?GoalEvaluationFigure $tolerance = null,
        public ?bool $withinTolerance = null,
        public ?GoalEvaluationFigure $start = null,
        public ?string $valuationDate = null,
        public ?string $valuationQuality = null,
        public ?string $explanationUrl = null,
    ) {
    }
}
