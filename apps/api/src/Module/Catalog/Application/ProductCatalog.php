<?php

declare(strict_types=1);

namespace App\Module\Catalog\Application;

use App\Module\Catalog\Domain\CatalogEntry;
use App\Module\Catalog\Domain\ProductCode;

/**
 * Read access to the system product catalogue.
 *
 * Like the asset reference, the catalogue is global and read-only: a product
 * belongs to no workspace, no request creates or edits one, and it is written
 * only by a reviewed migration. There is therefore no workspace to filter on
 * here, and no write method to forget to authorise.
 */
interface ProductCatalog
{
    public function findByCode(ProductCode $code): ?CatalogEntry;

    /**
     * Returns at most $limit entries from $offset, in a stable order.
     *
     * @return list<CatalogEntry>
     */
    public function readPage(int $limit, int $offset): array;

    public function count(): int;
}
