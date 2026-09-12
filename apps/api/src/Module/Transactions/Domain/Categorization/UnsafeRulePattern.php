<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Categorization;

/** A regular expression refused by {@see RegexSafetyPolicy}; the reason is a stable code, never the pattern. */
final class UnsafeRulePattern extends InvalidCategorizationRule
{
    public function __construct(public readonly string $reason, public readonly string $field)
    {
        parent::__construct(sprintf('The %s pattern is refused: %s.', $field, $reason));
    }
}
