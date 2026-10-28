<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;

/**
 * How one group splits between its direct sub-groups, its own accounts and
 * each account of its subtree.
 *
 * @see GroupCompositionCalculator
 */
final readonly class GroupComposition
{
    /**
     * @param array<string, CompositionSlice> $subgroups sub-group id => slice, only sub-groups holding an eligible account
     * @param array<string, CompositionSlice> $accounts  account id => slice, in the order given
     */
    public function __construct(
        public ?DecimalValue $total,
        public ?AssetCode $asset,
        public ?NetWorthShareReason $totalReason,
        public ?GroupCompositionChartReason $chartReason,
        public array $subgroups,
        public ?CompositionSlice $direct,
        public array $accounts,
    ) {
    }
}
