<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Takeover;

use App\Module\Foundation\Domain\DecimalValue;

final readonly class ResolvedTakeover
{
    /**
     * @param list<ResolvedTakeoverLine> $lines
     * @param list<TakeoverError>        $errors
     */
    public function __construct(
        public array $lines,
        public ?DecimalValue $cash,
        public array $errors,
        public int $displayPrecision,
    ) {
    }
}
