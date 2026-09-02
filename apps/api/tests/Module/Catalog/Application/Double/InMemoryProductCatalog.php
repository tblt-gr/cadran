<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\Application\Double;

use App\Module\Catalog\Application\ProductCatalog;
use App\Module\Catalog\Domain\CatalogEntry;
use App\Module\Catalog\Domain\ProductCode;

final class InMemoryProductCatalog implements ProductCatalog
{
    /** @var list<CatalogEntry> */
    private array $entries;

    /**
     * @param list<CatalogEntry> $entries
     */
    public function __construct(array $entries)
    {
        usort($entries, static fn (CatalogEntry $left, CatalogEntry $right): int => strcmp(
            $left->product->code->toString(),
            $right->product->code->toString(),
        ));

        $this->entries = $entries;
    }

    public function findByCode(ProductCode $code): ?CatalogEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->product->code->equals($code)) {
                return $entry;
            }
        }

        return null;
    }

    public function readPage(int $limit, int $offset): array
    {
        return array_slice($this->entries, $offset, $limit);
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
