<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

final readonly class CategorizationRuleInput
{
    /**
     * @param list<string> $accountScope
     * @param array<mixed> $conditions
     * @param list<string> $targetAxes
     */
    public function __construct(
        public string $label,
        public int $priority,
        public array $accountScope,
        public array $conditions,
        public string $targetCategoryId,
        public array $targetAxes,
        public ?string $targetCounterparty,
        public string $effectiveFrom,
        public ?string $effectiveTo,
        public bool $active = true,
        public ?int $version = null,
    ) {
    }
}
