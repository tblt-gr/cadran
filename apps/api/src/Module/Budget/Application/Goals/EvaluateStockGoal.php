<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use App\Module\Accounts\Application\ReadAccountValuation;
use App\Module\Accounts\Application\ReadNetWorth;
use App\Module\Budget\Domain\Goals\Goal;
use App\Module\Budget\Domain\Goals\GoalEvaluationRules;
use App\Module\Budget\Domain\Goals\GoalMetric;
use App\Module\Budget\Domain\Goals\GoalPeriodicity;
use App\Module\Budget\Domain\Goals\GoalVersion;
use App\Module\Foundation\Application\WorkspaceCalendar;

/** Stock goals reuse the dated Accounts readers rather than aggregating reporting flows. */
final readonly class EvaluateStockGoal
{
    public function __construct(private ReadAccountValuation $accounts, private ReadNetWorth $netWorth, private ResolveGoalScope $scope, private WorkspaceCalendar $calendar)
    {
    }

    /** @param list<string> $overrides */
    public function __invoke(EvaluationContext $context, Goal $goal, GoalVersion $version, array $overrides, \DateTimeImmutable $first, \DateTimeImmutable $last): GoalEvaluationView
    {
        $today = $this->calendar->today();
        $date = min($last, $today);
        $startDate = $first->modify('-1 day');
        $archived = $context->scope($this->scope, $goal)->archived;
        $value = $archived ? [null, 'REFERENCE_ARCHIVED', null, 'MISSING'] : $this->value($context, $goal, $date);
        $start = $archived ? [null, 'REFERENCE_ARCHIVED', null, 'MISSING'] : $this->value($context, $goal, $startDate);
        $target = $version->targetAmount?->toString();
        $tolerance = $version->toleranceAmount(null);
        $complete = $today->format('Y-m-d') >= $last->format('Y-m-d');
        $pace = null;
        if (!$complete && GoalPeriodicity::YEARLY === $goal->periodicity && null !== $value[0]) {
            $elapsed = max(0, min(12, ((int) $today->format('Y') - (int) $first->format('Y')) * 12 + (int) $today->format('n') - 1));
            $expected = GoalEvaluationRules::stockExpected($start[0], $target, $elapsed);
            if (null !== $expected) {
                $pace = ['expected' => $expected, 'status' => GoalEvaluationRules::pace($goal->direction(), $value[0], $expected)];
            }
        }
        $amount = static fn (?string $v): ?GoalEvaluationFigure => null === $v ? null : GoalEvaluationFigure::amount($v, $goal->assetCode->toString());

        return new GoalEvaluationView(
            $goal->id, $goal->label, $goal->periodicity->value, $goal->metric->value, $goal->scopeType->value, $goal->scopeRef, $goal->direction()->value,
            $version->valueType->value, null, $amount($target), $amount($value[0]), GoalEvaluationRules::gap($goal->direction(), $value[0], $target),
            GoalEvaluationRules::status($goal->direction(), $value[0], $target, $complete, $tolerance)->value, $value[1], 'goals.formula.stock',
            ['target' => $target, 'achieved' => $value[0], 'start' => $start[0], 'valuationDate' => $value[2], 'valuationQuality' => $value[3], 'tolerance' => $tolerance],
            $version->versionNo, $overrides, $pace, $amount($tolerance), GoalEvaluationRules::withinTolerance($goal->direction(), $value[0], $target, $tolerance),
            $amount($start[0]), $value[2], $value[3], '/accounts',
        );
    }

    /** @return array{?string, ?string, ?string, string} */
    private function value(EvaluationContext $context, Goal $goal, \DateTimeImmutable $day): array
    {
        $date = $day->format('Y-m-d');
        $key = $goal->metric->value.':'.($goal->scopeRef ?? '').':'.$date;
        if (isset($context->stocks[$key])) {
            return $context->stocks[$key];
        }
        if (GoalMetric::ACCOUNT_BALANCE === $goal->metric) {
            $valuation = ($this->accounts)($goal->scopeRef ?? throw new \LogicException('An account goal carries its account.'), $date);
            $amount = $valuation->amount;
            $asset = $valuation->assetCode;
            $quality = $valuation->quality;
            $asOf = $valuation->asOf;
            $reason = null === $amount ? ($valuation->reason ?? 'NO_DATA') : null;
        } else {
            $valuation = ($this->netWorth)($date);
            $amount = $valuation->total?->amount;
            $asset = $valuation->total?->asset;
            $quality = $valuation->quality;
            $asOf = null;
            foreach ($valuation->contributions as $contribution) {
                if ($contribution->eligible && null !== $contribution->valuedOn && (null === $asOf || $contribution->valuedOn > $asOf)) {
                    $asOf = $contribution->valuedOn;
                }
            }
            $reason = null === $amount ? ('MIXED_ASSETS' === $valuation->reason ? 'MIXED_ASSETS' : 'NO_DATA') : null;
        }
        if (null !== $asset && $asset !== $goal->assetCode->toString()) {
            $amount = null;
            $reason = 'MIXED_ASSETS';
        }

        return $context->stocks[$key] = [$amount, $reason, $asOf, $quality];
    }
}
