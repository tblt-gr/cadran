<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

use App\Module\Portfolio\Application\InstrumentView;

/** A euro fund held or rated on the contract and how fresh its announced rates are. */
final readonly class EuroFundState
{
    public function __construct(public InstrumentView $instrument, public ?int $latestRateYear, public bool $missingLastYear)
    {
    }
}
