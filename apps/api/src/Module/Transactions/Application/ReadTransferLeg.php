<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\WorkspaceScope;

/** Read-only fact about one transfer leg, null for any other transaction or another workspace. */
final readonly class ReadTransferLeg
{
    public function __construct(private TransferLegReader $legs)
    {
    }

    public function __invoke(WorkspaceScope $workspace, string $transactionId): ?TransferLegFact
    {
        return $this->legs->find($workspace, $transactionId);
    }
}
