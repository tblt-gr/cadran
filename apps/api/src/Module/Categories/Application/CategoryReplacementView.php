<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

use App\Module\Categories\Domain\CategoryReplacement;

final readonly class CategoryReplacementView
{
    public function __construct(
        public string $kind,
        public string $targetId,
        public ?string $targetLabel,
        public ?string $effectiveFrom,
    ) {
    }

    public static function of(CategoryReplacement $replacement, ?string $targetLabel): self
    {
        return new self(
            kind: $replacement->kind->value,
            targetId: $replacement->targetCategoryId,
            targetLabel: $targetLabel,
            effectiveFrom: $replacement->effectiveFrom?->format('Y-m-d'),
        );
    }
}
