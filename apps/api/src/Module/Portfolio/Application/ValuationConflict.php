<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

final class ValuationConflict extends \RuntimeException
{
    public const string EXISTS = 'valuation_exists';
    public const string STALE_VERSION = 'stale_valuation_version';
    public const string LIMIT_REACHED = 'valuation_limit_reached';
    public const string CONCURRENT_CHANGE = 'portfolio_valuation_conflict';

    public function __construct(public readonly string $problemCode)
    {
        parent::__construct(sprintf('Portfolio valuation conflict: %s.', $problemCode));
    }
}
