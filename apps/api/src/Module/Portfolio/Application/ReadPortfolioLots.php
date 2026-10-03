<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Portfolio\Domain\InstrumentRepository;

final readonly class ReadPortfolioLots
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private ReconcilePortfolioPositions $reconcile,
        private InstrumentRepository $instruments,
        private PresentPortfolioPositions $presenter,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(string $accountId, string $instrumentId, ?string $asOfLiteral): PortfolioLotsView
    {
        $context = $this->caller->resolveContext();
        $account = $this->gate->forRead($context->workspace, $accountId);
        $instrument = $this->instruments->find($context->workspace, $instrumentId) ?? throw new PortfolioInstrumentNotFound();
        $today = $this->calendar->today();
        $asOf = ReadPortfolioPositions::date($asOfLiteral ?? $today->format('Y-m-d'));
        if ($asOf > $today) {
            throw new InvalidPositionQuery('A future position date is not available.');
        }
        $result = ($this->reconcile)($context->workspace, $accountId, $asOf);

        return $this->presenter->lots(
            $result, $result->positions[$instrumentId] ?? null, $instrument, $account->assetCode->toString(), $asOf->format('Y-m-d'),
        );
    }
}
