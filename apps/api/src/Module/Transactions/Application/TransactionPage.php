<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final readonly class TransactionPage
{
    /** @param list<TransactionView> $items */
    public function __construct(public array $items, public ?string $nextCursor)
    {
    }
}
