<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/** An account valued through its portfolio takes no manual balance and no transaction-based reconciliation. */
final class PortfolioAccountValuedByPortfolio extends \DomainException
{
    public function __construct()
    {
        parent::__construct('The account is valued by its portfolio valuations.');
    }
}
