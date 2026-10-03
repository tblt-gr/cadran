<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\WorkspaceScope;

/** The most recent booked, non-voided transfer legs of an account, incoming or outgoing. */
final readonly class ListAccountTransferLegs
{
    public function __construct(private TransferLegReader $legs)
    {
    }

    /**
     * @param 'IN'|'OUT' $direction
     *
     * @return list<TransferLegFact>
     */
    public function __invoke(WorkspaceScope $workspace, string $accountId, string $direction, int $limit): array
    {
        return $this->legs->recent($workspace, $accountId, $direction, max(1, min($limit, 500)));
    }
}
