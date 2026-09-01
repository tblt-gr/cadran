<?php

declare(strict_types=1);

namespace App\Module\Reference\Application;

use App\Module\Reference\Domain\Asset;

final readonly class AssetPage
{
    /**
     * @param list<Asset> $assets
     */
    public function __construct(
        public array $assets,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }
}
