<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

/** The filters now match another number of transactions than the user confirmed. */
final class BulkScopeChanged extends \RuntimeException
{
    public function __construct(public readonly int $actualCount)
    {
        parent::__construct('The filtered set changed since it was counted.');
    }
}
