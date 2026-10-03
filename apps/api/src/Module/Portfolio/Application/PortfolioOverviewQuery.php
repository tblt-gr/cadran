<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;
use App\Module\Portfolio\Domain\Valuation\ValuationCompleteness;
use App\Module\Portfolio\Domain\Valuation\ValuationRepository;
use App\Module\Portfolio\Domain\Valuation\ValuationRules;

/** Shared authorized date selection for the overview and allocation. */
final readonly class PortfolioOverviewQuery
{
    public const int VALUATION_FRESHNESS_DAYS = 31;

    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private WorkspaceCalendar $calendar,
        private ValuationRepository $valuations,
        private PortfolioValuationPresenter $presenter,
        private PortfolioOperationRepository $operations,
    ) {
    }

    /** @return array{Account, \DateTimeImmutable, ?ValuationView, ?ValuationView} */
    public function __invoke(string $accountId, ?string $literal): array
    {
        $workspace = $this->caller->resolveContext()->workspace;
        $account = $this->gate->forRead($workspace, $accountId);
        $asOf = ValuationResolver::parseDay($literal ?? $this->calendar->today()->format('Y-m-d'), 'invalid_as_of');
        if ($asOf > $this->calendar->today()) {
            throw new InvalidPortfolioOperation('A future overview date is not available.', 'invalid_as_of');
        }
        if ($this->operations->countActive($workspace, $accountId) > PortfolioOperation::MAX_ACTIVE_PER_ACCOUNT) {
            throw new CashRangeTooDense('The portfolio history exceeds its read bound.');
        }
        if ($asOf < $account->openedOn) {
            return [$account, $asOf, null, null];
        }
        $dayAfter = $asOf->modify('+1 day');
        $latest = $this->valuations->latestActiveBefore($workspace, $accountId, $dayAfter);
        if (null === $latest || $latest->valuedOn < $account->openedOn) {
            return [$account, $asOf, null, null];
        }
        $selected = $latest;
        $newerPartial = null;
        if (ValuationCompleteness::PARTIAL === $latest->completeness) {
            $complete = $this->valuations->latestActiveBefore($workspace, $accountId, $dayAfter, completeness: ValuationCompleteness::COMPLETE);
            if (null !== $complete && $complete->valuedOn >= $account->openedOn) {
                $selected = $complete;
                $newerPartial = $latest;
            }
        }
        $views = $this->presenter->present($workspace, null === $newerPartial ? [$selected] : [$selected, $newerPartial]);

        return [$account, $asOf, $views[0], $views[1] ?? null];
    }

    /** @return array{id: string, valuedOn: string, totalValue: ?string, completeness: string, detail: string, ageDays: int, stale: bool}|null */
    public static function valuation(?ValuationView $view, \DateTimeImmutable $asOf): ?array
    {
        if (null === $view) {
            return null;
        }
        $valuation = $view->valuation;
        $age = ValuationRules::ageDays($valuation->valuedOn, $asOf);

        return ['id' => $valuation->id, 'valuedOn' => $valuation->valuedOn->format('Y-m-d'),
            'totalValue' => $valuation->totalValue?->toString(), 'completeness' => $valuation->completeness->value,
            'detail' => $valuation->detail->value, 'ageDays' => $age, 'stale' => $age > self::VALUATION_FRESHNESS_DAYS];
    }
}
