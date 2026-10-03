<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\WorkspaceScope;

/** Read-only facts about several transfer legs at once; identifiers that are no leg of the workspace are skipped. */
final readonly class ReadTransferLegs
{
    public function __construct(private TransferLegReader $legs)
    {
    }

    /**
     * @param list<string> $transactionIds
     *
     * @return list<TransferLegFact>
     */
    public function __invoke(WorkspaceScope $workspace, array $transactionIds): array
    {
        return [] === $transactionIds ? [] : $this->legs->findMany($workspace, $transactionIds);
    }
}
