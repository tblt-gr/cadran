<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

use App\Module\Transactions\Domain\Categorization\CategorizationRule;

final readonly class RuleResolution
{
    /** @param list<string> $matchingRuleIds every matching rule, winner first, in resolution order */
    public function __construct(public ?CategorizationRule $winner, public array $matchingRuleIds)
    {
    }
}
