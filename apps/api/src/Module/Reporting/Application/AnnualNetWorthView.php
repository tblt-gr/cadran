<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class AnnualNetWorthView
{
    /**
     * @param list<AnnualNetWorthPointView> $months
     * @param ?AnnualNetWorthPointView      $opening the closing balance of December of the previous year, null when that month has no figures
     */
    public function __construct(public ?string $assetCode, public array $months, public ?AnnualNetWorthPointView $opening = null)
    {
    }
}
