<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

use App\Module\Transactions\Domain\Categorization\CategorizationRule;

final readonly class CategorizationRuleView
{
    /** @return array<string, mixed> */
    public static function from(CategorizationRule $rule, string $categoryLabel): array
    {
        return [
            'id' => $rule->id, 'label' => $rule->label, 'priority' => $rule->priority,
            'accountScope' => $rule->accountScope, 'conditions' => $rule->conditions->toDocument(),
            'targetCategoryId' => $rule->targetCategoryId, 'targetCategoryLabel' => $categoryLabel,
            'targetAxes' => array_map(static fn ($axis): string => $axis->value, $rule->targetAxes),
            'targetCounterparty' => $rule->targetCounterparty, 'effectiveFrom' => $rule->effectiveFrom->format('Y-m-d'),
            'effectiveTo' => $rule->effectiveTo?->format('Y-m-d'), 'active' => $rule->active,
            'deactivatedReason' => $rule->deactivatedReason?->value, 'appliedCount' => $rule->appliedCount,
            'version' => $rule->version, 'createdAt' => $rule->createdAt->format(DATE_ATOM),
            'updatedAt' => $rule->updatedAt->format(DATE_ATOM), 'archivedAt' => $rule->archivedAt?->format(DATE_ATOM),
        ];
    }
}
