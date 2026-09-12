<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\DecimalValue;

/**
 * The two signed leg amounts a transfer request resolves to, plus what each
 * leg carries as its original amount and the rate that relates them when the
 * two accounts do not share an asset.
 */
final readonly class TransferLegPlan
{
    public function __construct(
        public AssetAmount $sourceAmount,
        public AssetAmount $targetAmount,
        public ?AssetAmount $sourceOriginal,
        public ?AssetAmount $targetOriginal,
        public ?DecimalValue $exchangeRate,
        public bool $crossAsset,
    ) {
    }
}
