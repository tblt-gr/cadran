<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;
use App\Module\Transactions\Application\PortfolioLinkedTransactions;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PortfolioLinkedTransactions::class)]
final readonly class ReadPortfolioLinkedTransactions implements PortfolioLinkedTransactions
{
    public function __construct(private PortfolioOperationRepository $operations)
    {
    }

    public function among(WorkspaceScope $workspace, array $transactionIds): array
    {
        return $this->operations->linkedAmong($workspace, $transactionIds);
    }
}
