<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\WorkspaceScope;

interface MonthlyTransferPairReader
{
    /**
     * Returns pairs for which either leg is in the inclusive period, ordered
     * by transfer identifier and bounded by the caller supplied limit.
     *
     * @return list<MonthlyTransferPairFact>
     */
    public function read(
        WorkspaceScope $workspace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $limit,
    ): array;
}
