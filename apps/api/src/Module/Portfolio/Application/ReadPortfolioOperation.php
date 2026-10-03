<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;

final readonly class ReadPortfolioOperation
{
    public function __construct(
        private CallerWorkspace $caller,
        private PortfolioOperationRepository $operations,
        private PortfolioOperationPresenter $presenter,
    ) {
    }

    public function __invoke(string $operationId): OperationView
    {
        $workspace = $this->caller->resolve();
        $operation = $this->operations->find($workspace, $operationId) ?? throw new PortfolioOperationNotFound();

        return $this->presenter->present($workspace, [$operation])[0];
    }
}
