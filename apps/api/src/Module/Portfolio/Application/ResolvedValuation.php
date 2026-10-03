<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Domain\Instrument;
use App\Module\Portfolio\Domain\Valuation\ValuationDetail;
use App\Module\Portfolio\Domain\Valuation\ValuationFigures;

final readonly class ResolvedValuation
{
    /** @param list<Instrument> $instruments the instruments held on the date, ordered by identifier */
    public function __construct(
        public \DateTimeImmutable $valuedOn,
        public ValuationDetail $detail,
        public ValuationFigures $figures,
        public DecimalValue $derivedCash,
        public ?DecimalValue $observedTotal,
        public ?string $note,
        public array $instruments,
        public int $displayPrecision,
    ) {
    }
}
