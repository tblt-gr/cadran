<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class MonthlyAccountFacts
{
    /** @param list<MonthlyAccountFact> $accounts */
    public function __construct(public array $accounts, public string $reconciliationStatus)
    {
    }
}
