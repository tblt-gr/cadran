<?php

declare(strict_types=1);

namespace App\Module\Catalog\Application;

use App\Module\Catalog\Domain\BusinessDay;
use App\Module\Catalog\Domain\EffectiveProduct;
use App\Module\Catalog\Domain\InvalidCatalogEntry;
use App\Module\Catalog\Domain\ProductCode;
use Symfony\Component\Clock\ClockInterface;

/**
 * Reads one catalogue product as it stands on a business date.
 */
final readonly class ReadProduct
{
    public function __construct(
        private ProductCatalog $catalog,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $code, ?string $asOf): EffectiveProduct
    {
        try {
            $productCode = ProductCode::fromString($code);
        } catch (InvalidCatalogEntry) {
            // A code that cannot exist and a code that does not exist answer
            // alike, so the endpoint keeps one contract for "no such product".
            throw new ProductNotFound('No product carries this code.');
        }

        $today = BusinessDay::fromDateTime($this->clock->now());

        if (null === $asOf) {
            $businessDay = $today;
        } else {
            try {
                $businessDay = BusinessDay::fromIsoDate($asOf);
            } catch (InvalidCatalogEntry $failure) {
                throw new InvalidProductQuery($failure->getMessage(), previous: $failure);
            }
        }

        $entry = $this->catalog->findByCode($productCode);
        if (null === $entry) {
            throw new ProductNotFound('No product carries this code.');
        }

        return $entry->effectiveOn($businessDay->date, $today->date);
    }
}
