<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class AnnualFlowsView
{
    /** @param list<AnnualFlowMonthView> $months */
    public function __construct(public ?string $assetCode, public array $months)
    {
    }
}
