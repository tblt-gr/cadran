<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

final readonly class OperationPage
{
    /** @param list<OperationView> $items */
    public function __construct(public array $items, public int $page, public int $pageSize, public int $total)
    {
    }
}
