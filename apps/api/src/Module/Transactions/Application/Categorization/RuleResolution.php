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

    /**
     * Exact only for a resolution that evaluated every rule: the next matching rule then wins.
     *
     * @param list<string>                      $ruleIds        the rules to drop
     * @param array<string, CategorizationRule> $remainingRules the rules still in the run, by identifier
     */
    public function withoutRules(array $ruleIds, array $remainingRules): self
    {
        $matching = array_values(array_diff($this->matchingRuleIds, $ruleIds));
        if ($matching === $this->matchingRuleIds) {
            return $this;
        }
        if ([] === $matching) {
            return new self(null, []);
        }

        return new self($remainingRules[$matching[0]] ?? throw new \LogicException('A remaining matching rule is missing from the run.'), $matching);
    }
}
