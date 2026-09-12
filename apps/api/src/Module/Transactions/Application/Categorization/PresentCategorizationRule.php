<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Transactions\Domain\Categorization\CategorizationRule;

final readonly class PresentCategorizationRule
{
    public function __construct(private CategoryRepository $categories)
    {
    }

    /** @return array<string, mixed> */
    public function one(CategorizationRule $rule): array
    {
        $labels = $this->categories->labelsByIds($rule->workspace, [$rule->targetCategoryId]);

        return CategorizationRuleView::from($rule, $labels[$rule->targetCategoryId] ?? '');
    }

    /**
     * @param list<CategorizationRule> $rules
     *
     * @return list<array<string, mixed>>
     */
    public function many(array $rules): array
    {
        if ([] === $rules) {
            return [];
        }
        $labels = $this->categories->labelsByIds($rules[0]->workspace, array_values(array_unique(array_map(static fn (CategorizationRule $rule): string => $rule->targetCategoryId, $rules))));

        return array_map(static fn (CategorizationRule $rule): array => CategorizationRuleView::from($rule, $labels[$rule->targetCategoryId] ?? ''), $rules);
    }
}
