<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final readonly class ReplaceTransactionSplitsInput
{
    /** @param list<array{categoryId: string, amount: array{value: string, assetCode: string}, analyticAxes: ?list<string>, note: ?string}> $splits */
    public function __construct(
        public array $splits,
        public int $version,
    ) {
    }
}
