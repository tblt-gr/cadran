<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

/** What a bulk move does to one account balance, from the earliest day it is affected. */
final readonly class BulkMoveImpact
{
    public function __construct(
        public string $accountId,
        public string $label,
        public string $assetCode,
        /** Exact signed decimal: the change of the account balance once every eligible row has moved. */
        public string $delta,
        public string $from,
        /** The latest affected day, only for a date change that leaves the balance unchanged at term. */
        public ?string $to,
    ) {
    }
}
