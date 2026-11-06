<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Takeover;

final readonly class PortfolioTakeoverPreview
{
    /**
     * @param list<TakeoverPreviewLine> $lines
     * @param list<TakeoverError>       $errors
     */
    public function __construct(
        public array $lines,
        public ?string $securitiesValue,
        public string $cash,
        public ?string $totalValue,
        public array $errors,
    ) {
    }
}
