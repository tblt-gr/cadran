<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

final class CategorizationExecutionLimitExceeded extends \RuntimeException
{
    /** @param list<string> $trippedRuleIds */
    public function __construct(public readonly array $trippedRuleIds = [])
    {
        parent::__construct('The categorization execution bound was exceeded.');
    }
}
