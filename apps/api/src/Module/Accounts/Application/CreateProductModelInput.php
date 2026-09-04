<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class CreateProductModelInput
{
    /**
     * @param list<string>         $capabilities
     * @param list<ModelRuleInput> $rules
     */
    public function __construct(
        public string $name,
        public string $family,
        public string $wrapperKind,
        public string $yieldKind,
        public ?string $defaultGroupCode,
        public string $valuationMode,
        public array $capabilities,
        public array $rules,
    ) {
    }
}
