<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Performance;

use App\Module\Accounts\Domain\Account;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Application\PortfolioValuationPresenter;
use App\Module\Portfolio\Domain\Performance\PerformanceFinding;
use App\Module\Portfolio\Domain\Performance\PerformancePeriod;
use App\Module\Portfolio\Domain\Performance\PerformanceReason;
use App\Module\Portfolio\Domain\Performance\PerformanceScope;
use App\Module\Portfolio\Domain\Performance\PeriodAnchor;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;
use App\Module\Portfolio\Domain\Valuation\Valuation;
use App\Module\Portfolio\Domain\Valuation\ValuationCompleteness;
use App\Module\Portfolio\Domain\Valuation\ValuationFlag;
use App\Module\Portfolio\Domain\Valuation\ValuationRepository;
use App\Module\Reference\Application\AssetCatalog;

/** Resolves the two anchors of a period and the operations between them; it computes no figure. */
final readonly class PerformancePeriodBuilder
{
    public const int MAX_OPERATIONS = 5000;

    public function __construct(
        private ValuationRepository $valuations,
        private PortfolioOperationRepository $operations,
        private PortfolioValuationPresenter $presenter,
        private AssetCatalog $assets,
    ) {
    }

    /**
     * @param list<PortfolioOperation>|null $loadedHistory the account history already read and bounded for this request,
     *                                                     which a series of periods shares instead of reloading it
     */
    public function build(WorkspaceScope $workspace, Account $account, PerformancePeriod $period, \DateTimeImmutable $today, ?array $loadedHistory = null): PerformancePeriodData
    {
        if (null === $loadedHistory && $this->operations->countActive($workspace, $account->id) > self::MAX_OPERATIONS) {
            throw new PerformanceRangeTooDense('The portfolio history exceeds the performance read bound.');
        }
        $precision = ($this->assets->findByCode($account->assetCode) ?? throw new \LogicException('An account asset is missing from the reference.'))->precision->display;
        $history = $loadedHistory ?? $this->operations->activeForAccount($workspace, $account->id);
        $blocking = [];

        $startValuation = null;
        $startOpening = null;
        if (PerformanceScope::INCEPTION === $period->scope) {
            $first = $history[0] ?? null;
            if (null !== $first && null === $this->valuations->latestActiveBefore($workspace, $account->id, $first->details->tradeDate)) {
                $startOpening = PeriodAnchor::opening($first->details->tradeDate->modify('-1 day'));
            } else {
                $blocking[] = new PerformanceFinding(PerformanceReason::NO_START_VALUATION);
            }
            $endMissing = [];
            [$endValuation, $endPartial] = $this->latest($workspace, $account->id, null, $today);
        } else {
            $firstDay = $period->firstDay ?? throw new \LogicException('A bounded period has a first day.');
            $lastDay = $period->lastDay ?? throw new \LogicException('A bounded period has a last day.');
            $target = $period->startTarget() ?? throw new \LogicException('A bounded period has a start.');
            [$startValuation, $startPartial] = $this->latest($workspace, $account->id, $target->modify('first day of this month'), $target);
            if (null === $startValuation) {
                if ($this->isEmptyBefore($workspace, $account->id, $firstDay, $history)) {
                    $startOpening = PeriodAnchor::opening($target);
                } else {
                    $blocking[] = new PerformanceFinding($startPartial ? PerformanceReason::START_VALUATION_PARTIAL : PerformanceReason::NO_START_VALUATION, [$target->format('Y-m-d')]);
                }
            }
            $endMissing = [$lastDay->format('Y-m-d')];
            [$endValuation, $endPartial] = $this->latest($workspace, $account->id, $firstDay, $lastDay);
        }
        if (null === $endValuation) {
            $blocking[] = new PerformanceFinding($endPartial ? PerformanceReason::END_VALUATION_PARTIAL : PerformanceReason::NO_END_VALUATION, $endMissing);
        }

        $views = $this->presenter->present($workspace, array_values(array_filter([$startValuation, $endValuation])));
        $start = $startOpening ?? (null === $startValuation ? null : self::anchor($views[0]->valuation, $views[0]->flags));
        $end = null === $endValuation ? null : self::anchor($views[null === $startValuation ? 0 : 1]->valuation, $views[null === $startValuation ? 0 : 1]->flags);

        if (null !== $start && null !== $end && $end->date <= $start->date) {
            $blocking[] = new PerformanceFinding(PerformanceReason::NO_END_VALUATION, $endMissing);
        }
        $warnings = [];
        if (null !== $end && null !== $period->lastDay && $end->date < $period->lastDay) {
            $warnings[] = new PerformanceFinding(PerformanceReason::PERIOD_IN_PROGRESS, [$end->date->format('Y-m-d')]);
        }

        $operations = [];
        if ([] === $blocking && null !== $start && null !== $end) {
            $history = array_values(array_filter($history, static fn (PortfolioOperation $operation): bool => $operation->details->tradeDate <= $end->date));
            $operations = array_values(array_filter($history, static fn (PortfolioOperation $operation): bool => $operation->details->tradeDate > $start->date));
        }

        return new PerformancePeriodData($start, $end, $blocking, $warnings, $operations, $history, $precision);
    }

    /**
     * The latest active COMPLETE valuation dated within [from, to] and whether the window only holds partial ones.
     * A null $from leaves the window open at the start.
     *
     * @return array{?Valuation, bool}
     */
    private function latest(WorkspaceScope $workspace, string $accountId, ?\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $before = $to->modify('+1 day');
        $complete = $this->valuations->latestActiveBefore($workspace, $accountId, $before, true, ValuationCompleteness::COMPLETE);
        if (null !== $complete && (null === $from || $complete->valuedOn >= $from)) {
            return [$complete, false];
        }
        $partial = $this->valuations->latestActiveBefore($workspace, $accountId, $before, false, ValuationCompleteness::PARTIAL);

        return [null, null !== $partial && (null === $from || $partial->valuedOn >= $from)];
    }

    /**
     * @param list<PortfolioOperation> $history
     */
    private function isEmptyBefore(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $day, array $history): bool
    {
        foreach ($history as $operation) {
            if ($operation->details->tradeDate < $day) {
                return false;
            }
        }

        return null === $this->valuations->latestActiveBefore($workspace, $accountId, $day);
    }

    /** @param list<ValuationFlag> $flags */
    private static function anchor(Valuation $valuation, array $flags): PeriodAnchor
    {
        return PeriodAnchor::valuation(
            $valuation->id,
            $valuation->valuedOn,
            $valuation->totalValue ?? throw new \LogicException('A complete valuation has a total.'),
            $valuation->securitiesValue ?? throw new \LogicException('A complete valuation has a securities value.'),
            $valuation->detail->value,
            array_map(static fn ($flag): string => $flag->value, $flags),
            $valuation->positions,
        );
    }
}
