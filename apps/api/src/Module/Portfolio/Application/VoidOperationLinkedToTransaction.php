<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;

/** Voids the active operation backed by a transfer leg, if any, and leaves the leg unlinked. */
final readonly class VoidOperationLinkedToTransaction
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioOperationRepository $operations,
        private VoidPortfolioOperation $void,
    ) {
    }

    public function __invoke(string $transactionId, ?string $reason): void
    {
        $workspace = $this->caller->resolveContext()->workspace;
        $operationId = $this->operations->activeOperationLinkedTo($workspace, $transactionId);
        $operation = null === $operationId ? null : $this->operations->find($workspace, $operationId);
        if (null !== $operation) {
            ($this->void)($operation->id, $operation->version, $reason);
        }
    }
}
