<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Domain\Valuation\ValuationDetail;

final readonly class ValuationDraftView
{
    /** @param list<DraftPosition> $positions */
    public function __construct(
        public \DateTimeImmutable $valuedOn,
        public string $assetCode,
        public DecimalValue $derivedCash,
        public ValuationDetail $suggestedDetail,
        public ?DecimalValue $lastTotal,
        public ?\DateTimeImmutable $lastTotalOn,
        public ?string $currentValuationId,
        public ?int $currentValuationVersion,
        public array $positions,
    ) {
    }
}
