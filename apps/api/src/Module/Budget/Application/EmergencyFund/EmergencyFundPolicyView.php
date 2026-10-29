<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\EmergencyFund;

use App\Module\Budget\Domain\EmergencyFund\EmergencyFundPolicy;
use App\Module\Categories\Application\BudgetCategoryFact;

final readonly class EmergencyFundPolicyView
{
    /** @param list<array{id: string, label: string, archived: bool}> $essentialCategories */
    public function __construct(
        public string $essentialMode,
        public array $essentialCategories,
        public string $referenceMode,
        public ?int $trailingMonths,
        public string $targetMonths,
        public int $version,
        public string $targetMode = 'MONTHS',
        public ?string $targetAmount = null,
        public ?string $targetAssetCode = null,
        public ?string $targetRatio = null,
    ) {
    }

    /**
     * A listed category that has since been deleted names nothing and is left out.
     *
     * @param list<BudgetCategoryFact> $categories
     */
    public static function of(EmergencyFundPolicy $policy, array $categories): self
    {
        $byId = [];
        foreach ($categories as $category) {
            $byId[$category->id] = $category;
        }
        $listed = [];
        foreach ($policy->essentialCategoryIds as $id) {
            if (isset($byId[$id])) {
                $listed[] = ['id' => $id, 'label' => $byId[$id]->label, 'archived' => null !== $byId[$id]->archivedAt];
            }
        }

        return new self($policy->essentialMode->value, $listed, $policy->referenceMode->value, $policy->trailingMonths, $policy->targetMonths->toString(), $policy->version, $policy->targetMode->value, $policy->targetAmount?->toString(), $policy->targetAssetCode?->toString(), $policy->targetRatio?->toString());
    }
}
