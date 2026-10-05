<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

final readonly class EuroFundRateInput
{
    public function __construct(
        public string $instrumentId,
        public int $year,
        public string $servedRate,
        public ?string $bonusRate,
        public string $basis,
        public ?string $sourceNote,
    ) {
    }
}
