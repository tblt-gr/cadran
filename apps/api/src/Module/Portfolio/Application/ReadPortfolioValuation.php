<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Portfolio\Domain\Valuation\ValuationRepository;

/** A valuation by identifier, superseded ones included as read-only history. */
final readonly class ReadPortfolioValuation
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private ValuationRepository $valuations,
        private PortfolioValuationPresenter $presenter,
    ) {
    }

    public function __invoke(string $valuationId): ValuationView
    {
        $workspace = $this->caller->resolveContext()->workspace;
        $valuation = $this->valuations->find($workspace, $valuationId) ?? throw new ValuationNotFound();

        return $this->presenter->present($workspace, [$valuation])[0];
    }
}
