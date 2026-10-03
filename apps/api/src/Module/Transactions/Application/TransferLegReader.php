<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\WorkspaceScope;

interface TransferLegReader
{
    public function find(WorkspaceScope $workspace, string $transactionId): ?TransferLegFact;

    /**
     * Newest first. Only booked, non-voided legs of nature TRANSFER, with a positive amount for IN and a negative one for OUT.
     *
     * @param 'IN'|'OUT' $direction
     *
     * @return list<TransferLegFact>
     */
    public function recent(WorkspaceScope $workspace, string $accountId, string $direction, int $limit): array;
}
