<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

final class PortfolioOperationAuditEvents
{
    public const string ENTITY = 'portfolio_operation';
    public const string RECORDED = 'portfolio_operation.recorded';
    public const string VOIDED = 'portfolio_operation.voided';
    public const string REPLACED = 'portfolio_operation.replaced';
    public const string REORDERED = 'portfolio_operation.reordered';
}
