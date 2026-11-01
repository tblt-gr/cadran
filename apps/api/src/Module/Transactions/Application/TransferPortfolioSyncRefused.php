<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

/** The portfolio refused the operation a transfer implies: the whole transfer is refused with it. */
final class TransferPortfolioSyncRefused extends \DomainException
{
    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('The portfolio refused the operation matching this transfer: %s.', $reason), previous: $previous);
    }
}
