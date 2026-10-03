<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Portfolio\Domain\Instrument;
use App\Module\Portfolio\Domain\OperationCashEffect;
use App\Module\Portfolio\Domain\OperationDetails;

final readonly class ResolvedOperation
{
    /** @param list<Instrument> $instruments the instruments the operation touches, locked when resolved for a write */
    public function __construct(
        public OperationDetails $details,
        public OperationCashEffect $effect,
        public array $instruments,
    ) {
    }
}
