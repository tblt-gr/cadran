<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Performance;

use App\Module\Accounts\Domain\Account;
use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\RoundingMode;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Application\PortfolioUnrealizedGain;
use App\Module\Portfolio\Domain\Performance\AnchorKind;
use App\Module\Portfolio\Domain\Performance\ModifiedDietz;
use App\Module\Portfolio\Domain\Performance\ModifiedDietzCalculator;
use App\Module\Portfolio\Domain\Performance\PerformanceAmount;
use App\Module\Portfolio\Domain\Performance\PerformanceFinding;
use App\Module\Portfolio\Domain\Performance\PerformancePeriod;
use App\Module\Portfolio\Domain\Performance\PerformanceQuality;
use App\Module\Portfolio\Domain\Performance\PerformanceReason;
use App\Module\Portfolio\Domain\Performance\PerformanceScope;
use App\Module\Portfolio\Domain\Performance\ReturnChain;
use App\Module\Portfolio\Domain\PortfolioOperation;

/**
 * Modified Dietz of a requested period: one Dietz for a month, and for a year or the whole life the geometric chain
 * of the monthly ones, each month resolved like a MONTH request on the history read once.
 */
final readonly class ComputeModifiedDietz
{
    public function __construct(
        private PerformancePeriodBuilder $builder,
        private ModifiedDietzCalculator $calculator,
    ) {
    }

    public function __invoke(WorkspaceScope $workspace, Account $account, PerformancePeriod $period, PerformancePeriodData $data, \DateTimeImmutable $today): ModifiedDietzFigure
    {
        $start = $data->start;
        $end = $data->end;
        if (!$data->isCalculable() || null === $start || null === $end) {
            return self::figure(null, [...$data->blocking, ...$data->warnings], [], null, 0, null, true);
        }
        if (PerformanceScope::MONTH === $period->scope) {
            $result = $this->calculator->calculate($start, $end, $data->operations, $data->displayPrecision);

            return self::figure(
                $result->rate, [...$result->blocking, ...$data->warnings, ...$result->warnings], $result->weights,
                $result->averageCapital, $result->weightCount, null, [] !== $result->blocking, gain: $result->gain, denominatorTimesDays: $result->denominatorTimesDays,
            );
        }

        [$first, $count] = self::span($account, $period, $data);
        if ($count > ReturnChain::MAX_PERIODS) {
            return self::figure(null, [new PerformanceFinding(PerformanceReason::TOO_MANY_PERIODS), ...$data->warnings], [], null, 0, null, true);
        }

        $labels = [];
        for ($index = 0; $index < $count; ++$index) {
            $labels[] = $first->modify('+'.$index.' month')->format('Y-m');
        }
        $months = [];
        $rates = [];
        $unavailable = [];
        $warnings = $data->warnings;
        foreach ($labels as $label) {
            $month = $this->month($workspace, $account, $label, $today, $data->history);
            $months[] = ['month' => $label, 'value' => self::display($month->rate), 'quality' => self::quality($month), 'findings' => [...$month->blocking, ...$month->warnings]];
            $warnings = [...$warnings, ...$month->warnings];
            if (null === $month->rate) {
                $unavailable[] = $label;
            } else {
                $rates[] = $month->rate;
            }
        }
        if ([] !== $unavailable) {
            return self::figure(null, [new PerformanceFinding(PerformanceReason::MONTH_NOT_CALCULABLE, $unavailable), ...self::merged($warnings)], [], null, 0, $months, true);
        }

        return self::figure(ReturnChain::chain($rates), self::merged($warnings), [], null, 0, $months, false);
    }

    /** @param list<PortfolioOperation> $history */
    private function month(WorkspaceScope $workspace, Account $account, string $label, \DateTimeImmutable $today, array $history): ModifiedDietz
    {
        $period = PerformancePeriod::of(PerformanceScope::MONTH->value, $label, $today, $account->openedOn);
        $data = $this->builder->build($workspace, $account, $period, $today, $history);
        if (null === $data->start || null === $data->end || !$data->isCalculable()) {
            $blocking = $data->blocking;

            return new ModifiedDietz(null, null, $blocking[0]->code ?? null, [], 0, $blocking, $data->warnings);
        }
        $result = $this->calculator->calculate($data->start, $data->end, $data->operations, $data->displayPrecision);

        return new ModifiedDietz($result->rate, $result->averageCapital, $result->reason, $result->weights, $result->weightCount, $result->blocking, [...$data->warnings, ...$result->warnings]);
    }

    /**
     * The first month and the number of months of the chain: from the month of the first operation when the period
     * starts from an empty account, from January otherwise, never before the month the account was opened, to the month of the end anchor.
     *
     * @return array{\DateTimeImmutable, int}
     */
    private static function span(Account $account, PerformancePeriod $period, PerformancePeriodData $data): array
    {
        $start = $data->start ?? throw new \LogicException('A calculable period has a start.');
        $end = $data->end ?? throw new \LogicException('A calculable period has an end.');
        $first = $period->firstDay ?? $start->date->modify('+1 day');
        if (AnchorKind::OPENING === $start->kind && [] !== $data->history) {
            $first = max($first, $data->history[0]->details->tradeDate);
        }
        $first = max($first, $account->openedOn)->modify('first day of this month');
        $last = $end->date->modify('first day of this month');

        return [$first, ((int) $last->format('Y') - (int) $first->format('Y')) * 12 + (int) $last->format('n') - (int) $first->format('n') + 1];
    }

    /**
     * @param list<PerformanceFinding> $findings
     *
     * @return list<PerformanceFinding> one finding per code, its dates merged
     */
    private static function merged(array $findings): array
    {
        $dates = [];
        foreach ($findings as $finding) {
            $dates[$finding->code->value] = [...($dates[$finding->code->value] ?? []), ...$finding->dates];
        }

        return array_map(static fn (string $code, array $codeDates): PerformanceFinding => new PerformanceFinding(PerformanceReason::from($code), $codeDates), array_keys($dates), $dates);
    }

    private static function quality(ModifiedDietz $month): PerformanceQuality
    {
        return PerformanceQuality::of(PerformanceQuality::APPROXIMATION, [] !== $month->blocking, [] !== $month->warnings);
    }

    private static function display(?CalculatedDecimal $rate): ?string
    {
        return null === $rate ? null : PerformanceAmount::canonical($rate->round(PortfolioUnrealizedGain::PERFORMANCE_SCALE, RoundingMode::HALF_UP), PortfolioUnrealizedGain::PERFORMANCE_SCALE);
    }

    /**
     * @param list<PerformanceFinding>                                                                                         $findings
     * @param list<array{operationId: string, date: string, amount: string, remainingDays: string, weight: string}>            $weights
     * @param list<array{month: string, value: ?string, quality: PerformanceQuality, findings: list<PerformanceFinding>}>|null $months
     */
    private static function figure(?CalculatedDecimal $rate, array $findings, array $weights, ?string $averageCapital, int $weightCount, ?array $months, bool $blocked, ?string $gain = null, ?string $denominatorTimesDays = null): ModifiedDietzFigure
    {
        $quality = PerformanceQuality::of(PerformanceQuality::APPROXIMATION, $blocked, [] !== $findings);

        return new ModifiedDietzFigure(self::display($rate), $quality, $findings, $averageCapital, $weights, $weightCount, $months, $gain, $denominatorTimesDays);
    }
}
