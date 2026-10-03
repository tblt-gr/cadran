<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

final class PortfolioOperationConflict extends \RuntimeException
{
    public const string LIMIT_REACHED = 'operation_limit_reached';
    public const string CONCURRENT_CHANGE = 'portfolio_operation_conflict';

    public function __construct(public readonly string $problemCode)
    {
        parent::__construct(sprintf('Portfolio operation conflict: %s.', $problemCode));
    }
}
