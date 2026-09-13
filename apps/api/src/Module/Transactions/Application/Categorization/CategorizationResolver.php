<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Transactions\Domain\Categorization\CategorizationRule;
use App\Module\Transactions\Domain\Categorization\CategorizationSubject;

/**
 * Deterministic resolution: rules in period and scope, ordered by priority,
 * creation time and identifier; the first match wins. A rule whose target is
 * unknown, archived or of the wrong type for the movement sign is a non-match,
 * so an archived category never receives a new automatic split.
 */
final readonly class CategorizationResolver
{
    /**
     * @param list<CategorizationRule> $rules
     * @param array<string, Category>  $targets categories by identifier
     */
    public function resolve(array $rules, array $targets, CategorizationSubject $subject, RuleMatchingRun $run, bool $firstMatchOnly): RuleResolution
    {
        if (!$subject->isEligible()) {
            return new RuleResolution(null, []);
        }

        $winner = null;
        $matching = [];
        foreach (self::ordered($rules) as $rule) {
            if (!$rule->appliesTo($subject) || !self::targetFits($targets[$rule->targetCategoryId] ?? null, $subject)
                || !$run->matches($rule, $subject)) {
                continue;
            }
            $winner ??= $rule;
            $matching[] = $rule->id;
            if ($firstMatchOnly) {
                break;
            }
        }

        return new RuleResolution($winner, $matching);
    }

    /**
     * @param list<CategorizationRule> $rules
     *
     * @return list<CategorizationRule>
     */
    public static function ordered(array $rules): array
    {
        usort($rules, static fn (CategorizationRule $a, CategorizationRule $b): int => [$a->priority, $a->createdAt, $a->id] <=> [$b->priority, $b->createdAt, $b->id]);

        return $rules;
    }

    public static function targetFits(?Category $category, CategorizationSubject $subject): bool
    {
        return null !== $category && null === $category->archivedAt
            && $category->type === ($subject->amount->value->isNegative() ? CategoryType::EXPENSE : CategoryType::INCOME);
    }
}
