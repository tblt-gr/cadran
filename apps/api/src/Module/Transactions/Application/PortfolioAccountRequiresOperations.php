<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

/**
 * An income, expense, refund or fee booked on an account valued by its portfolio
 * would count the same money twice: the owner records a portfolio operation instead.
 */
final class PortfolioAccountRequiresOperations extends \DomainException
{
    public function __construct(public readonly string $accountId)
    {
        parent::__construct('The account is followed in portfolio mode: record a portfolio operation instead.');
    }
}
