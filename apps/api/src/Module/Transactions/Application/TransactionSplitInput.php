<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Foundation\Domain\AssetAmount;

/**
 * One parsed row of a client-submitted split allocation, before category
 * resolution. A `null` {@see $analyticAxes} means "inherit the category's
 * default axes"; an explicit list, even empty, overrides them.
 */
final readonly class TransactionSplitInput
{
    /** @param ?list<AnalyticAxis> $analyticAxes */
    public function __construct(
        public string $categoryId,
        public AssetAmount $amount,
        public ?array $analyticAxes,
        public ?string $note,
    ) {
    }
}
