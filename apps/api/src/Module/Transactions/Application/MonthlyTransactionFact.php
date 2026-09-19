<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;

final readonly class MonthlyTransactionFact
{
    /** @param list<MonthlyTransactionSplitFact> $splits */
    public function __construct(
        public string $accountId,
        public DecimalValue $amount,
        public AssetCode $asset,
        public string $nature,
        public array $splits,
    ) {
    }
}
