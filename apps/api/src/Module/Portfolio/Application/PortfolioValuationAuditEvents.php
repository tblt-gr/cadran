<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

final class PortfolioValuationAuditEvents
{
    public const string ENTITY = 'portfolio_valuation';
    public const string RECORDED = 'portfolio_valuation.recorded';
    public const string SUPERSEDED = 'portfolio_valuation.superseded';
    public const string WITHDRAWN = 'portfolio_valuation.withdrawn';
}
